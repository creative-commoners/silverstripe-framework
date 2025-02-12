<?php

namespace SilverStripe\Security;

use SilverStripe\ORM\DataObject;

/**
 * Readonly version of a {@link PermissionCheckboxSetField} -
 * uses the same structure, but has all checkboxes disabled.
 */
class PermissionCheckboxSetField_Readonly extends PermissionCheckboxSetField
{

    protected $readonly = true;

    public function saveInto(DataObject $record)
    {
        return false;
    }
}
