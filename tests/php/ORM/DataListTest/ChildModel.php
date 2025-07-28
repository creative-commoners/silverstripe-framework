<?php

namespace SilverStripe\ORM\Tests\DataListTest;

use SilverStripe\Dev\TestOnly;

class ChildModel extends ParentModel implements TestOnly
{
    private static string $table_name = 'DataListTest_ChildModel';

    private static array $db = [
        'ChildField' => 'Varchar',
    ];
}
