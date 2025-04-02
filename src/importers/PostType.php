<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\wpimport\importers;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\elements\User as UserElement;
use craft\enums\CmsEdition;
use craft\enums\Color;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\DateTimeHelper;
use craft\helpers\Inflector;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\wpimport\BaseConfigurableImporter;
use craft\wpimport\Command;
use craft\wpimport\generators\fields\Caption;
use craft\wpimport\generators\fields\Color as ColorField;
use craft\wpimport\generators\fields\Comments;
use craft\wpimport\generators\fields\Description;
use craft\wpimport\generators\fields\Format;
use craft\wpimport\generators\fields\Media as MediaField;
use craft\wpimport\generators\fields\PostContent;
use craft\wpimport\generators\fields\Sticky;
use craft\wpimport\generators\fields\Tags;
use craft\wpimport\generators\fields\Template;
use craft\wpimport\generators\fields\WpId;
use craft\wpimport\generators\fields\WpTitle;
use Illuminate\Support\Collection;
use Throwable;
use yii\console\Exception;

/**
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 */
class PostType extends BaseConfigurableImporter
{
    private EntryType $entryType;
    private Section $section;

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
        return sprintf('%s/%s', $this->data['rest_namespace'], $this->data['rest_base'] ?: $this->data['name']);
    }

    public function label(): string
    {
        return Inflector::pluralize(StringHelper::titleize(str_replace('_', ' ', $this->data['labels']['name'])));
    }

    public function typeLabel(): string
    {
        return 'Post Type';
    }

    public function queryParams(): array
    {
        $params = [
            'status' => 'publish,future,draft,pending,private',
        ];
        if ($this->hierarchical()) {
            $params['orderby'] = 'menu_order';
            $params['order'] = 'asc';
        }
        return $params;
    }

    public function elementType(): string
    {
        return Entry::class;
    }

    public function populate(ElementInterface $element, array $data): void
    {
        /** @var Entry $element */
        $element->sectionId = $this->section()->id;
        $element->setTypeId($this->entryType()->id);

        if ($this->section()->type === Section::TYPE_STRUCTURE && $data['parent']) {
            $element->setParentId($this->command->import($this->slug(), $data['parent']));
        }

        if (Craft::$app->edition === CmsEdition::Solo) {
            $element->setAuthorId(UserElement::find()->admin()->limit(1)->ids()[0]);
        } elseif (!empty($data['author'])) {
            try {
                $element->setAuthorId($this->command->import(User::SLUG, $data['author'], [
                    'roles' => User::ALL_ROLES,
                ]));
            } catch (Throwable) {
            }
        }

        $title = $data['title']['raw'] ?? null;
        $element->title = $title !== null ? StringHelper::safeTruncate($title, 255) : null;
        $element->setFieldValue(WpTitle::get()->handle, $title);
        $element->slug = $data['slug'];
        $element->postDate = DateTimeHelper::toDateTime($data['date_gmt']);
        $element->dateUpdated = DateTimeHelper::toDateTime($data['modified_gmt']);
        $element->enabled = in_array($data['status'], ['publish', 'future']);

        $fieldValues = [
            Template::get()->handle => StringHelper::removeRight($data['template'] ?? '', '.php'),
        ];
        if ($this->supports('excerpt')) {
            $fieldValues['excerpt'] = $data['excerpt']['raw'] ?? null;
        }
        if ($this->supports('post-formats')) {
            $fieldValues[Format::get()->handle] = $data['format'] ?? null;
        }
        if (!$this->hierarchical()) {
            $fieldValues[Sticky::get()->handle] = $data['sticky'] ?? false;
        }
        if ($this->hasTaxonomy('post_tag')) {
            $fieldValues[Tags::get()->handle] = Collection::make($data['tags'])
                ->map(function (int $id) {
                    try {
                        return $this->command->import(Tag::SLUG, $id);
                    } catch (Throwable) {
                        return null;
                    }
                })
                ->filter()
                ->all();
        }
        if ($data['featured_media'] ?? null) {
            try {
                $fieldValues['featuredImage'] = [$this->command->import(Media::SLUG, $data['featured_media'])];
            } catch (Throwable) {
            }
        }
        if ($this->supports('comments') && $this->command->importComments) {
            $fieldValues[Comments::get()->handle] = [
                'commentEnabled' => ($data['comment_status'] ?? null) === 'open',
            ];
        }

        foreach ($this->data['taxonomies'] as $taxonomy) {
            if ($taxonomy === 'post_tag') {
                continue;
            }

            /** @var Taxonomy $importer */
            $importer = $this->command->importers[$taxonomy];
            $fieldValues[$importer->field()->handle] = Collection::make(match ($taxonomy) {
                'category' => $data['categories'],
                default => $data[$taxonomy],
            })
                ->map(function (int $id) use ($importer) {
                    try {
                        return $this->command->import($importer->slug(), $id);
                    } catch (Throwable) {
                        return null;
                    }
                })
                ->filter()
                ->all();
        }

        if (!empty($data['acf'])) {
            $fieldValues = array_merge($fieldValues, $this->command->prepareAcfFieldValues(
                $this->command->fieldsForEntity('post_type', $this->slug()),
                $data['acf'],
            ));
        }

        // Add Custom Event Start Date field
        if (!empty($data['meta']['em_start_date'])) {
            $fieldValues['eventStartDate'] = DateTimeHelper::toDateTime($data['meta']['em_start_date']);
        }

        foreach ($fieldValues as $handle => $value) {
            try {
                $element->setFieldValue($handle, $value);
            } catch (Throwable) {
            }
        }

        if (!empty($data['content_parsed'])) {
            // save the entry first, so it gets an ID
            if (!$element->id) {
                $element->setScenario(Element::SCENARIO_ESSENTIALS);
                if (!Craft::$app->elements->saveElement($element)) {
                    throw new Exception(implode(', ', $element->getFirstErrors()));
                }
            }
            // $element->setFieldValue(PostContent::get()->handle, $this->command->renderBlocks($data['content_parsed'], $element));
            $content = $this->command->renderBlocks($data['content_parsed'], $element);
            $content = $this->autop($content);
            $element->setFieldValue(PostContent::get()->handle, $content);
        }
    }

    public function entryType(): EntryType
    {
        if (isset($this->entryType)) {
            return $this->entryType;
        }

        $entryTypeHandle = StringHelper::toHandle($this->data['labels']['singular_name']);
        $entryType = Craft::$app->entries->getEntryTypeByHandle($entryTypeHandle);
        $newEntryType = !$entryType;

        if ($newEntryType) {
            $entryType = new EntryType();
            $entryType->name = $this->data['labels']['singular_name'];
            $entryType->handle = $entryTypeHandle;
            $entryType->icon = $this->command->normalizeIcon($this->data['icon'] ?? null) ?? 'pen-nib';
            $entryType->color = Color::Blue;
        }

        $contentElements = [];
        if ($this->supports('title')) {
            $contentElements[] = new EntryTitleField([
                'required' => false,
            ]);
        }
        $contentElements[] = new CustomField(PostContent::get());

        $metaElements = [];
        if ($this->supports('thumbnail')) {
            $metaElements[] = new CustomField(MediaField::get(), [
                'label' => 'Featured Image',
                'handle' => 'featuredImage',
            ]);
        }
        if ($this->supports('excerpt')) {
            $metaElements[] = new CustomField(Caption::get(), [
                'label' => 'Excerpt',
                'handle' => 'excerpt',
            ]);
        }
        if ($this->supports('comments') && $this->command->importComments) {
            $metaElements[] = new CustomField(Comments::get());
        }
        if ($this->supports('post-formats')) {
            $metaElements[] = new CustomField(Format::get());
        }
        if (!$this->hierarchical()) {
            $metaElements[] = new CustomField(Sticky::get());
        }

        foreach ($this->data['taxonomies'] as $taxonomy) {
            if ($taxonomy === 'post_tag') {
                continue;
            }

            /** @var Taxonomy $importer */
            $importer = $this->command->importers[$taxonomy];
            $metaElements[] = new CustomField($importer->field());
        }

        if ($this->hasTaxonomy('post_tag')) {
            $metaElements[] = new CustomField(Tags::get());
        }

        $metaElements[] = new CustomField(WpId::get());
        $metaElements[] = new CustomField(WpTitle::get());
        $metaElements[] = new CustomField(Template::get());

        $fieldLayout = $entryType->getFieldLayout();
        $this->command->addElementsToLayout($fieldLayout, 'Content', $contentElements, true, true);
        $this->command->addElementsToLayout($fieldLayout, 'Cover Photo', [
            new CustomField(Description::get(), [
                'label' => 'Cover Text',
                'handle' => 'coverText',
            ]),
            new CustomField(MediaField::get(), [
                'label' => 'Cover Photo',
                'handle' => 'coverPhoto',
            ]),
            new CustomField(ColorField::get(), [
                'label' => 'Cover Overlay Color',
                'handle' => 'coverOverlayColor',
            ]),
        ]);
        $this->command->addAcfFieldsToLayout('post_type', $this->slug(), $fieldLayout);
        $this->command->addElementsToLayout($fieldLayout, 'Meta', $metaElements);

        $message = sprintf('%s the `%s` entry type', $newEntryType ? 'Creating' : 'Updating', $entryType->name);
        $this->command->do($message, function () use ($entryType) {
            if (!Craft::$app->entries->saveEntryType($entryType)) {
                throw new Exception(implode(', ', $entryType->getFirstErrors()));
            }
        });

        return $this->entryType = $entryType;
    }

    public function section(): Section
    {
        if (isset($this->section)) {
            return $this->section;
        }

        $sectionHandle = StringHelper::toHandle($this->label());
        $section = Craft::$app->entries->getSectionByHandle($sectionHandle);
        if ($section) {
            return $this->section = $section;
        }

        $section = new Section();
        $section->name = $this->label();
        $section->handle = $sectionHandle;
        $section->type = $this->hierarchical() ? Section::TYPE_STRUCTURE : Section::TYPE_CHANNEL;
        $section->enableVersioning = $this->supports('revisions');
        $section->setEntryTypes([$this->entryType()]);
        $section->setSiteSettings([
            new Section_SiteSettings([
                'siteId' => Craft::$app->sites->getPrimarySite()->id,
                'hasUrls' => true,
                'uriFormat' => strtr(trim($this->command->wpInfo['permalink_structure'], '/'), [
                    '%year%' => "{postDate|date('Y')}",
                    '%monthnum%' => "{postDate|date('m')}",
                    '%day%' => "{postDate|date('d')}",
                    '%hour%' => "{postDate|date('H')}",
                    '%minute%' => "{postDate|date('i')}",
                    '%second%' => "{postDate|date('s')}",
                    '%post_id%' => '{id}',
                    '%postname%' => '{slug}',
                    '%category%' => "{categories.one().slug ?? 'uncategorized'}",
                    '%author%' => '{author.username}',
                ]),
                'template' => '_post.twig',
            ]),
        ]);
        $section->previewTargets = [
            [
                'label' => Craft::t('app', 'Primary {type} page', [
                    'type' => Entry::lowerDisplayName(),
                ]),
                'urlFormat' => '{url}',
            ],
        ];

        $this->command->do("Creating `$section->name` section", function () use ($section) {
            if (!Craft::$app->entries->saveSection($section)) {
                throw new Exception(implode(', ', $section->getFirstErrors()));
            }
        });

        return $this->section = $section;
    }

    private function hierarchical(): bool
    {
        return $this->data['hierarchical'] ?? false;
    }

    private function supports(string $feature): bool
    {
        return $this->data['supports'][$feature] ?? false;
    }

    private function hasTaxonomy(string $name): bool
    {
        return in_array($name, $this->data['taxonomies']);
    }

    private function get_html_split_regex()
    {
        static $regex;

        if (!isset($regex)) {
            // phpcs:disable Squiz.Strings.ConcatenationSpacing.PaddingFound -- don't remove regex indentation
            $comments =
                '!'             // Start of comment, after the <.
                . '(?:'         // Unroll the loop: Consume everything until --> is found.
                . '-(?!->)' // Dash not followed by end of comment.
                . '[^\-]*+' // Consume non-dashes.
                . ')*+'         // Loop possessively.
                . '(?:-->)?';   // End of comment. If not found, match all input.

            $cdata =
                '!\[CDATA\['    // Start of comment, after the <.
                . '[^\]]*+'     // Consume non-].
                . '(?:'         // Unroll the loop: Consume everything until ]]> is found.
                . '](?!]>)' // One ] not followed by end of comment.
                . '[^\]]*+' // Consume non-].
                . ')*+'         // Loop possessively.
                . '(?:]]>)?';   // End of comment. If not found, match all input.

            $escaped =
                '(?='             // Is the element escaped?
                . '!--'
                . '|'
                . '!\[CDATA\['
                . ')'
                . '(?(?=!-)'      // If yes, which type?
                . $comments
                . '|'
                . $cdata
                . ')';

            $regex =
                '/('                // Capture the entire match.
                . '<'           // Find start of element.
                . '(?'          // Conditional expression follows.
                . $escaped  // Find end of escaped element.
                . '|'           // ...else...
                . '[^>]*>?' // Find end of normal element.
                . ')'
                . ')/';
            // phpcs:enable
        }

        return $regex;
    }

    private function wp_replace_in_html_tags($haystack, $replace_pairs)
    {
        // Find all elements.
        $textarr = preg_split($this->get_html_split_regex(), $haystack, -1, PREG_SPLIT_DELIM_CAPTURE);
        $changed = false;

        // Optimize when searching for one item.
        if (1 === count($replace_pairs)) {
            // Extract $needle and $replace.
            $needle = array_key_first($replace_pairs);
            $replace = $replace_pairs[$needle];

            // Loop through delimiters (elements) only.
            for ($i = 1, $c = count($textarr); $i < $c; $i += 2) {
                if (str_contains($textarr[$i], $needle)) {
                    $textarr[$i] = str_replace($needle, $replace, $textarr[$i]);
                    $changed = true;
                }
            }
        } else {
            // Extract all $needles.
            $needles = array_keys($replace_pairs);

            // Loop through delimiters (elements) only.
            for ($i = 1, $c = count($textarr); $i < $c; $i += 2) {
                foreach ($needles as $needle) {
                    if (str_contains($textarr[$i], $needle)) {
                        $textarr[$i] = strtr($textarr[$i], $replace_pairs);
                        $changed = true;
                        // After one strtr() break out of the foreach loop and look at next element.
                        break;
                    }
                }
            }
        }

        if ($changed) {
            $haystack = implode($textarr);
        }

        return $haystack;
    }

    private function autop($text, $br = true)
    {
        $pre_tags = array();

        if (trim($text) === '') {
            return '';
        }

        // Just to make things a little easier, pad the end.
        $text = $text . "\n";

        /*
         * Pre tags shouldn't be touched by autop.
         * Replace pre tags with placeholders and bring them back after autop.
         */
        if (str_contains($text, '<pre')) {
            $text_parts = explode('</pre>', $text);
            $last_part = array_pop($text_parts);
            $text = '';
            $i = 0;

            foreach ($text_parts as $text_part) {
                $start = strpos($text_part, '<pre');

                // Malformed HTML?
                if (false === $start) {
                    $text .= $text_part;
                    continue;
                }

                $name = "<pre wp-pre-tag-$i></pre>";
                $pre_tags[$name] = substr($text_part, $start) . '</pre>';

                $text .= substr($text_part, 0, $start) . $name;
                ++$i;
            }

            $text .= $last_part;
        }
        // Change multiple <br>'s into two line breaks, which will turn into paragraphs.
        $text = preg_replace('|<br\s*/?>\s*<br\s*/?>|', "\n\n", $text);

        $allblocks = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary)';

        // Add a double line break above block-level opening tags.
        $text = preg_replace('!(<' . $allblocks . '[\s/>])!', "\n\n$1", $text);

        // Add a double line break below block-level closing tags.
        $text = preg_replace('!(</' . $allblocks . '>)!', "$1\n\n", $text);

        // Add a double line break after hr tags, which are self closing.
        $text = preg_replace('!(<hr\s*?/?>)!', "$1\n\n", $text);

        // Standardize newline characters to "\n".
        $text = str_replace(array("\r\n", "\r"), "\n", $text);

        // Find newlines in all elements and add placeholders.
        $text = $this->wp_replace_in_html_tags($text, array("\n" => ' <!-- wpnl --> '));

        // Collapse line breaks before and after <option> elements so they don't get autop'd.
        if (str_contains($text, '<option')) {
            $text = preg_replace('|\s*<option|', '<option', $text);
            $text = preg_replace('|</option>\s*|', '</option>', $text);
        }

        /*
         * Collapse line breaks inside <object> elements, before <param> and <embed> elements
         * so they don't get autop'd.
         */
        if (str_contains($text, '</object>')) {
            $text = preg_replace('|(<object[^>]*>)\s*|', '$1', $text);
            $text = preg_replace('|\s*</object>|', '</object>', $text);
            $text = preg_replace('%\s*(</?(?:param|embed)[^>]*>)\s*%', '$1', $text);
        }

        /*
         * Collapse line breaks inside <audio> and <video> elements,
         * before and after <source> and <track> elements.
         */
        if (str_contains($text, '<source') || str_contains($text, '<track')) {
            $text = preg_replace('%([<\[](?:audio|video)[^>\]]*[>\]])\s*%', '$1', $text);
            $text = preg_replace('%\s*([<\[]/(?:audio|video)[>\]])%', '$1', $text);
            $text = preg_replace('%\s*(<(?:source|track)[^>]*>)\s*%', '$1', $text);
        }

        // Collapse line breaks before and after <figcaption> elements.
        if (str_contains($text, '<figcaption')) {
            $text = preg_replace('|\s*(<figcaption[^>]*>)|', '$1', $text);
            $text = preg_replace('|</figcaption>\s*|', '</figcaption>', $text);
        }

        // Remove more than two contiguous line breaks.
        $text = preg_replace("/\n\n+/", "\n\n", $text);

        // Split up the contents into an array of strings, separated by double line breaks.
        $paragraphs = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Reset $text prior to rebuilding.
        $text = '';

        // Rebuild the content as a string, wrapping every bit with a <p>.
        foreach ($paragraphs as $paragraph) {
            $text .= '<p>' . trim($paragraph, "\n") . "</p>\n";
        }

        // Under certain strange conditions it could create a P of entirely whitespace.
        $text = preg_replace('|<p>\s*</p>|', '', $text);

        // Add a closing <p> inside <div>, <address>, or <form> tag if missing.
        $text = preg_replace('!<p>([^<]+)</(div|address|form)>!', '<p>$1</p></$2>', $text);

        // If an opening or closing block element tag is wrapped in a <p>, unwrap it.
        $text = preg_replace('!<p>\s*(</?' . $allblocks . '[^>]*>)\s*</p>!', '$1', $text);

        // In some cases <li> may get wrapped in <p>, fix them.
        $text = preg_replace('|<p>(<li.+?)</p>|', '$1', $text);

        // If a <blockquote> is wrapped with a <p>, move it inside the <blockquote>.
        $text = preg_replace('|<p><blockquote([^>]*)>|i', '<blockquote$1><p>', $text);
        $text = str_replace('</blockquote></p>', '</p></blockquote>', $text);

        // If an opening or closing block element tag is preceded by an opening <p> tag, remove it.
        $text = preg_replace('!<p>\s*(</?' . $allblocks . '[^>]*>)!', '$1', $text);

        // If an opening or closing block element tag is followed by a closing <p> tag, remove it.
        $text = preg_replace('!(</?' . $allblocks . '[^>]*>)\s*</p>!', '$1', $text);

        // Optionally insert line breaks.
        if ($br) {
            // Replace newlines that shouldn't be touched with a placeholder.
            $text = preg_replace_callback('/<(script|style|svg|math).*?<\/\\1>/s', [$this, 'autop_newline_preservation_helper'], $text);

            // Normalize <br>
            $text = str_replace(array('<br>', '<br/>'), '<br />', $text);

            // Replace any new line characters that aren't preceded by a <br /> with a <br />.
            $text = preg_replace('|(?<!<br />)\s*\n|', "<br />\n", $text);

            // Replace newline placeholders with newlines.
            $text = str_replace('<WPPreserveNewline />', "\n", $text);
        }

        // If a <br /> tag is after an opening or closing block tag, remove it.
        $text = preg_replace('!(</?' . $allblocks . '[^>]*>)\s*<br />!', '$1', $text);

        // If a <br /> tag is before a subset of opening or closing block tags, remove it.
        $text = preg_replace('!<br />(\s*</?(?:p|li|div|dl|dd|dt|th|pre|td|ul|ol)[^>]*>)!', '$1', $text);
        $text = preg_replace("|\n</p>$|", '</p>', $text);

        // Replace placeholder <pre> tags with their original content.
        if (!empty($pre_tags)) {
            $text = str_replace(array_keys($pre_tags), array_values($pre_tags), $text);
        }

        // Restore newlines in all elements.
        if (str_contains($text, '<!-- wpnl -->')) {
            $text = str_replace(array(' <!-- wpnl --> ', '<!-- wpnl -->'), "\n", $text);
        }

        return $text;
    }

    private function autop_newline_preservation_helper($matches) {
	    return str_replace( "\n", '<WPPreserveNewline />', $matches[0] );
    }
}
