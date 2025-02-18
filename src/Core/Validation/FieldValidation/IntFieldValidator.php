<?php

namespace SilverStripe\Core\Validation\FieldValidation;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\FieldType\DBInt;

/**
 * Validates that a value is a 32-bit signed integer
 */
class IntFieldValidator extends NumericNonStringFieldValidator
{
    public function __construct(
        string $name,
        mixed $value,
        ?int $minValue = null,
        ?int $maxValue = null
    ) {
        if (is_null($minValue)) {
            $minValue = (int) DBInt::getMinValue();
        }
        if (is_null($maxValue)) {
            $maxValue = (int) DBInt::getMaxValue();
        }
        parent::__construct($name, $value, $minValue, $maxValue);
    }

    protected function validateValue(): ValidationResult
    {
        $result = ValidationResult::create();
        if (!is_int($this->value)) {
            $message = _t(__CLASS__ . '.WRONGTYPE', 'Must be an integer');
            $result->addFieldError($this->name, $message);
        }
        if (!$result->isValid()) {
            return $result;
        }
        $result->combineAnd(parent::validateValue());
        return $result;
    }
}
