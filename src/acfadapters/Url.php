<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\wpimport\acfadapters;

use craft\base\FieldInterface;
use craft\fields\Link;
use craft\fields\linktypes\Url as UrlLinkType;
use craft\wpimport\BaseAcfAdapter;

/**
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 */
class Url extends BaseAcfAdapter
{
    public static function type(): string
    {
        return 'url';
    }

    public function create(array $data): FieldInterface
    {
        $field = new Link();
        $field->types = [UrlLinkType::id()];
        return $field;
    }
}
