<?php

namespace SilverStripe\ORM\Tests\Search;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HiddenField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\Filters\EndsWithFilter;
use SilverStripe\ORM\Filters\ExactMatchFilter;
use SilverStripe\ORM\Filters\PartialMatchFilter;
use SilverStripe\ORM\Search\SearchContext;
use SilverStripe\Model\ArrayData;

class SearchContextTest extends SapphireTest
{
    protected static $fixture_file = 'SearchContextTest.yml';

    protected static $extra_dataobjects = [
        SearchContextTest\Person::class,
        SearchContextTest\NoSearchableFields::class,
        SearchContextTest\Book::class,
        SearchContextTest\Company::class,
        SearchContextTest\Project::class,
        SearchContextTest\Deadline::class,
        SearchContextTest\Action::class,
        SearchContextTest\AllFilterTypes::class,
        SearchContextTest\WithinRangeFilterModel::class,
        SearchContextTest\Customer::class,
        SearchContextTest\Address::class,
        SearchContextTest\Order::class,
        SearchContextTest\GeneralSearch::class,
    ];

    public function testResultSetFilterReturnsExpectedCount()
    {
        $person = SearchContextTest\Person::singleton();
        $context = $person->getDefaultSearchContext();
        $results = $context->getResults(['Name' => '']);
        $this->assertEquals(5, $results->Count());

        $results = $context->getResults(['EyeColor' => 'green']);
        $this->assertEquals(2, $results->Count());

        $results = $context->getResults(['EyeColor' => 'green', 'HairColor' => 'black']);
        $this->assertEquals(1, $results->Count());
    }

    public function testSearchableFieldsDefaultsToSummaryFields()
    {
        $obj = new SearchContextTest\NoSearchableFields();
        $summaryFields = $obj->summaryFields();
        $expected = [];

        $expectedSearchableFields = [
            'Name',
            'Customer.FirstName',
            'HairColor',
            'EyeColor',
        ];

        foreach ($expectedSearchableFields as $field) {
            $expected[$field] = [
                'title' => $obj->fieldLabel($field),
                'filter' => 'PartialMatchFilter',
            ];
        }
        $this->assertEquals($expected, $obj->searchableFields());
        $this->assertNotEquals($summaryFields, $obj->searchableFields());
    }

    public function testSummaryIncludesDefaultFieldsIfNotDefined()
    {
        $person = SearchContextTest\Person::singleton();
        $this->assertContains('Name', $person->summaryFields());

        $book = SearchContextTest\Book::singleton();
        $this->assertContains('Title', $book->summaryFields());
    }

    public function testAccessDefinedSummaryFields()
    {
        $company = SearchContextTest\Company::singleton();
        $this->assertContains('Industry', $company->summaryFields());
    }

    public function testPartialMatchUsedByDefaultWhenNotExplicitlySet()
    {
        $person = SearchContextTest\Person::singleton();
        $context = $person->getDefaultSearchContext();

        $this->assertEquals(
            [
                "Name" => new PartialMatchFilter("Name"),
                "HairColor" => new PartialMatchFilter("HairColor"),
                "EyeColor" => new PartialMatchFilter("EyeColor")
            ],
            $context->getFilters()
        );
    }

    public function testDefaultFiltersDefinedWhenNotSetInDataObject()
    {
        $book = SearchContextTest\Book::singleton();
        $context = $book->getDefaultSearchContext();

        $this->assertEquals(
            [
                "Title" => new PartialMatchFilter("Title")
            ],
            $context->getFilters()
        );
    }

    public function testUserDefinedFiltersAppearInSearchContext()
    {
        $company = SearchContextTest\Company::singleton();
        $context = $company->getDefaultSearchContext();

        $this->assertEquals(
            [
                "Name" => new PartialMatchFilter("Name"),
                "Industry" => new PartialMatchFilter("Industry"),
                "AnnualProfit" => new PartialMatchFilter("AnnualProfit")
            ],
            $context->getFilters()
        );
    }

    public function testUserDefinedFieldsAppearInSearchContext()
    {
        $company = SearchContextTest\Company::singleton();
        $context = $company->getDefaultSearchContext();
        $this->assertEquals(
            new FieldList(
                new HiddenField($company->getGeneralSearchFieldName(), 'General Search'),
                (new TextField("Name", 'Name'))
                    ->setMaxLength(255),
                new TextareaField("Industry", 'Industry'),
                new NumericField("AnnualProfit", 'The Almighty Annual Profit')
            ),
            $context->getFields()
        );
    }

    public function testRelationshipObjectsLinkedInSearch()
    {
        $action3 = $this->objFromFixture(SearchContextTest\Action::class, 'action3');

        $project = SearchContextTest\Project::singleton();
        $context = $project->getDefaultSearchContext();

        $params = ["Name" => "Blog Website", "Actions__SolutionArea" => "technical"];

        /** @var DataList $results */
        $results = $context->getResults($params);

        $this->assertEquals(1, $results->count());

        /** @var SearchContextTest\Project $project */
        $project = $results->first();

        $this->assertInstanceOf(SearchContextTest\Project::class, $project);
        $this->assertEquals("Blog Website", $project->Name);
        $this->assertEquals(2, $project->Actions()->Count());

        $this->assertEquals(
            "Get RSS feeds working",
            $project->Actions()->find('ID', $action3->ID)->Description
        );
    }

    public static function provideQueryWithinRangeFilter(): array
    {
        return [
            // DBDate
            'date mid range' => [
                'params' => [
                    'DateOnly_SearchFrom' => '1990-01-01',
                    'DateOnly_SearchTo' => '2100-12-24',
                ],
                'expectedFixtureNames' => ['midrange'],
            ],
            'date from only' => [
                'params' => [
                    'DateOnly_SearchFrom' => '1990-01-01',
                ],
                'expectedFixtureNames' => ['midrange', 'highrange'],
            ],
            'date to only' => [
                'params' => [
                    'DateOnly_SearchTo' => '2100-12-24',
                ],
                'expectedFixtureNames' => ['lowrange', 'midrange'],
            ],
            // DBDatetime
            'datetime mid range' => [
                'params' => [
                    'Datetime_SearchFrom' => '1990-01-01 12:23:15',
                    'Datetime_SearchTo' => '2100-12-24 12:23:15',
                ],
                'expectedFixtureNames' => ['midrange'],
            ],
            'datetime from only' => [
                'params' => [
                    'Datetime_SearchFrom' => '1990-01-01 12:23:15',
                ],
                'expectedFixtureNames' => ['midrange', 'highrange'],
            ],
            'datetime to only' => [
                'params' => [
                    'Datetime_SearchTo' => '2100-12-24 12:23:15',
                ],
                'expectedFixtureNames' => ['lowrange', 'midrange'],
            ],
            'datetime mid range date only' => [
                'params' => [
                    'DatetimeWithDateField_SearchFrom' => '1990-01-01',
                    'DatetimeWithDateField_SearchTo' => '2100-12-24',
                ],
                'expectedFixtureNames' => ['midrange'],
            ],
            'datetime from only date only' => [
                'params' => [
                    'DatetimeWithDateField_SearchFrom' => '1990-01-01',
                ],
                'expectedFixtureNames' => ['midrange', 'highrange'],
            ],
            'datetime to only date only' => [
                'params' => [
                    'DatetimeWithDateField_SearchTo' => '2100-12-24',
                ],
                'expectedFixtureNames' => ['lowrange', 'midrange'],
            ],
            'datetime mid range date only exact day' => [
                'params' => [
                    // The from time should end up being 00:00:00
                    'DatetimeWithDateField_SearchFrom' => '2005-04-06',
                    // The to time should end up being 24:59:59
                    'DatetimeWithDateField_SearchTo' => '2005-04-06',
                ],
                'expectedFixtureNames' => ['midrange'],
            ],
            // DBTime
            'time mid range' => [
                'params' => [
                    'TimeOnly_SearchFrom' => '05:23:45',
                    'TimeOnly_SearchTo' => '17:01:23',
                ],
                'expectedFixtureNames' => ['midrange'],
            ],
            'time from only' => [
                'params' => [
                    'TimeOnly_SearchFrom' => '05:23:45',
                ],
                'expectedFixtureNames' => ['midrange', 'highrange'],
            ],
            'time to only' => [
                'params' => [
                    'TimeOnly_SearchTo' => '17:01:23',
                ],
                'expectedFixtureNames' => ['lowrange', 'midrange'],
            ],
            // DBInt
            'int mid range' => [
                'params' => [
                    'IntRange_SearchFrom' => '53',
                    'IntRange_SearchTo' => '623',
                ],
                'expectedFixtureNames' => ['midrange'],
            ],
            'int from only' => [
                'params' => [
                    'IntRange_SearchFrom' => '53',
                ],
                'expectedFixtureNames' => ['midrange', 'highrange'],
            ],
            'int to only' => [
                'params' => [
                    'IntRange_SearchTo' => '623',
                ],
                'expectedFixtureNames' => ['lowrange', 'midrange'],
            ],
            // DBDecimal
            'decimal mid range' => [
                'params' => [
                    'DecimalRange_SearchFrom' => '2.0',
                    'DecimalRange_SearchTo' => '63.125',
                ],
                'expectedFixtureNames' => ['midrange'],
            ],
            'decimal from only' => [
                'params' => [
                    'DecimalRange_SearchFrom' => '2.0',
                ],
                'expectedFixtureNames' => ['midrange', 'highrange'],
            ],
            'decimal to only' => [
                'params' => [
                    'DecimalRange_SearchTo' => '63.125',
                ],
                'expectedFixtureNames' => ['lowrange', 'midrange'],
            ],
            // DBFloat
            'float mid range' => [
                'params' => [
                    'FloatRange_SearchFrom' => '2.0',
                    'FloatRange_SearchTo' => '63.125',
                ],
                'expectedFixtureNames' => ['midrange'],
            ],
            'float from only' => [
                'params' => [
                    'FloatRange_SearchFrom' => '2.0',
                ],
                'expectedFixtureNames' => ['midrange', 'highrange'],
            ],
            'float to only' => [
                'params' => [
                    'FloatRange_SearchTo' => '63.125',
                ],
                'expectedFixtureNames' => ['lowrange', 'midrange'],
            ],
            // DBPercentage
            'percentage mid range' => [
                'params' => [
                    'PercentageRange_SearchFrom' => '0.12',
                    'PercentageRange_SearchTo' => '0.75',
                ],
                'expectedFixtureNames' => ['midrange'],
            ],
            'percentage from only' => [
                'params' => [
                    'PercentageRange_SearchFrom' => '0.12',
                ],
                'expectedFixtureNames' => ['midrange', 'highrange'],
            ],
            'percentage to only' => [
                'params' => [
                    'PercentageRange_SearchTo' => '0.75',
                ],
                'expectedFixtureNames' => ['lowrange', 'midrange'],
            ],
            // DBYear
            'year mid range' => [
                'params' => [
                    'YearRange_SearchFrom' => '1990',
                    'YearRange_SearchTo' => '2100',
                ],
                'expectedFixtureNames' => ['midrange'],
            ],
            'year from only' => [
                'params' => [
                    'YearRange_SearchFrom' => '1990',
                ],
                'expectedFixtureNames' => ['midrange', 'highrange'],
            ],
            'year to only' => [
                'params' => [
                    'YearRange_SearchTo' => '2100',
                ],
                'expectedFixtureNames' => ['lowrange', 'midrange'],
            ],
            // DBCurrency
            'currency mid range' => [
                'params' => [
                    'CurrencyRange_SearchFrom' => '2.0',
                    'CurrencyRange_SearchTo' => '63.125',
                ],
                'expectedFixtureNames' => ['midrange'],
            ],
            'currency from only' => [
                'params' => [
                    'CurrencyRange_SearchFrom' => '2.0',
                ],
                'expectedFixtureNames' => ['midrange', 'highrange'],
            ],
            'currency to only' => [
                'params' => [
                    'CurrencyRange_SearchTo' => '63.125',
                ],
                'expectedFixtureNames' => ['lowrange', 'midrange'],
            ],
            // Special match_any config
            'match_any mid range' => [
                'params' => [
                    'MatchAnyRange_SearchFrom' => '2.0',
                    'MatchAnyRange_SearchTo' => '63.125',
                ],
                'expectedFixtureNames' => ['midrange'],
            ],
            'match_any from only' => [
                'params' => [
                    'MatchAnyRange_SearchFrom' => '2.0',
                ],
                'expectedFixtureNames' => ['midrange', 'highrange'],
            ],
            'match_any to only' => [
                'params' => [
                    'MatchAnyRange_SearchTo' => '63.125',
                ],
                'expectedFixtureNames' => ['lowrange', 'midrange'],
            ],
            // DBVarchar
            'varchar mid range' => [
                'params' => [
                    'VarcharRangeWithConfig_SearchFrom' => 'e',
                    'VarcharRangeWithConfig_SearchTo' => 's',
                ],
                'expectedFixtureNames' => ['midrange'],
            ],
            'varchar from only' => [
                'params' => [
                    'VarcharRangeWithConfig_SearchFrom' => 'e',
                ],
                'expectedFixtureNames' => ['midrange', 'highrange'],
            ],
            'varchar to only' => [
                'params' => [
                    'VarcharRangeWithConfig_SearchTo' => 's',
                ],
                'expectedFixtureNames' => ['lowrange', 'midrange'],
            ],
        ];
    }

    #[DataProvider('provideQueryWithinRangeFilter')]
    public function testQueryWithinRangeFilter(array $params, array $expectedFixtureNames): void
    {
        $model = SearchContextTest\WithinRangeFilterModel::singleton();
        $context = $model->getDefaultSearchContext();
        $results = $context->getResults($params)->column('ID');

        $found = [];
        foreach ($expectedFixtureNames as $fixtureName) {
            $id = $this->idFromFixture(SearchContextTest\WithinRangeFilterModel::class, $fixtureName);
            if (in_array($id, $results)) {
                $found[] = $fixtureName;
            }
        }

        $this->assertSame($expectedFixtureNames, $found);
        $this->assertCount(count($expectedFixtureNames), $results, 'More results found than expected');
    }

    public function testCanGenerateQueryUsingAllFilterTypes()
    {
        $all = SearchContextTest\AllFilterTypes::singleton();
        $context = $all->getDefaultSearchContext();
        $params = [
            "ExactMatch" => "Match me exactly",
            "PartialMatch" => "partially",
            "CollectionMatch" => [
                "ExistingCollectionValue",
                "NonExistingCollectionValue",
                4,
                "Inline'Quotes'"
            ],
            "StartsWith" => "12345",
            "EndsWith" => "ijkl",
            "Fulltext" => "two"
        ];

        $results = $context->getResults($params);
        $this->assertEquals(1, $results->Count());
        $this->assertEquals("Filtered value", $results->First()->HiddenValue);
    }

    public function testStartsWithFilterCaseInsensitive()
    {
        $all = SearchContextTest\AllFilterTypes::singleton();
        $context = $all->getDefaultSearchContext();
        $params = [
            "StartsWith" => "12345-6789 camelcase", // spelled lowercase
        ];

        $results = $context->getResults($params);
        $this->assertEquals(1, $results->Count());
        $this->assertEquals("Filtered value", $results->First()->HiddenValue);
    }

    public function testEndsWithFilterCaseInsensitive()
    {
        $all = SearchContextTest\AllFilterTypes::singleton();
        $context = $all->getDefaultSearchContext();
        $params = [
            "EndsWith" => "IJKL", // spelled uppercase
        ];

        $results = $context->getResults($params);
        $this->assertEquals(1, $results->Count());
        $this->assertEquals("Filtered value", $results->First()->HiddenValue);
    }

    public function testSearchContextSummary()
    {
        $filters = [
            'KeywordSearch' => PartialMatchFilter::create('KeywordSearch'),
            'Country' => PartialMatchFilter::create('Country'),
            'CategoryID' => PartialMatchFilter::create('CategoryID'),
            'Featured' => PartialMatchFilter::create('Featured'),
            'Nothing' => PartialMatchFilter::create('Nothing'),
        ];

        $fields = FieldList::create(
            TextField::create('KeywordSearch', 'Keywords'),
            TextField::create('Country', 'Country'),
            DropdownField::create('CategoryID', 'Category', [
                1 => 'Category one',
                2 => 'Category two',
            ]),
            CheckboxField::create('Featured', 'Featured')
        );

        $context = SearchContext::create(
            SearchContextTest\Person::class,
            $fields,
            $filters
        );

        $context->setSearchParams([
            'KeywordSearch' => 'tester',
            'Country' => null,
            'CategoryID' => 2,
            'Featured' => 1,
            'Nothing' => 'empty',
        ]);

        $list = $context->getSummary();

        $this->assertEquals(3, $list->count());
        // KeywordSearch should be in the summary
        $keyword = $list->find('Field', 'Keywords');
        $this->assertNotNull($keyword);
        $this->assertEquals('tester', $keyword->Value);

        // Country should be skipped over
        $country = $list->find('Field', 'Country');
        $this->assertNull($country);

        // Category should be expressed as the label
        $category = $list->find('Field', 'Category');
        $this->assertNotNull($category);
        $this->assertEquals('Category two', $category->Value);

        // Featured should have no value, since it's binary
        $featured = $list->find('Field', 'Featured');
        $this->assertNotNull($featured);
        $this->assertNull($featured->Value);

        // "Nothing" should come back null since there's no field for it
        $nothing = $list->find('Field', 'Nothing');
        $this->assertNull($nothing);
    }

    public function testGeneralSearch()
    {
        $general1 = $this->objFromFixture(SearchContextTest\GeneralSearch::class, 'general1');
        $context = $general1->getDefaultSearchContext();
        $generalField = $general1->getGeneralSearchFieldName();

        // Matches on a variety of fields
        $results = $context->getResults([$generalField => 'General']);
        $this->assertCount(2, $results);
        $this->assertNotContains('MatchNothing', $results->column('Name'));
        $results = $context->getResults([$generalField => 'brown']);
        $this->assertCount(1, $results);
        $this->assertEquals('General One', $results->first()->Name);

        // Uses its own filter (not field filters)
        $results = $context->getResults([$generalField => 'exact']);
        $this->assertCount(1, $results);
        $this->assertEquals('General One', $results->first()->Name);

        // Uses match_any fields
        $results = $context->getResults([$generalField => 'first']);
        $this->assertCount(1, $results);
        $this->assertEquals('General One', $results->first()->Name);
        // Even across a relation
        $results = $context->getResults([$generalField => 'arbitrary']);
        $this->assertCount(1, $results);
        $this->assertEquals('General One', $results->first()->Name);
    }

    public function testSpecificSearchFields()
    {
        $general1 = $this->objFromFixture(SearchContextTest\GeneralSearch::class, 'general1');
        $context = $general1->getDefaultSearchContext();
        $generalField = $general1->getGeneralSearchFieldName();
        $results = $context->getResults([$generalField => $general1->ExcludeThisField]);
        $this->assertNotEmpty($general1->ExcludeThisField);
        $this->assertCount(0, $results);
    }

    public function testGeneralOnlyUsesSearchableFields()
    {
        $general1 = $this->objFromFixture(SearchContextTest\GeneralSearch::class, 'general1');
        $context = $general1->getDefaultSearchContext();
        $generalField = $general1->getGeneralSearchFieldName();
        $results = $context->getResults([$generalField => $general1->DoNotUseThisField]);
        $this->assertNotEmpty($general1->DoNotUseThisField);
        $this->assertCount(0, $results);
    }

    public function testGeneralSearchSplitTerms()
    {
        $general1 = $this->objFromFixture(SearchContextTest\GeneralSearch::class, 'general1');
        $context = $general1->getDefaultSearchContext();
        $generalField = $general1->getGeneralSearchFieldName();

        // These terms don't exist in a single field in this order on any object, but they do exist in separate fields.
        $results = $context->getResults([$generalField => 'general blue']);
        $this->assertCount(1, $results);
        $this->assertEquals('General Zero', $results->first()->Name);

        // These terms exist in a single field, but not in this order.
        $results = $context->getResults([$generalField => 'matches partial']);
        $this->assertCount(1, $results);
        $this->assertEquals('General One', $results->first()->Name);
    }

    public function testGeneralSearchNoSplitTerms()
    {
        Config::modify()->set(SearchContextTest\GeneralSearch::class, 'general_search_split_terms', false);
        $general1 = $this->objFromFixture(SearchContextTest\GeneralSearch::class, 'general1');
        $context = $general1->getDefaultSearchContext();
        $generalField = $general1->getGeneralSearchFieldName();

        // These terms don't exist in a single field in this order on any object
        $results = $context->getResults([$generalField => 'general blue']);
        $this->assertCount(0, $results);

        // These terms exist in a single field, but not in this order.
        $results = $context->getResults([$generalField => 'matches partial']);
        $this->assertCount(0, $results);

        // These terms exist in a single field in this order.
        $results = $context->getResults([$generalField => 'partial matches']);
        $this->assertCount(1, $results);
        $this->assertEquals('General One', $results->first()->Name);
    }

    public function testGetGeneralSearchFilter()
    {
        $general1 = $this->objFromFixture(SearchContextTest\GeneralSearch::class, 'general1');
        $context = $general1->getDefaultSearchContext();
        $getSearchFilterReflection = new ReflectionMethod($context, 'getGeneralSearchFilter');
        $getSearchFilterReflection->setAccessible(true);

        // By default, uses the PartialMatchFilter.
        $this->assertSame(
            PartialMatchFilter::class,
            get_class($getSearchFilterReflection->invoke($context, $general1->ClassName, 'ExactMatchField'))
        );
        $this->assertSame(
            PartialMatchFilter::class,
            get_class($getSearchFilterReflection->invoke($context, $general1->ClassName, 'PartialMatchField'))
        );

        // Changing the config changes the filter.
        Config::modify()->set(SearchContextTest\GeneralSearch::class, 'general_search_field_filter', EndsWithFilter::class);
        $this->assertSame(
            EndsWithFilter::class,
            get_class($getSearchFilterReflection->invoke($context, $general1->ClassName, 'ExactMatchField'))
        );
        $this->assertSame(
            EndsWithFilter::class,
            get_class($getSearchFilterReflection->invoke($context, $general1->ClassName, 'PartialMatchField'))
        );

        // Removing the filter config defaults to use the field's filter.
        Config::modify()->set(SearchContextTest\GeneralSearch::class, 'general_search_field_filter', '');
        $this->assertSame(
            ExactMatchFilter::class,
            get_class($getSearchFilterReflection->invoke($context, $general1->ClassName, 'ExactMatchField'))
        );
        $this->assertSame(
            PartialMatchFilter::class,
            get_class($getSearchFilterReflection->invoke($context, $general1->ClassName, 'PartialMatchField'))
        );
    }

    public function testGeneralSearchFilterIsUsed()
    {
        Config::modify()->set(SearchContextTest\GeneralSearch::class, 'general_search_field_filter', '');
        $general1 = $this->objFromFixture(SearchContextTest\GeneralSearch::class, 'general1');
        $context = $general1->getDefaultSearchContext();
        $generalField = $general1->getGeneralSearchFieldName();

        // Respects ExactMatchFilter
        $results = $context->getResults([$generalField => 'exact']);
        $this->assertCount(0, $results);
        // No match when splitting terms
        $results = $context->getResults([$generalField => 'This requires an exact match']);
        $this->assertCount(0, $results);


        // When not splitting terms, the behaviour of `ExactMatchFilter` is slightly different.
        Config::modify()->set(SearchContextTest\GeneralSearch::class, 'general_search_split_terms', false);
        // Respects ExactMatchFilter
        $results = $context->getResults([$generalField => 'exact']);
        $this->assertCount(0, $results);
        $results = $context->getResults([$generalField => 'This requires an exact match']);
        $this->assertCount(1, $results);
        $this->assertEquals('General One', $results->first()->Name);
    }

    public function testGeneralSearchDisabled()
    {
        Config::modify()->set(SearchContextTest\GeneralSearch::class, 'general_search_field_name', '');
        $general1 = $this->objFromFixture(SearchContextTest\GeneralSearch::class, 'general1');
        $context = $general1->getDefaultSearchContext();
        $generalField = $general1->getGeneralSearchFieldName();
        $this->assertEmpty($generalField);

        // Defaults to returning all objects, because the field doesn't exist in the SearchContext
        $numObjs = SearchContextTest\GeneralSearch::get()->count();
        $results = $context->getResults([$generalField => 'General']);
        $this->assertCount($numObjs, $results);
        $results = $context->getResults([$generalField => 'brown']);
        $this->assertCount($numObjs, $results);

        // Searching on other fields still works as expected (e.g. first field, which is the UI default in this situation)
        $results = $context->getResults(['Name' => 'General']);
        $this->assertCount(2, $results);
        $this->assertNotContains('MatchNothing', $results->column('Name'));
    }

    public function testGeneralSearchCustomFieldName()
    {
        Config::modify()->set(SearchContextTest\GeneralSearch::class, 'general_search_field_name', 'some_arbitrary_field_name');
        $obj = new SearchContextTest\GeneralSearch();
        $this->assertSame('some_arbitrary_field_name', $obj->getGeneralSearchFieldName());
        $this->testGeneralSearch();
    }

    public function testGeneralSearchFieldNameMustBeUnique()
    {
        Config::modify()->set(SearchContextTest\GeneralSearch::class, 'general_search_field_name', 'MatchAny');
        $general1 = $this->objFromFixture(SearchContextTest\GeneralSearch::class, 'general1');
        $this->expectException(LogicException::class);
        $general1->getDefaultSearchContext();
    }

    public function testMatchAnySearch()
    {
        $order1 = $this->objFromFixture(SearchContextTest\Order::class, 'order1');
        $context = $order1->getDefaultSearchContext();

        // Search should match Order's customer FirstName
        $results = $context->getResults(['CustomFirstName' => 'Bill']);
        $this->assertCount(2, $results);
        $this->assertListContains([
            ['Name' => 'Jane'],
            ['Name' => 'Jack'],
        ], $results);

        // Search should match Order's shipping address FirstName
        $results = $context->getResults(['CustomFirstName' => 'Bob']);
        $this->assertCount(2, $results);
        $this->assertListContains([
            ['Name' => 'Jane'],
            ['Name' => 'Jill'],
        ], $results);

        // Search should match Order's Name db field
        $results = $context->getResults(['CustomFirstName' => 'Jane']);
        $this->assertCount(1, $results);
        $this->assertSame('Jane', $results->first()->Name);

        // Search should not match any Order
        $results = $context->getResults(['CustomFirstName' => 'NoMatches']);
        $this->assertCount(0, $results);
    }

    public function testMatchAnySearchWithFilters()
    {
        $order1 = $this->objFromFixture(SearchContextTest\Order::class, 'order1');
        $context = $order1->getDefaultSearchContext();

        $results = $context->getResults(['ExactMatchField' => 'Bil']);
        $this->assertCount(0, $results);
        $results = $context->getResults(['PartialMatchField' => 'Bil']);
        $this->assertCount(2, $results);

        $results = $context->getResults(['ExactMatchField' => 'ob']);
        $this->assertCount(0, $results);
        $results = $context->getResults(['PartialMatchField' => 'ob']);
        $this->assertCount(2, $results);

        $results = $context->getResults(['ExactMatchField' => 'an']);
        $this->assertCount(0, $results);
        $results = $context->getResults(['PartialMatchField' => 'an']);
        $this->assertCount(1, $results);
    }

    public function testGetSearchFieldsThrowsException()
    {
        $modelClass = ArrayData::class;
        $context = new SearchContext($modelClass);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Cannot dynamically determine search fields. Pass the fields to setFields()'
            . " or implement a scaffoldSearchFields() method on {$modelClass}"
        );

        $context->getSearchFields();
    }
}
