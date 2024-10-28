<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\wpimport\errors;

use yii\console\Exception;

/**
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 */
class UnknownAcfFieldTypeException extends Exception
{
    public function __construct(
        public readonly string $fieldType,
        public readonly array $data,
    ) {
        parent::__construct($this->getName());
    }

    public function getName(): string
    {
        return "Unknown ACF field type: $this->fieldType";
    }
}
