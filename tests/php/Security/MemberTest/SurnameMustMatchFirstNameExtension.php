<?php

namespace SilverStripe\Security\Tests\MemberTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\Core\Extension;

/**
 * Extension that adds additional validation criteria
 */
class SurnameMustMatchFirstNameExtension extends Extension implements TestOnly
{
    protected function updatePHP($data, $form)
    {
        return $data['FirstName'] == $data['Surname'];
    }
}
