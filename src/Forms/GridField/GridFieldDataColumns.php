<?php

namespace SilverStripe\Forms\GridField;

use SilverStripe\Core\Convert;
use InvalidArgumentException;
use SilverStripe\ORM\DataObject;

/**
 * @see GridField
 */
class GridFieldDataColumns extends AbstractGridFieldComponent implements GridField_ColumnProvider
{

    /**
     * @var array
     */
    public $fieldCasting = [];

    /**
     * @var array
     */
    public $fieldFormatting = [];

    /**
     * This is the columns that will be visible
     *
     * @var array
     */
    protected $displayFields = [];

    /**
     * Modify the list of columns displayed in the table.
     * See {@link GridFieldDataColumns->getDisplayFields()} and {@link GridFieldDataColumns}.
     *
     * @param GridField $gridField
     * @param array $columns List reference of all column names. (by reference)
     */
    public function augmentColumns(SilverStripe\Forms\GridField\GridField $gridField, &$columns): void
    {
        $baseColumns = array_keys($this->getDisplayFields($gridField) ?? []);

        foreach ($baseColumns as $col) {
            $columns[] = $col;
        }

        $columns = array_unique($columns ?? []);
    }

    /**
     * Names of all columns which are affected by this component.
     *
     * @param GridField $gridField
     * @return array
     */
    public function getColumnsHandled(SilverStripe\Forms\GridField\GridField $gridField): array
    {
        return array_keys($this->getDisplayFields($gridField) ?? []);
    }

    /**
     * Override the default behaviour of showing the models summaryFields with
     * these fields instead
     * Example: array( 'Name' => 'Members name', 'Email' => 'Email address')
     *
     * @param array $fields
     * @return $this
     */
    public function setDisplayFields(array|stdClass $fields): SilverStripe\Forms\GridField\GridFieldDataColumns
    {
        if (!is_array($fields)) {
            throw new InvalidArgumentException(
                'Arguments passed to GridFieldDataColumns::setDisplayFields() must be an array'
            );
        }
        $this->displayFields = $fields;
        return $this;
    }

    /**
     * Get the DisplayFields
     *
     * @param GridField $gridField
     * @return array
     * @see GridFieldDataColumns::setDisplayFields
     */
    public function getDisplayFields(SilverStripe\Forms\GridField\GridField $gridField): array
    {
        if (!$this->displayFields) {
            return singleton($gridField->getModelClass())->summaryFields();
        }
        return $this->displayFields;
    }

    /**
     * Specify castings with fieldname as the key, and the desired casting as value.
     * Example: array("MyCustomDate"=>"Date","MyShortText"=>"Text->FirstSentence")
     *
     * @param array $casting
     * @return $this
     */
    public function setFieldCasting(array $casting): SilverStripe\Forms\GridField\GridFieldDataColumns
    {
        $this->fieldCasting = $casting;
        return $this;
    }

    /**
     * @return array
     */
    public function getFieldCasting(): array
    {
        return $this->fieldCasting;
    }

    /**
     * Specify custom formatting for fields, e.g. to render a link instead of pure text.
     *
     * Caution: Make sure to escape special php-characters like in a normal php-statement.
     * Example:    "myFieldName" => '<a href=\"custom-admin/$ID\">$ID</a>'.
     *
     * Alternatively, pass a anonymous function, which takes two parameters:
     * The value and the original list item.
     *
     * Formatting is applied after field casting, so if you're modifying the string
     * to include further data through custom formatting, ensure it's correctly escaped.
     *
     * @param array $formatting
     * @return $this
     */
    public function setFieldFormatting(array $formatting): SilverStripe\Forms\GridField\GridFieldDataColumns
    {
        $this->fieldFormatting = $formatting;
        return $this;
    }

    /**
     * @return array
     */
    public function getFieldFormatting(): array
    {
        return $this->fieldFormatting;
    }

    /**
     * HTML for the column, content of the <td> element.
     *
     * @param GridField $gridField
     * @param DataObject $record Record displayed in this row
     * @param string $columnName
     * @return string HTML for the column. Return NULL to skip.
     */
    public function getColumnContent(SilverStripe\Forms\GridField\GridField $gridField, DNADesign\Elemental\Tests\Src\TestElement $record, string $columnName): string|null|SilverStripe\ORM\FieldType\DBHTMLText
    {
        // Find the data column for the given named column
        $columns = $this->getDisplayFields($gridField);
        $columnInfo = array_key_exists($columnName, $columns ?? []) ? $columns[$columnName] : null;

        // Allow callbacks
        if (is_array($columnInfo) && isset($columnInfo['callback'])) {
            $method = $columnInfo['callback'];
            $value = $method($record, $columnName, $gridField);

        // This supports simple FieldName syntax
        } else {
            $value = $gridField->getDataFieldValue($record, $columnName);
        }

        // Turn $value, whatever it is, into a HTML embeddable string
        $value = $this->castValue($gridField, $columnName, $value);
        // Make any formatting tweaks
        $value = $this->formatValue($gridField, $record, $columnName, $value);
        // Do any final escaping
        $value = $this->escapeValue($gridField, $value);

        return $value;
    }

    /**
     * Attributes for the element containing the content returned by {@link getColumnContent()}.
     *
     * @param  GridField $gridField
     * @param  DataObject $record displayed in this row
     * @param  string $columnName
     * @return array
     */
    public function getColumnAttributes(SilverStripe\Forms\GridField\GridField $gridField, DNADesign\Elemental\Tests\Src\TestElement $record, string $columnName): array
    {
        return ['class' => 'col-' . preg_replace('/[^\w]/', '-', $columnName ?? '')];
    }

    /**
     * Additional metadata about the column which can be used by other components,
     * e.g. to set a title for a search column header.
     *
     * @param GridField $gridField
     * @param string $column
     * @return array Map of arbitrary metadata identifiers to their values.
     */
    public function getColumnMetadata(SilverStripe\Forms\GridField\GridField $gridField, string $column): array
    {
        $columns = $this->getDisplayFields($gridField);

        $title = null;
        if (is_string($columns[$column])) {
            $title = $columns[$column];
        } elseif (is_array($columns[$column]) && isset($columns[$column]['title'])) {
            $title = $columns[$column]['title'];
        }

        return [
            'title' => $title,
        ];
    }

    /**
     * Translate a Object.RelationName.ColumnName $columnName into the value that ColumnName returns
     *
     * @param DataObject $record
     * @param string $columnName
     * @return string|null - returns null if it could not found a value
     */
    protected function getValueFromRelation($record, $columnName)
    {
        $fieldNameParts = explode('.', $columnName ?? '');
        $tmpItem = clone($record);
        for ($idx = 0; $idx < sizeof($fieldNameParts ?? []); $idx++) {
            $methodName = $fieldNameParts[$idx];
            // Last mmethod call from $columnName return what that method is returning
            if ($idx == sizeof($fieldNameParts ?? []) - 1) {
                return $tmpItem->XML_val($methodName);
            }
            // else get the object from this $methodName
            $tmpItem = $tmpItem->$methodName();
        }
        return null;
    }

    /**
     * Casts a field to a string which is safe to insert into HTML
     *
     * @param GridField $gridField
     * @param string $fieldName
     * @param string $value
     * @return string
     */
    protected function castValue(SilverStripe\Forms\GridField\GridField $gridField, string $fieldName, SilverStripe\ORM\FieldType\DBHTMLText|string|int|float $value): string|null
    {
        // If a fieldCasting is specified, we assume the result is safe
        if (array_key_exists($fieldName, $this->fieldCasting ?? [])) {
            $value = $gridField->getCastedValue($value, $this->fieldCasting[$fieldName]);
        } elseif (is_object($value)) {
            // If the value is an object, we do one of two things
            if (method_exists($value, 'Nice')) {
                // If it has a "Nice" method, call that & make sure the result is safe
                $value = nl2br(Convert::raw2xml($value->Nice()) ?? '');
            } else {
                // Otherwise call forTemplate - the result of this should already be safe
                $value = $value->forTemplate();
            }
        } else {
            // Otherwise, just treat as a text string & make sure the result is safe
            $value = nl2br(Convert::raw2xml($value) ?? '');
        }

        return $value;
    }

    /**
     *
     * @param GridField $gridField
     * @param DataObject $item
     * @param string $fieldName
     * @param string $value
     * @return string
     */
    protected function formatValue(SilverStripe\Forms\GridField\GridField $gridField, DNADesign\Elemental\Tests\Src\TestElement $item, string $fieldName, string $value): string|null|SilverStripe\ORM\FieldType\DBHTMLText
    {
        if (!array_key_exists($fieldName, $this->fieldFormatting ?? [])) {
            return $value;
        }

        $spec = $this->fieldFormatting[$fieldName];
        if (!is_string($spec) && is_callable($spec)) {
            return $spec($value, $item);
        } else {
            $format = str_replace('$value', "__VAL__", $spec ?? '');
            $format = preg_replace('/\$([A-Za-z0-9-_]+)/', '$item->$1', $format ?? '');
            $format = str_replace('__VAL__', '$value', $format ?? '');
            eval('$value = "' . $format . '";');
            return $value;
        }
    }

    /**
     * Remove values from a value using FieldEscape setter
     *
     * @param GridField $gridField
     * @param string $value
     * @return string
     */
    protected function escapeValue(SilverStripe\Forms\GridField\GridField $gridField, string|SilverStripe\ORM\FieldType\DBHTMLText $value): string|null|SilverStripe\ORM\FieldType\DBHTMLText
    {
        if (!$escape = $gridField->FieldEscape) {
            return $value;
        }

        foreach ($escape as $search => $replace) {
            $value = str_replace($search ?? '', $replace ?? '', $value ?? '');
        }
        return $value;
    }
}
