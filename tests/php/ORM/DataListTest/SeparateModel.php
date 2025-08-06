<?php

namespace SilverStripe\ORM\Tests\DataListTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class SeparateModel extends DataObject implements TestOnly
{
    private static string $table_name = 'DataListTest_SeparateModel';

    private static array $db = [
        'Title' => 'Varchar',
        'SeparateField' => 'Varchar',
    ];
}
