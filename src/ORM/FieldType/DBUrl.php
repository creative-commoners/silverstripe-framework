<?php

namespace SilverStripe\ORM\FieldType;

use SilverStripe\ORM\FieldType\DBVarchar;
use SilverStripe\Core\Validation\FieldValidation\UrlFieldValidator;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\UrlField;

class DBUrl extends DBVarchar
{
    private static array $field_validators = [
        UrlFieldValidator::class,
    ];

    public function scaffoldFormField(?string $title = null, array $params = []): ?FormField
    {
        $field = UrlField::create($this->name, $title);
        $field->setMaxLength($this->getSize());
        return $field;
    }

    public function scaffoldSearchField(?string $title = null): ?FormField
    {
        return TextField::create($this->getName(), $title);
    }
}
