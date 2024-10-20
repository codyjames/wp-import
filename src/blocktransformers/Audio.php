<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\wpimport\blocktransformers;

use craft\elements\Entry;
use craft\wpimport\BaseBlockTransformer;
use craft\wpimport\importers\Media;
use Throwable;

/**
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 */
class Audio extends BaseBlockTransformer
{
    public static function blockName(): string
    {
        return 'core/audio';
    }

    public function render(array $data, Entry $entry): string
    {
        $id = $data['attrs']['id'] ?? null;
        if (!$id) {
            return '';
        }

        try {
            $assetId = $this->command->import(Media::resource(), $id);
        } catch (Throwable) {
            return '';
        }

        return $this->createNestedEntry($entry, function(Entry $nestedEntry) use ($assetId) {
            $nestedEntry->setTypeId($this->command->mediaEntryType->id);
            $nestedEntry->setFieldValue($this->command->mediaField->handle, [$assetId]);
        });
    }
}
