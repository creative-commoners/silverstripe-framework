<?php

namespace SilverStripe\ORM\Tests;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\NullableField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\FieldType\DBVarchar;

class DBVarcharTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        DBVarcharTest\TestObject::class,
    ];

    public function testScaffold()
    {
        $obj = new DBVarcharTest\TestObject();
        /** @var DBVarchar $dbField */
        $dbField = $obj->dbObject('Title');
        $this->assertTrue($dbField->getNullifyEmpty());
        /** @var TextField $field */
        $field = $dbField->scaffoldFormField();
        $this->assertInstanceOf(TextField::class, $field);
        $this->assertEquals(129, $field->getMaxLength());

        /** @var DBVarchar $dbField */
        $dbField = $obj->dbObject('NullableField');
        $this->assertFalse($dbField->getNullifyEmpty());
        /** @var NullableField $nullable */
        $nullable = $dbField->scaffoldFormField();
        $this->assertInstanceOf(NullableField::class, $nullable);
        $innerField = $nullable->valueField;
        $this->assertInstanceOf(TextField::class, $innerField);
        $this->assertEquals(111, $innerField->getMaxLength());
    }

    public function testDefaultValue()
    {
        $obj = new DBVarcharTest\TestObject();
        $id = $obj->write();
        // Note that the defalt value comes from the database, so it will be
        // on the record when we fetch it, not on the above $obj object.
        $record = DBVarcharTest\TestObject::get()->byID($id);
        $this->assertSame('default value', $record->HasDefault);
        $this->assertSame('default value', $record->HasDefaultOldSyntax);
    }

    public function testNullifyEmpty(): void
    {
        $obj = new DBVarcharTest\TestObject();
        $id = $obj->write();
        $record = DBVarcharTest\TestObject::get()->byID($id);
        // Default values
        $this->assertSame(null, $record->Title, 'Title should be null');
        $this->assertSame('', $record->NullableField, 'NullableField should be empty string');

        // Setting values to empty string - only title should be nullified
        $record->Title = '';
        $record->NullableField = '';
        $record->write();
        $record = DBVarcharTest\TestObject::get()->byID($id);
        $this->assertSame(null, $record->Title, 'Title should be null');
        $this->assertSame('', $record->NullableField, 'NullableField should be empty string');

        // Setting values to null - both should be null
        $record->Title = null;
        $record->NullableField = null;
        $record->write();
        $record = DBVarcharTest\TestObject::get()->byID($id);
        $this->assertSame(null, $record->Title, 'Title should be null');
        $this->assertSame(null, $record->NullableField, 'NullableField should be null');
    }
}
