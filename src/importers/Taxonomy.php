<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\wpimport\importers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\TitleField;
use craft\fields\Categories;
use craft\helpers\StringHelper;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\Site;
use craft\wpimport\BaseConfigurableImporter;
use craft\wpimport\Command;
use craft\wpimport\generators\fields\Description;
use craft\wpimport\generators\fields\WpId;
use craft\wpimport\generators\fields\WpTitle;
use Throwable;
use yii\console\Exception;

/**
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 */
class Taxonomy extends BaseConfigurableImporter
{
    private CategoryGroup $categoryGroup;
    private Categories $field;

    public function __construct(private array $data, Command $command, array $config = [])
    {
        parent::__construct($command, $config);
    }

    public function slug(): string
    {
        return $this->data['slug'];
    }

    public function apiUri(): string
    {
        return sprintf('%s/%s', $this->data['rest_namespace'], $this->data['rest_base'] ?: $this->data['slug']);
    }

    public function label(): string
    {
        return $this->data['name'];
    }

    public function typeLabel(): string
    {
        return 'Taxonomy';
    }

    public function elementType(): string
    {
        return Category::class;
    }

    public function populate(ElementInterface $element, array $data): void
    {
        /** @var Category $element */
        $element->groupId = $this->categoryGroup()->id;

        if (!$this->categoryGroup()->maxLevels && $data['parent']) {
            $element->setParentId($this->command->import($this->slug(), $data['parent']));
        }

        $element->title = StringHelper::safeTruncate($data['name'], 255);
        $element->setFieldValue(WpTitle::get()->handle, $data['name']);
        $element->slug = $data['slug'];

        $fieldValues = [
            Description::get()->handle => $data['description'],
        ];

        if (!empty($data['acf'])) {
            $fieldValues = array_merge($fieldValues, $this->command->prepareAcfFieldValues(
                $this->command->fieldsForEntity('taxonomy', $this->slug()),
                $data['acf'],
            ));
        }

        foreach ($fieldValues as $handle => $value) {
            try {
                $element->setFieldValue($handle, $value);
            } catch (Throwable) {
            }
        }
    }

    public function categoryGroup(): CategoryGroup
    {
        if (isset($this->categoryGroup)) {
            return $this->categoryGroup;
        }

        $groupHandle = StringHelper::toHandle($this->label());
        $group = Craft::$app->categories->getGroupByHandle($groupHandle);
        $newGroup = !$group;

        if ($newGroup) {
            $group = new CategoryGroup();
            $group->name = $this->label();
            $group->handle = $groupHandle;
            if (!$this->hierarchical()) {
                $group->maxLevels = 1;
            }
        }

        $fieldLayout = $group->getFieldLayout();
        $this->command->addElementsToLayout($fieldLayout, 'Content', [
            new TitleField(),
            new CustomField(Description::get()),
        ], true, true);
        $this->command->addAcfFieldsToLayout('taxonomy', $this->slug(), $fieldLayout);
        $this->command->addElementsToLayout($fieldLayout, 'Meta', [
            new CustomField(WpId::get()),
            new CustomField(WpTitle::get()),
        ]);

        $group->setSiteSettings(array_map(fn(Site $site) => new CategoryGroup_SiteSettings([
            'siteId' => $site->id,
        ]), Craft::$app->sites->getAllSites(true)));

        $message = sprintf('%s the `%s` category group', $newGroup ? 'Creating' : 'Updating', $group->name);
        $this->command->do($message, function() use ($group) {
            if (!Craft::$app->categories->saveGroup($group)) {
                throw new Exception(implode(', ', $group->getFirstErrors()));
            }
        });

        return $this->categoryGroup = $group;
    }

    public function field(): Categories
    {
        if (isset($this->field)) {
            return $this->field;
        }

        $fieldHandle = StringHelper::toHandle($this->label());
        /** @var Categories|null $field */
        $field = Craft::$app->fields->getFieldByHandle($fieldHandle);

        if ($field) {
            return $this->field = $field;
        }

        $field = new Categories();
        $field->name = $this->label();
        $field->handle = $fieldHandle;
        $field->source = sprintf('group:%s', $this->categoryGroup()->uid);

        $this->command->do("Creating `$field->name` Categories field", function() use ($field) {
            if (!Craft::$app->fields->saveField($field)) {
                throw new Exception(implode(', ', $field->getFirstErrors()));
            }
        });

        return $this->field = $field;
    }

    private function hierarchical(): bool
    {
        return $this->data['hierarchical'] ?? false;
    }
}
