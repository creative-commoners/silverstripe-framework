<?php

namespace SilverStripe\ORM\Tests;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\Tests\DataObjectTest\Team;
use SilverStripe\ORM\Tests\HasManyListTest\Company;
use SilverStripe\ORM\Tests\HasManyListTest\CompanyCar;
use SilverStripe\ORM\Tests\HasManyListTest\Employee;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataList;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\CliDebugView;

class HasManyListTest extends SapphireTest
{

    protected static $fixture_file = [
        'DataObjectTest.yml', // Borrow the model from DataObjectTest
        'HasManyListTest.yml',
    ];

    public static $extra_data_objects = [
        Company::class,
        Employee::class,
        CompanyCar::class,
    ];

    public static function getExtraDataObjects()
    {
        return array_merge(
            DataObjectTest::$extra_data_objects,
            ManyManyListTest::$extra_data_objects,
            static::$extra_data_objects
        );
    }

    public function testRelationshipEmptyOnNewRecords()
    {
        // Relies on the fact that (unrelated) comments exist in the fixture file already
        $newTeam = new Team(); // has_many Comments
        $this->assertEquals([], $newTeam->Comments()->column('ID'));
    }

    public function testSetUseCache(): void
    {
        $queryCounter = new DBQueryCounterDebugView();
        Injector::inst()->registerService($queryCounter, CliDebugView::class);

        $team1 = $this->objFromFixture(Team::class, 'team1');
        $commentsList1 = $team1->Comments()->setUseCache(true);
        $team2 = $this->objFromFixture(Team::class, 'team2');
        $commentsList2 = $team2->Comments()->setUseCache(true);

        // First query is uncached
        $queryCounter->startCounting();
        $comments1 = $commentsList1->toArray();
        $comments2 = $commentsList2->toArray();
        $queryCounter->stopCounting();
        $this->assertSame(2, $queryCounter->getCount());

        // Second query uses the cache
        $queryCounter->startCounting();
        $comments1Cached = $commentsList1->toArray();
        $comments2Cached = $commentsList2->toArray();
        $queryCounter->stopCounting();
        $this->assertSame(0, $queryCounter->getCount());

        // Check the lists are correct
        $this->assertSame($comments1, $comments1Cached);
        $this->assertSame($comments2, $comments2Cached);
        // Check there was no accidental bleed-through
        // Using NotEquals instead of NotSame here checks the data, not just the specific object instances.
        $this->assertNotEquals($comments1Cached, $comments2Cached);
    }

    /**
     * Test that related objects can be removed from a relation
     */
    public function testRemoveRelation()
    {
        // Check that expected teams exist
        $list = Team::get();
        $this->assertEquals(
            ['Subteam 1', 'Subteam 2', 'Subteam 3', 'Team 1', 'Team 2', 'Team 3'],
            $list->sort('Title')->column('Title')
        );

        // Test that each team has the correct comments
        $team1 = $this->objFromFixture(DataObjectTest\Team::class, 'team1');
        $team2 = $this->objFromFixture(DataObjectTest\Team::class, 'team2');
        $this->assertEquals(['Bob', 'Joe'], $team1->Comments()->sort('Name')->column('Name'));
        $this->assertEquals(['Phil'], $team2->Comments()->sort('Name')->column('Name'));

        // Test that removing comments from unrelated team has no effect
        $team1comment = $this->objFromFixture(DataObjectTest\TeamComment::class, 'comment1');
        $team2comment = $this->objFromFixture(DataObjectTest\TeamComment::class, 'comment3');
        $team1->Comments()->remove($team2comment);
        $team2->Comments()->remove($team1comment);
        $this->assertEquals(['Bob', 'Joe'], $team1->Comments()->sort('Name')->column('Name'));
        $this->assertEquals(['Phil'], $team2->Comments()->sort('Name')->column('Name'));
        $this->assertEquals($team1->ID, $team1comment->TeamID);
        $this->assertEquals($team2->ID, $team2comment->TeamID);

        // Test that removing items from the related team resets the has_one relations on the fan
        $team1comment = $this->objFromFixture(DataObjectTest\TeamComment::class, 'comment1');
        $team2comment = $this->objFromFixture(DataObjectTest\TeamComment::class, 'comment3');
        $team1->Comments()->remove($team1comment);
        $team2->Comments()->remove($team2comment);
        $this->assertEquals(['Bob'], $team1->Comments()->sort('Name')->column('Name'));
        $this->assertEquals([], $team2->Comments()->sort('Name')->column('Name'));
        $this->assertEmpty($team1comment->TeamID);
        $this->assertEmpty($team2comment->TeamID);
    }

    public function testRemoveRelationInvalidatesCache()
    {
        $queryCounter = new DBQueryCounterDebugView();
        Injector::inst()->registerService($queryCounter, CliDebugView::class);

        $control = DataObjectTest\Team::get()->setUseCache(true);
        $control->count();

        // Test that the team has the correct comments
        $team = $this->objFromFixture(DataObjectTest\Team::class, 'team1');
        $comments = $team->Comments()->setUseCache(true);
        $this->assertEquals(['Bob', 'Joe'], $comments->sort('Name')->column('Name'));

        // Test that removing items clears cache for the relation class
        $team1comment = $this->objFromFixture(DataObjectTest\TeamComment::class, 'comment1');
        $comments->remove($team1comment);
        $queryCounter->startCounting();
        $this->assertEquals(['Bob'], $comments->sort('Name')->column('Name'));
        $queryCounter->stopCounting();
        $this->assertSame(1, $queryCounter->getCount());

        // Make sure other class's caches aren't affected
        $queryCounter->startCounting();
        $control->count();
        $queryCounter->stopCounting();
        $this->assertSame(0, $queryCounter->getCount());
    }

    public function testDefaultSortIsUsedOnList()
    {
        /** @var Company $company */
        $company = $this->objFromFixture(Company::class, 'silverstripe');

        $this->assertListEquals([
            ['Make' => 'Ferrari'],
            ['Make' => 'Jaguar'],
            ['Make' => 'Lamborghini'],
        ], $company->CompanyCars());
    }

    public function testCanBeSortedDescending()
    {
        /** @var Company $company */
        $company = $this->objFromFixture(Company::class, 'silverstripe');

        $this->assertListEquals([
            ['Make' => 'Lamborghini'],
            ['Make' => 'Jaguar'],
            ['Make' => 'Ferrari'],
        ], $company->CompanyCars()->sort('"Make" DESC'));
    }

    public function testSortByModel()
    {
        /** @var Company $company */
        $company = $this->objFromFixture(Company::class, 'silverstripe');

        $this->assertListEquals([
            ['Model' => 'Countach'],
            ['Model' => 'E Type'],
            ['Model' => 'F40'],
        ], $company->CompanyCars()->sort('"Model" ASC'));
    }

    public function testAddInvalidatesCache(): void
    {
        $queryCounter = new DBQueryCounterDebugView();
        Injector::inst()->registerService($queryCounter, CliDebugView::class);
        $control = DataObjectTest\Team::get()->setUseCache(true);
        $control->count();
        $newCar = new CompanyCar(['Model' => 'Buggie']);
        $newCar->write();

        $company = $this->objFromFixture(Company::class, 'silverstripe');
        $carsList = $company->CompanyCars()->setUseCache(true);
        $origCount = $carsList->count();
        $carsList->add($newCar);

        // Make sure CompanyCar cache was invalidated
        $queryCounter->startCounting();
        $this->assertSame($origCount + 1, $carsList->count());
        $queryCounter->stopCounting();
        $this->assertSame(1, $queryCounter->getCount());

        // Make sure other class's caches aren't affected
        $queryCounter->startCounting();
        $control->count();
        $queryCounter->stopCounting();
        $this->assertSame(0, $queryCounter->getCount());
    }

    public function testAddManyInvalidatesCache(): void
    {
        $queryCounter = new DBQueryCounterDebugView();
        Injector::inst()->registerService($queryCounter, CliDebugView::class);
        $control = DataObjectTest\Team::get()->setUseCache(true);
        $control->count();
        $newCar1 = new CompanyCar(['Model' => 'Buggie']);
        $newCar1->write();
        $newCar2 = new CompanyCar(['Model' => 'Buggie']);
        $newCar2->write();

        $company = $this->objFromFixture(Company::class, 'silverstripe');
        $carsList = $company->CompanyCars()->setUseCache(true);
        $origCount = $carsList->count();
        $carsList->addMany([$newCar1, $newCar2]);

        // Make sure CompanyCar cache was invalidated
        $queryCounter->startCounting();
        $this->assertSame($origCount + 2, $carsList->count());
        $queryCounter->stopCounting();
        $this->assertSame(1, $queryCounter->getCount());

        // Make sure other class's caches aren't affected
        $queryCounter->startCounting();
        $control->count();
        $queryCounter->stopCounting();
        $this->assertSame(0, $queryCounter->getCount());
    }

    public function testSetByIDListInvalidatesCache(): void
    {
        $queryCounter = new DBQueryCounterDebugView();
        Injector::inst()->registerService($queryCounter, CliDebugView::class);
        $control = DataObjectTest\Team::get()->setUseCache(true);
        $control->count();
        $newCar1 = new CompanyCar(['Model' => 'Buggie']);
        $newCar1->write();
        $newCar2 = new CompanyCar(['Model' => 'Buggie']);
        $newCar2->write();

        $company = $this->objFromFixture(Company::class, 'silverstripe');
        $carsList = $company->CompanyCars()->setUseCache(true);
        $carsList->count();
        $carsList->setByIDList([$newCar1->ID, $newCar2->ID]);

        // Make sure CompanyCar cache was invalidated
        $queryCounter->startCounting();
        $this->assertSame(2, $carsList->count());
        $queryCounter->stopCounting();
        $this->assertSame(1, $queryCounter->getCount());

        // Make sure other class's caches aren't affected
        $queryCounter->startCounting();
        $control->count();
        $queryCounter->stopCounting();
        $this->assertSame(0, $queryCounter->getCount());
    }

    public function testCallbackOnSetById()
    {
        $addedIds = [];
        $removedIds = [];

        $base = $this->objFromFixture(Company::class, 'silverstripe');
        $relation = $base->Employees();
        $remove = $relation->First();
        $add = new Employee();
        $add->write();

        $relation->addCallbacks()->add(function ($list, $item) use (&$addedIds) {
            $addedIds[] = $item;
        });

        $relation->removeCallbacks()->add(function ($list, $ids) use (&$removedIds) {
            $removedIds = $ids;
        });

        $relation->setByIDList(array_merge(
            $base->Employees()->exclude('ID', $remove->ID)->column('ID'),
            [$add->ID]
        ));
        $this->assertEquals([$remove->ID], $removedIds);
    }

    public function testAddCallback()
    {
        $added = [];

        $base = $this->objFromFixture(Company::class, 'silverstripe');
        $relation = $base->Employees();
        $add = new Employee();
        $add->write();

        $relation->addCallbacks()->add(function ($list, $item) use (&$added) {
            $added[] = $item;
        });

        $relation->add($add);
        $this->assertEquals([$add], $added);
    }

    public function testRemoveCallbackOnRemove()
    {
        $removedIds = [];

        $base = $this->objFromFixture(Company::class, 'silverstripe');
        $relation = $base->Employees();
        $remove = $relation->First();

        $relation->removeCallbacks()->add(function ($list, $ids) use (&$removedIds) {
            $removedIds = $ids;
        });

        $relation->remove($remove);
        $this->assertEquals([$remove->ID], $removedIds);
    }

    public function testRemoveCallbackOnRemoveById()
    {
        $removedIds = [];

        $base = $this->objFromFixture(Company::class, 'silverstripe');
        $relation = $base->Employees();
        $remove = $relation->First();

        $relation->removeCallbacks()->add(function ($list, $ids) use (&$removedIds) {
            $removedIds = $ids;
        });

        $relation->removeByID($remove->ID);
        $this->assertEquals([$remove->ID], $removedIds);
    }

    #[DataProvider('provideForForeignIDPlaceholders')]
    public function testForForeignIDPlaceholders(bool $config, bool $useInt, bool $expected): void
    {
        Config::modify()->set(DataList::class, 'use_placeholders_for_integer_ids', $config);
        $team1 = $this->objFromFixture(Team::class, 'team1');
        $team2 = $this->objFromFixture(Team::class, 'team2');
        $comments1 = $team1->Comments();
        $comments2 = $team2->Comments();
        $ids = $useInt ? [$team1->ID, $team2->ID] : ['Lorem', 'Ipsum'];
        $newCommentsList = $comments1->forForeignID($ids);
        $sql = $newCommentsList->dataQuery()->sql();
        preg_match('#ID" IN \(([^\)]+)\)\)#', $sql, $matches);
        $usesPlaceholders = $matches[1] === '?, ?';
        $this->assertSame($expected, $usesPlaceholders);
        $expectedIDs = $useInt
            ? array_values(array_merge($comments1->column('ID'), $comments2->column('ID')))
            : [];
        $this->assertSame($expectedIDs, $newCommentsList->column('ID'));
    }

    public static function provideForForeignIDPlaceholders(): array
    {
        return [
            'config false' => [
                'config' => false,
                'useInt' => true,
                'expected' => false,
            ],
            'config false non-int' => [
                'config' => false,
                'useInt' => false,
                'expected' => true,
            ],
            'config true' => [
                'config' => true,
                'useInt' => true,
                'expected' => true,
            ],
            'config true non-int' => [
                'config' => true,
                'useInt' => false,
                'expected' => true,
            ],
        ];
    }
}
