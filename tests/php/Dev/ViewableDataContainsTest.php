<?php

namespace SilverStripe\Dev\Tests;

use SilverStripe\Dev\Constraint\ViewableDataContains;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\Tests\ViewableDataContainsTest\TestObject;
use SilverStripe\Security\Member;
use SilverStripe\View\ArrayData;
use PHPUnit\Framework\Attributes\DataProvider;

class ViewableDataContainsTest extends SapphireTest
{
    private $test_data = [
        'FirstName' => 'Ingo',
        'Surname' => 'Schommer'
    ];

    public static function provideMatchesForList()
    {
        return [
            [
                ['FirstName' => 'Ingo']
            ],
            [
                ['Surname' => 'Schommer']
            ],
            [
                ['FirstName' => 'Ingo', 'Surname' => 'Schommer']
            ]
        ];
    }


    public static function provideInvalidMatchesForList()
    {
        return [
            [
                ['FirstName' => 'AnyoneNotInList']
            ],
            [
                ['Surname' => 'NotInList']
            ],
            [
                ['FirstName' => 'Ingo', 'Surname' => 'Minnee']
            ]
        ];
    }

    /**
     * @param $match
     */
    #[DataProvider('provideMatchesForList')]
    public function testEvaluateMatchesCorrectlyArrayData($match)
    {
        $constraint = new ViewableDataContains($match);

        $item = ArrayData::create($this->test_data);

        $this->assertTrue($constraint->evaluate($item, '', true));
    }

    /**
     * @param $match
     */
    #[DataProvider('provideMatchesForList')]
    public function testEvaluateMatchesCorrectlyDataObject($match)
    {
        $constraint = new ViewableDataContains($match);

        $item = Member::create($this->test_data);

        $this->assertTrue($constraint->evaluate($item, '', true));
    }

    /**
     * @param $matches
     */
    #[DataProvider('provideInvalidMatchesForList')]
    public function testEvaluateDoesNotMatchWrongMatchInArrayData($match)
    {
        $constraint = new ViewableDataContains($match);

        $item = ArrayData::create($this->test_data);

        $this->assertFalse($constraint->evaluate($item, '', true));
    }

    /**
     * @param $matches
     */
    #[DataProvider('provideInvalidMatchesForList')]
    public function testEvaluateDoesNotMatchWrongMatchInDataObject($match)
    {
        $constraint = new ViewableDataContains($match);

        $item = Member::create($this->test_data);

        $this->assertFalse($constraint->evaluate($item, '', true));
    }

    public function testFieldAccess()
    {
        $data = new TestObject(['name' => 'Damian']);
        $constraint = new ViewableDataContains(['name' => 'Damian', 'Something' => 'something']);
        $this->assertTrue($constraint->evaluate($data, '', true));

        $constraint = new ViewableDataContains(['name' => 'Damian', 'Something' => 'notthing']);
        $this->assertFalse($constraint->evaluate($data, '', true));
    }
}
