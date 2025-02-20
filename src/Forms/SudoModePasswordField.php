<?php

namespace SilverStripe\Forms;

use SilverStripe\Forms\PasswordField;

class SudoModePasswordField extends PasswordField
{
    public const FIELD_NAME = 'SudoModePasswordField';
    
    protected $schemaComponent = 'SudoModePasswordField';

    public function __construct()
    {
        // Name must be "SudoModePasswordField" as there's logic elsewhere expecting this
        // $title and $value are set to null as the react component does not use these arguments
        parent::__construct(SudoModePasswordField::FIELD_NAME);
        // Set title to empty string to avoid rendering a label before the react component has loaded
        $this->setTitle('');
        $this->addExtraClass('SudoModePasswordField');
    }

    public function performReadonlyTransformation()
    {
        // Readonly transformation should not be applied to this field
        // as this field is intended to be used on a form that has been set to read only mode
        return $this;
    }
}
