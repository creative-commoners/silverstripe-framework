<?php

namespace SilverStripe\Core\Validation\FieldValidation;

use RunTimeException;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\FieldType\DBBigInt;

/**
 * A field validator for 64-bit integers
 * Will throw a RunTimeException if used on a 32-bit system
 */
class BigIntFieldValidator extends IntFieldValidator
{
    public function __construct(
        string $name,
        mixed $value,
        ?int $minValue = null,
        ?int $maxValue = null
    ) {
        if (is_null($minValue) || is_null($maxValue)) {
            $bits = strlen(decbin(~0));
            if ($bits === 32) {
                throw new RunTimeException('Cannot use BigIntFieldValidator on a 32-bit system');
            }
            $minValue ??= (int) DBBigInt::getMinValue();
            $maxValue ??= (int) DBBigInt::getMaxValue();
        }
        $this->minValue = $minValue;
        $this->maxValue = $maxValue;
        parent::__construct($name, $value, $minValue, $maxValue);
    }

    protected function validateValue(): ValidationResult
    {
        $result = ValidationResult::create();
        // Validate string values that are too large or too small
        // Only testing for string values here as that's all bccomp can take as arguments
        // int values that are too large or too small will be cast to float
        // on 64-bit systems and will fail the validation in IntFieldValidator
        if (is_string($this->value)) {
            if (!is_null($this->minValue) && bccomp($this->value, DBBigInt::getMinValue()) === -1) {
                $result->addFieldError($this->name, $this->getTooSmallMessage());
            }
            if (!is_null($this->maxValue) && bccomp($this->value, DBBigInt::getMaxValue()) === 1) {
                $result->addFieldError($this->name, $this->getTooLargeMessage());
            }
        }
        $result->combineAnd(parent::validateValue());
        return $result;
    }
}
