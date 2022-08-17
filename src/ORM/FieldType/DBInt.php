<?php

namespace SilverStripe\ORM\FieldType;

use SilverStripe\Forms\NumericField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DB;
use SilverStripe\View\ArrayData;

/**
 * Represents a signed 32 bit integer field.
 */
class DBInt extends DBField
{

    public function __construct(string $name = null, int $defaultVal = 0): void
    {
        $this->defaultVal = is_int($defaultVal) ? $defaultVal : 0;

        parent::__construct($name);
    }

    /**
     * Ensure int values are always returned.
     * This is for mis-configured databases that return strings.
     */
    public function getValue(): int
    {
        return (int) $this->value;
    }

    /**
     * Returns the number, with commas added as appropriate, eg “1,000”.
     */
    public function Formatted()
    {
        return number_format($this->value ?? 0.0);
    }

    public function requireField(): void
    {
        $parts = [
            'datatype' => 'int',
            'precision' => 11,
            'null' => 'not null',
            'default' => $this->defaultVal,
            'arrayValue' => $this->arrayValue
        ];
        $values = ['type' => 'int', 'parts' => $parts];
        DB::require_field($this->tableName, $this->name, $values);
    }

    public function Times()
    {
        $output = new ArrayList();
        for ($i = 0; $i < $this->value; $i++) {
            $output->push(ArrayData::create(['Number' => $i + 1]));
        }

        return $output;
    }

    public function Nice()
    {
        return sprintf('%d', $this->value);
    }

    public function scaffoldFormField($title = null, array $params = null): SilverStripe\Forms\NumericField
    {
        return NumericField::create($this->name, $title);
    }

    public function nullValue(): int
    {
        return 0;
    }

    public function prepValueForDB(int|string|bool|array $value): int
    {
        if ($value === true) {
            return 1;
        }

        if (empty($value) || !is_numeric($value)) {
            return 0;
        }

        return (int)$value;
    }
}
