<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\wpimport\blocktransformers;

use craft\elements\Entry;
use craft\wpimport\BaseBlockTransformer;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 */
class DsbDetails extends BaseBlockTransformer
{
    public static function blockName(): string
    {
        return 'dsb/details-summary-block';
    }

    public function render(array $data, Entry $entry): string
    {
        return $this->createNestedEntry($entry, function(Entry $nestedEntry) use ($data) {
            $nestedEntry->setTypeId($this->command->detailsEntryType->id);

            $summaryNodes = (new Crawler($data['innerHTML']))->filter('summary');
            if ($summaryNodes->count()) {
                $nestedEntry->setFieldValue($this->command->summaryField->handle, $summaryNodes->html());
            }

            // save it so we get an ID, before parsing the nested blocks
            $this->saveNestedEntry($nestedEntry);

            $nestedEntry->setFieldValue(
                'postContent',
                $this->command->renderBlocks($data['innerBlocks'], $nestedEntry),
            );
        });
    }
}
