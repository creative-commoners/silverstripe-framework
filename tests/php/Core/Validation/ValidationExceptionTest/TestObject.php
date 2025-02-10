<?php

namespace SilverStripe\Core\Tests\Validation\ValidationExceptionTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class TestObject extends DataObject implements TestOnly
{
    private static $table_name = 'ValidationExceptionTest_TestObject';

    private static $db = [
        'Email' => 'Email',
    ];
}
