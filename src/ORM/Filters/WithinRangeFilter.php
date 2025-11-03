<?php

namespace SilverStripe\ORM\Filters;

use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FormField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;

class WithinRangeFilter extends SearchFilter
{
    private mixed $min = null;
    private mixed $max = null;

    public function setMin(mixed $min)
    {
        $this->min = $min;
    }

    public function getMin(): mixed
    {
        return $this->min;
    }

    public function setMax(mixed $max)
    {
        $this->max = $max;
    }

    public function getMax(): mixed
    {
        return $this->max;
    }

    protected function applyOne(DataQuery $query)
    {
        $this->model = $query->applyRelation($this->relation);
        $predicate = sprintf('%1$s >= ? AND %1$s <= ?', $this->getDbName());
        return $query->where([
            $predicate => [
                $this->min,
                $this->max
            ]
        ]);
    }

    protected function excludeOne(DataQuery $query)
    {
        $this->model = $query->applyRelation($this->relation);
        $predicate = sprintf('%1$s < ? OR %1$s > ?', $this->getDbName());
        return $query->where([
            $predicate => [
                $this->min,
                $this->max
            ]
        ]);
    }

    /**
     * Take a single form field and turn it into seaprate "from" and "to" fields in a group.
     */
    public static function convertToRangeField(FormField $originalField): FormField
    {
        $fieldFrom = $originalField;
        $fieldTo = clone $originalField;
        $originalTitle = $originalField->Title();
        $originalName = $originalField->getName();

        $fieldFrom->setName($originalName . '_SearchFrom');
        $fieldFrom->setTitle(_t(DataObject::class . '.FILTER_WITHINRANGE_FROM', 'From'));
        $fieldTo->setName($originalName . '_SearchTo');
        $fieldTo->setTitle(_t(DataObject::class . '.FILTER_WITHINRANGE_TO', 'To'));

        return FieldGroup::create(
            $originalTitle,
            [$fieldFrom, $fieldTo]
        )->setName($originalName)->addExtraClass('fieldgroup--fill-width');
    }
}
