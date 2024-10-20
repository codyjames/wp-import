<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\wpimport\blocktransformers;

use craft\elements\Entry;
use craft\wpimport\BaseBlockTransformer;

/**
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 */
class ListItem extends BaseBlockTransformer
{
    public static function blockName(): string
    {
        return 'core/list-item';
    }

    public function render(array $data, Entry $entry): string
    {
        // Perfect <li> tag. No notes.
        return $data['innerHTML'];
    }
}
