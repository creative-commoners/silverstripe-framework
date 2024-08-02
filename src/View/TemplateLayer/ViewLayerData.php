<?php

namespace SilverStripe\View\TemplateLayer;

use BadMethodCallException;
use Countable;
use IteratorAggregate;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\ViewableData;
use Traversable;

/**
 * Data as provided to the view layer.
 *
 * All data that is displayed in templates should first be wrapped in this class
 * to ensure it is correctly escaped and cast in a consistent manner regardless
 * of which rendering engine is being used.
 *
 * Note that implementing Countable here is necessary (see the comment in count()),
 * and works as expected with twig and ss template engines, but it's concievable
 * that some other rendering engine would have special logic for Countable objects
 * or that twig might implement some logic for Countable objects in the future.
 * We should look to make the ss template engine not have the weakness that  currently
 * makes this necessary,
 */
class ViewLayerData implements IteratorAggregate, Countable
{
    // Note that this falls apart if, for example, a list contains raw arrays/values,
    // or ViewableData::obj() returns a non-ViewableData (which is possible but I think technically incorrect).
    // For best backwards compatibility and kindness to developers, we should accept mixed $data here
    // and handle casting/escaping of non-ViewableData in this class as well.
    public function __construct(private ViewableData $data)
    {}

    public function count(): int
    {
        // This will throw an exception if the data item isn't Countable,
        // but we have to have this so we can rewind in SSViewer_Scope::next()
        // after getting itemIteratorTotal without throwing an exception.
        // This could be avoided if we just return $this->data->getIterator() in
        // the getIterator() method (or omit that method entirely and let it be
        // handled with __call()) but then any time you loop you're using objects
        // that aren't ViewLayerData objects and therefore won't be cast or
        // escaped correctly by Twig.
        return count($this->data);
    }

    public function getIterator(): Traversable
    {
        if (!$this->dataIsIterable()) {
            throw new BadMethodCallException('This data is not iterable.');
        }
        foreach ($this->data->getIterator() as $item) {
            yield new ViewLayerData($item);
        }
    }

    public function dataIsIterable(): bool
    {
        return is_iterable($this->data);
    }

    public function __isset($name)
    {
        // Might be worth reintroducing the way ss template engine checks if lists/countables "exist" here,
        // i.e. if ($this->data->__isset($name) && is_countable($this->data->{$name})) { return count($this->data->{$name}); }
        // Those in worst-case scenarios that would result in lazy-loading a value when we don't need to.
        //
        // The SS template system uses `ViewableData::hasValue()` rather than isset(), so maybe we can use that here too.
        return $this->data->__isset($name);
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
}
