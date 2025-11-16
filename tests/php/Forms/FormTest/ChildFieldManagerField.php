<?php

namespace SilverStripe\Forms\Tests\FormTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\Forms\ChildFieldManager;
use SilverStripe\Forms\DatalessField;
use SilverStripe\Forms\FormField;

class ChildFieldManagerField extends DatalessField implements ChildFieldManager, TestOnly
{
    /**
     * @var array<FormField>
     */
    private array $fields;

    public function __construct(array $children)
    {
        $this->fields = $children;
        return parent::__construct('ChildFieldManager');
    }

    public function isManagedField(string $fieldName): bool
    {
        return (bool) $this->getManagedFieldByName($fieldName);
    }

    public function getManagedFieldByName(string $fieldName): ?FormField
    {
        foreach ($this->fields as $field) {
            if ($field->getName() === $fieldName) {
                return $field;
            }
        }
        return null;
    }

    public function getManagedFields(): iterable
    {
        return $this->fields;
    }
}
