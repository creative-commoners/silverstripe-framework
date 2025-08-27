<?php

namespace SilverStripe\Forms\Tests;

use SilverStripe\ORM\DataObject;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\LookupField;
use SilverStripe\Security\Member;
use PHPUnit\Framework\Attributes\DataProvider;

class LookupFieldTest extends SapphireTest
{
    protected static $fixture_file = 'LookupFieldTest.yml';

    public static function provideValidate(): array
    {
        return [
            'valid-value' => [
                'value' => [1],
                'expected' => true,
            ],
            // test that validation isn't being applied to read-only field
            'valid-not-value' => [
                'value' => [3],
                'expected' => true,
            ],
        ];
    }

    /**
     * @useDatabase false
     */
    #[DataProvider('provideValidate')]
    public function testValidate(mixed $value, bool $expected): void
    {
        $field = new LookupField('Test', 'Test', [1 => 'cat', 2 => 'dog']);
        $field->setValue($value);
        $this->assertSame($expected, $field->validate()->isValid());
    }

    public function testNullValueWithNumericArraySource()
    {
        $source = [1 => 'one', 2 => 'two', 3 => 'three'];
        $field = new LookupField('test', 'test', $source);
        $field->setValue(null);
        $result = trim($field->Field()->getValue() ?? '');

        $this->assertXmlStringEqualsXmlString(
            $this->toXml('<span class="readonly" id="test" role="textbox" aria-readonly="true" tabindex="0">'
            . '<i>(none)</i></span><input type="hidden" name="test" value="" />'),
            $this->toXml($result),
        );
    }

    public function testStringValueWithNumericArraySource()
    {
        $source = [1 => 'one', 2 => 'two', 3 => 'three'];
        $field = new LookupField('test', 'test', $source);
        $field->setValue(1);
        $result = trim($field->Field()->getValue() ?? '');
        $this->assertXmlStringEqualsXmlString(
            $this->toXml('<span class="readonly" id="test" role="textbox" aria-readonly="true" tabindex="0">'
            . 'one</span><input type="hidden" name="test" value="1" />'),
            $this->toXml($result)
        );
    }

    public function testUnknownStringValueWithNumericArraySource()
    {
        $source = [1 => 'one', 2 => 'two', 3 => 'three'];
        $field = new LookupField('test', 'test', $source);
        $field->setValue('w00t');
        $result = trim($field->Field()->getValue() ?? '');

        $this->assertXmlStringEqualsXmlString(
            $this->toXml('<span class="readonly" id="test" role="textbox" aria-readonly="true" tabindex="0">'
            . 'w00t</span><input type="hidden" name="test" value="" />'),
            $this->toXml($result)
        );
    }

    public function testArrayValueWithAssociativeArraySource()
    {
        // Array values (= multiple selections) might be set e.g. from ListboxField
        $source = ['one' => 'one val', 'two' => 'two val', 'three' => 'three val'];
        $field = new LookupField('test', 'test', $source);
        $field->setValue(['one','two']);
        $result = trim($field->Field()->getValue() ?? '');

        $this->assertXmlStringEqualsXmlString(
            $this->toXml('<span class="readonly" id="test" role="textbox" aria-readonly="true" tabindex="0">'
            . 'one val, two val</span><input type="hidden" name="test" value="one, two" />'),
            $this->toXml($result)
        );
    }

    public function testArrayValueWithNumericArraySource()
    {
        // Array values (= multiple selections) might be set e.g. from ListboxField
        $source = [1 => 'one', 2 => 'two', 3 => 'three'];
        $field = new LookupField('test', 'test', $source);
        $field->setValue([1,2]);
        $result = trim($field->Field()->getValue() ?? '');

        $this->assertXmlStringEqualsXmlString(
            $this->toXml('<span class="readonly" id="test" role="textbox" aria-readonly="true" tabindex="0">'
            . 'one, two</span><input type="hidden" name="test" value="1, 2" />'),
            $this->toXml($result)
        );
    }

    public function testArrayValueWithSqlMapSource()
    {
        $member1 = $this->objFromFixture(Member::class, 'member1');
        $member2 = $this->objFromFixture(Member::class, 'member2');
        $member3 = $this->objFromFixture(Member::class, 'member3');

        $source = DataObject::get(Member::class);
        $field = new LookupField('test', 'test', $source->map('ID', 'FirstName'));
        $field->setValue([$member1->ID, $member2->ID]);
        $result = trim($field->Field()->getValue() ?? '');

        $this->assertXmlStringEqualsXmlString(
            $this->toXml(sprintf(
                '<span class="readonly" id="test" role="textbox" aria-readonly="true" tabindex="0">'
                . 'member1, member2</span><input type="hidden" name="test" value="%s, %s" />',
                $member1->ID,
                $member2->ID
            )),
            $this->toXml($result)
        );
    }

    public function testWithMultiDimensionalSource()
    {
        $choices = [
            "Non-vegetarian" => [
                0 => 'Carnivore',
            ],
            "Vegetarian" => [
                3 => 'Carrots',
            ],
            "Other" => [
                9 => 'Vegan'
            ]
        ];

        $field = new LookupField('test', 'test', $choices);
        $field->setValue(3);
        $result = trim($field->Field()->getValue() ?? '');

        $this->assertXmlStringEqualsXmlString(
            $this->toXml('<span class="readonly" id="test" role="textbox" aria-readonly="true" tabindex="0">'
            . 'Carrots</span><input type="hidden" name="test" value="3" />'),
            $this->toXml($result)
        );

        $field->setValue([3, 9]);
        $result = trim($field->Field()->getValue() ?? '');

        $this->assertXmlStringEqualsXmlString(
            $this->toXml('<span class="readonly" id="test" role="textbox" aria-readonly="true" tabindex="0">'
            . 'Carrots, Vegan</span><input type="hidden" name="test" value="3, 9" />'),
            $this->toXml($result)
        );
    }

    /**
     * Ensure there is a single parent node in preparation for using assertXmlStringEqualsXmlString()
     * which is tolerant of whitespaces differences
     * This prevents the error PHPUnit\Util\Xml\XmlException: Extra content at the end of the document
     */
    private function toXml(string $html)
    {
        return "<div>$html</div>";
    }
}
