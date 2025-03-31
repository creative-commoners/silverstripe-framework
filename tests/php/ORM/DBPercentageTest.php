<?php

namespace SilverStripe\ORM\Tests;

use SilverStripe\ORM\FieldType\DBPercentage;
use SilverStripe\Dev\SapphireTest;
use PHPUnit\Framework\Attributes\DataProvider;

class DBPercentageTest extends SapphireTest
{

    public function testNice()
    {
        /* Test the default Nice() output of Percentage */
        $cases = [
            '0.01' => '1.00%',
            '0.10' => '10.00%',
            '1' => '100.00%',
            '1.5' => '150.00%',
            '1.5000' => '150.00%',
            '1.05' => '105.00%',
            '1.0500' => '105.00%',
            '0.95' => '95.00%'
        ];

        foreach ($cases as $original => $expected) {
            $percentage = new DBPercentage('Probability');
            $percentage->setValue($original);
            $this->assertEquals($expected, $percentage->Nice());
        }
    }

    public function testCustomPrecision()
    {
        /* Set a precision that's different from the default with Nice() output */
        $cases = [
            '0.01' => '1%',
            '0.1' => '10%',
            '1' => '100%',
            '1.5' => '150%',
            '1.05' => '105%',
            '1.0500' => '105%'
        ];

        foreach ($cases as $original => $expected) {
            $percentage = new DBPercentage('Probability', 2);
            $percentage->setValue($original);
            $this->assertEquals($expected, $percentage->Nice());
        }
    }

    public static function provideSetGetValue(): array
    {
        return [
            'zero' => [
                'value' => 0,
                'expected' => 0,
            ],
            'zero-point-five' => [
                'value' => 0.5,
                'expected' => 0.5,
            ],
            'one' => [
                'value' => 1,
                'expected' => 1,
            ],
            'one-point-five' => [
                'value' => 1.5,
                'expected' => 1.5,
            ],
            'negative-zero-point-five' => [
                'value' => -0.5,
                'expected' => -0.5,
            ],
            'string-zero' => [
                'value' => '0',
                'expected' => '0',
            ],
            'string-zero-point-five' => [
                'value' => '0.5',
                'expected' => '0.5',
            ],
            'string-one' => [
                'value' => '1',
                'expected' => '1',
            ],
            'string-one-point-five' => [
                'value' => '1.5',
                'expected' => '1.5',
            ],
            'string-negative-zero-point-five' => [
                'value' => '-0.5',
                'expected' => '-0.5',
            ],
            'string-fish' => [
                'value' => 'fish',
                'expected' => 'fish',
            ],
            'empty-string' => [
                'value' => '',
                'expected' => '',
            ],
            'null' => [
                'value' => null,
                'expected' => null,
            ],
            'true' => [
                'value' => true,
                'expected' => true,
            ],
            'false' => [
                'value' => false,
                'expected' => false,
            ],
            'array' => [
                'value' => [],
                'expected' => [],
            ],
        ];
    }

    #[DataProvider('provideSetGetValue')]
    public function testSetGetValue(mixed $value, mixed $expected): void
    {
        $percentage = new DBPercentage('Test');
        $percentage->setValue($value);
        $this->assertEquals($expected, $percentage->getValue());
    }

    public static function provideValidate(): array
    {
        return [
            'zero' => [
                'value' => 0,
                'expected' => true,
            ],
            'zero-point-five' => [
                'value' => 0.5,
                'expected' => true,
            ],
            'one' => [
                'value' => 1,
                'expected' => true,
            ],
            'one-point-five' => [
                'value' => 1.5,
                'expected' => false,
            ],
            'negative-zero-point-five' => [
                'value' => -0.5,
                'expected' => false,
            ],
            'string-zero' => [
                'value' => '0',
                'expected' => true,
            ],
            'string-zero-point-five' => [
                'value' => '0.5',
                'expected' => true,
            ],
            'string-one' => [
                'value' => '1',
                'expected' => true,
            ],
            'string-one-point-five' => [
                'value' => '1.5',
                'expected' => false,
            ],
            'string-negative-zero-point-five' => [
                'value' => '-0.5',
                'expected' => false,
            ],
            'string-fish' => [
                'value' => 'fish',
                'expected' => false,
            ],
            'empty-string' => [
                'value' => '',
                'expected' => false,
            ],
            'null' => [
                'value' => null,
                'expected' => true,
            ],
            'true' => [
                'value' => true,
                'expected' => false,
            ],
            'false' => [
                'value' => false,
                'expected' => false,
            ],
            'array' => [
                'value' => [],
                'expected' => false,
            ],
        ];
    }

    #[DataProvider('provideValidate')]
    public function testValidate(mixed $value, bool $expected): void
    {
        $percentage = new DBPercentage('Test');
        $percentage->setValue($value);
        $this->assertEquals($expected, $percentage->validate()->isValid());
    }
}
