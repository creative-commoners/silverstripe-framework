<?php

namespace SilverStripe\View\TemplateEngine;

use BadMethodCallException;
use IteratorAggregate;
use SilverStripe\View\ViewableData;
use Traversable;

class ViewLayerData implements IteratorAggregate
{
    // Note that this falls apart if, for example, a list contains raw arrays/values,
    // or obj() returns a non-ViewableData (which is possible but I think technically incorrect).
    // For best backwards compatibility, we should accept mixed $data here.
    // Optionally, Viewable::data() should explicitly be typehinted to return ViewableData,
    // and we could pass the values in the iterator through ViewableData::obj() before decorating
    // them.
    public function __construct(private ViewableData $data)
    {}

    public function getIterator(): Traversable
    {
        if (!$this->data->hasMethod('getIterator')) {
            throw new BadMethodCallException('This data is not iterable.');
        }
        foreach ($this->data->getIterator() as $item) {
            yield new ViewLayerData($item);
        }
    }

    public function __get($name)
    {
        return new ViewLayerData($this->data->obj($name));
    }

    public function __call($name, $arguments)
    {
        return new ViewLayerData($this->data->obj($name, $arguments));
    }

    public function __toString()
    {
        // Will call forTemplate() by default.
        return $this->data->__toString();
    }

    // Might be worth implementing __isset() as well to re-introduce the way ss template engine checks if lists/countables "exist".
}
