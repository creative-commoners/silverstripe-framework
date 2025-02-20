<?php

namespace SilverStripe\Forms\Tests\GridField\GridFieldFilterHeaderTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

class ModelWithBadSearchableFields extends DataObject implements TestOnly
{
    private static $table_name = 'GridFieldFilterHeaderTest_ModelWithBadSearchableFields';

    private static $db = [
        'Name' => 'Varchar',
    ];

    private static $summary_fields = [
        'Name' => 'Name',
    ];

    // Explicitly empty
    private static $searchable_fields = [];

    public function searchableFields()
    {
        // Explicitly only include this custom field
        return [
            'WhatIsThis' => [
                'field' => TextField::class,
                'title' => $this->fieldLabel('WhatIsThis'),
            ],
        ];
    }
}
