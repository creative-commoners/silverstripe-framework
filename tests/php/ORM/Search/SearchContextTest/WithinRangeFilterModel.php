<?php

namespace SilverStripe\ORM\Tests\Search\SearchContextTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\Forms\DateField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDecimal;
use SilverStripe\ORM\Filters\WithinRangeFilter;

class WithinRangeFilterModel extends DataObject implements TestOnly
{
    private static $table_name = 'SearchContextTest_WithinRangeFilterModel';

    private static $db = [
        // All date, time, and numeric fields are supported automatically
        'DateOnly' => 'Date',
        'Datetime' => 'Datetime',
        'DatetimeWithDateField' => 'Datetime',
        'TimeOnly' => 'Time',
        'IntRange' => 'Int',
        'DecimalRange' => 'Decimal',
        'FloatRange' => 'Float',
        'PercentageRange' => 'Percentage',
        'YearRange' => 'Year',
        'CurrencyRange' => 'Currency',
        // Other field types require additional configuration but can work
        'VarcharRangeWithConfig' => 'Varchar',
    ];

    private static $searchable_fields = [
        'DateOnly' => WithinRangeFilter::class,
        'Datetime' => WithinRangeFilter::class,
        'DatetimeWithDateField' => [
            'filter' => WithinRangeFilter::class,
            'field' => DateField::class,
        ],
        'TimeOnly' => WithinRangeFilter::class,
        'IntRange' => WithinRangeFilter::class,
        'DecimalRange' => WithinRangeFilter::class,
        'FloatRange' => WithinRangeFilter::class,
        'PercentageRange' => WithinRangeFilter::class,
        'YearRange' => WithinRangeFilter::class,
        'CurrencyRange' => WithinRangeFilter::class,
        // Special "match_any" config can also work with this filter
        'MatchAnyRange' => [
            'filter' => WithinRangeFilter::class,
            'dataType' => DBDecimal::class,
            'match_any' => [
                'DecimalRange',
                'FloatRange',
                'CurrencyRange',
            ],
        ],
        // Note the addition of rangeFromDefault and rangeToDefault here
        'VarcharRangeWithConfig' => [
            'filter' => WithinRangeFilter::class,
            'rangeFromDefault' => 'a',
            'rangeToDefault' => 'z',
        ],
    ];
}
