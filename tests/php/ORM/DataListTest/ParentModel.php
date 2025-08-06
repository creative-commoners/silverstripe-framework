<?php

namespace SilverStripe\ORM\Tests\DataListTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class ParentModel extends DataObject implements TestOnly
{
    private static string $table_name = 'DataListTest_ParentModel';

    private static array $db = [
        'Title' => 'Varchar',
        'ParentField' => 'Varchar',
    ];
}
