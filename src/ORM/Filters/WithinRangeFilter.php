<?php

namespace SilverStripe\ORM\Filters;

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
}
