<?php

namespace SilverStripe\Dev\Tests;

use SilverStripe\Dev\Constraint\ModelDataContains;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\Tests\ModelDataContainsTest\TestObject;
use SilverStripe\Security\Member;
use SilverStripe\Model\ArrayData;

class ModelDataContainsTest extends SapphireTest
{
    private $test_data = [
        'FirstName' => 'Ingo',
        'Surname' => 'Schommer'
    ];

    public function provideMatchesForList()
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


    public function provideInvalidMatchesForList()
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
     * @dataProvider provideMatchesForList()
     *
     * @param $match
     */
    public function testEvaluateMatchesCorrectlyArrayData($match)
    {
        $constraint = new ModelDataContains($match);

        $item = ArrayData::create($this->test_data);

        $this->assertTrue($constraint->evaluate($item, '', true));
    }

    /**
     * @dataProvider provideMatchesForList()
     *
     * @param $match
     */
    public function testEvaluateMatchesCorrectlyDataObject($match)
    {
        $constraint = new ModelDataContains($match);

        $item = Member::create($this->test_data);

        $this->assertTrue($constraint->evaluate($item, '', true));
    }

    /**
     * @dataProvider provideInvalidMatchesForList()
     *
     * @param $matches
     */
    public function testEvaluateDoesNotMatchWrongMatchInArrayData($match)
    {
        $constraint = new ModelDataContains($match);

        $item = ArrayData::create($this->test_data);

        $this->assertFalse($constraint->evaluate($item, '', true));
    }

    /**
     * @dataProvider provideInvalidMatchesForList()
     *
     * @param $matches
     */
    public function testEvaluateDoesNotMatchWrongMatchInDataObject($match)
    {
        $constraint = new ModelDataContains($match);

        $item = Member::create($this->test_data);

        $this->assertFalse($constraint->evaluate($item, '', true));
    }

    public function testFieldAccess()
    {
        $data = new TestObject(['name' => 'Damian']);
        $constraint = new ModelDataContains(['name' => 'Damian', 'Something' => 'something']);
        $this->assertTrue($constraint->evaluate($data, '', true));

        $constraint = new ModelDataContains(['name' => 'Damian', 'Something' => 'notthing']);
        $this->assertFalse($constraint->evaluate($data, '', true));
    }
}
