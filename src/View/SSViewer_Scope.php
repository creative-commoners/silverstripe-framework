<?php

namespace SilverStripe\View;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use Iterator;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\ORM\FieldType\DBText;
use SilverStripe\ORM\FieldType\DBFloat;
use SilverStripe\ORM\FieldType\DBInt;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\View\TemplateLayer\ViewLayerData;

/**
 * This tracks the current scope for an SSViewer instance. It has three goals:
 *   - Handle entering & leaving sub-scopes in loops and withs
 *   - Track Up and Top
 *   - (As a side effect) Inject data that needs to be available globally (used to live in ViewableData)
 *
 * It is also responsible for mixing in data on top of what the item provides. This can be "global"
 * data that is scope-independant (like BaseURL), or type-specific data that is layered on top cross-cut like
 * (like $FirstLast etc).
 *
 * In order to handle up, rather than tracking it using a tree, which would involve constructing new objects
 * for each step, we use indexes into the itemStack (which already has to exist).
 *
 * Each item has three indexes associated with it
 *
 *   - Pop. Which item should become the scope once the current scope is popped out of
 *   - Up. Which item is up from this item
 *   - Current. Which item is the first time this object has appeared in the stack
 *
 * We also keep the index of the current starting point for lookups. A lookup is a sequence of obj calls -
 * when in a loop or with tag the end result becomes the new scope, but for injections, we throw away the lookup
 * and revert back to the original scope once we've got the value we're after
 */
class SSViewer_Scope
{
    const ITEM = 0;
    const ITEM_ITERATOR = 1;
    const ITEM_ITERATOR_TOTAL = 2;
    const POP_INDEX = 3;
    const UP_INDEX = 4;
    const CURRENT_INDEX = 5;
    const ITEM_OVERLAY = 6;

    /**
     * The stack of previous items ("scopes") - an indexed array of: item, item iterator, item iterator total,
     * pop index, up index, current index & parent overlay
     *
     * @var array
     */
    private $itemStack = [];

    /**
     * The current "global" item (the one any lookup starts from)
     *
     * @var object
     */
    protected $item;

    /**
     * If we're looping over the current "global" item, here's the iterator that tracks with item we're up to
     *
     * @var Iterator
     */
    protected $itemIterator;

    /**
     * Total number of items in the iterator
     *
     * @var int
     */
    protected $itemIteratorTotal;

    /**
     * A pointer into the item stack for the item that will become the active scope on the next pop call
     *
     * @var int
     */
    private $popIndex;

    /**
     * A pointer into the item stack for which item is "up" from this one
     *
     * @var int
     */
    private $upIndex;

    /**
     * A pointer into the item stack for which the active item (or null if not in stack yet)
     *
     * @var int
     */
    private $currentIndex;

    /**
     * A store of copies of the main item stack, so it's preserved during a lookup from local scope
     * (which may push/pop items to/from the main item stack)
     *
     * @var array
     */
    private $localStack = [];

    /**
     * The index of the current item in the main item stack, so we know where to restore the scope
     * stored in $localStack.
     *
     * @var int
     */
    private $localIndex = 0;

    /**
     * List of global property providers
     *
     * @internal
     * @var TemplateGlobalProvider[]|null
     */
    private static $globalProperties = null;

    /**
     * List of global iterator providers
     *
     * @internal
     * @var TemplateIteratorProvider[]|null
     */
    private static $iteratorProperties = null;

    /**
     * Overlay variables. Take precedence over anything from the current scope
     *
     * @var array|null
     */
    protected $overlay;

    /**
     * Flag for whether overlay should be preserved when pushing a new scope
     *
     * @see SSViewer_Scope::pushScope()
     * @var bool
     */
    protected $preserveOverlay = false;

    /**
     * Underlay variables. Concede precedence to overlay variables or anything from the current scope
     *
     * @var array
     */
    protected $underlay;

    public function __construct(
        ?ViewLayerData $item,
        array $overlay = [],
        array $underlay = [],
        SSViewer_Scope $inheritedScope = null
    ) {
        $this->item = $item;

        $this->itemIterator = ($inheritedScope) ? $inheritedScope->itemIterator : null;
        $this->itemIteratorTotal = ($inheritedScope) ? $inheritedScope->itemIteratorTotal : 0;
        $this->itemStack[] = [$this->item, $this->itemIterator, $this->itemIteratorTotal, null, null, 0];

        $this->overlay = $overlay;
        $this->underlay = $underlay;

        $this->cacheGlobalProperties();
        $this->cacheIteratorProperties();
    }

    /**
     * Returns the current "active" item
     *
     * @return object
     */
    public function getItem()
    {
        $item = $this->itemIterator ? $this->itemIterator->current() : $this->item;
        if (is_scalar($item)) {
            $item = $this->convertScalarToDBField($item);
        }

        // Wrap list arrays in ViewableData so templates can handle them
        if (is_array($item) && array_is_list($item)) {
            $item = ArrayList::create($item);
        }

        return $item;
    }

    /**
     * Called at the start of every lookup chain by SSTemplateParser to indicate a new lookup from local scope
     *
     * @return SSViewer_Scope
     */
    public function locally()
    {
        list(
            $this->item,
            $this->itemIterator,
            $this->itemIteratorTotal,
            $this->popIndex,
            $this->upIndex,
            $this->currentIndex
        ) = $this->itemStack[$this->localIndex];

        // Remember any  un-completed (resetLocalScope hasn't been called) lookup chain. Even if there isn't an
        // un-completed chain we need to store an empty item, as resetLocalScope doesn't know the difference later
        $this->localStack[] = array_splice($this->itemStack, $this->localIndex + 1);

        return $this;
    }

    /**
     * Reset the local scope - restores saved state to the "global" item stack. Typically called after
     * a lookup chain has been completed
     */
    public function resetLocalScope()
    {
        // Restore previous un-completed lookup chain if set
        $previousLocalState = $this->localStack ? array_pop($this->localStack) : null;
        array_splice($this->itemStack, $this->localIndex + 1, count($this->itemStack ?? []), $previousLocalState);

        list(
            $this->item,
            $this->itemIterator,
            $this->itemIteratorTotal,
            $this->popIndex,
            $this->upIndex,
            $this->currentIndex
        ) = end($this->itemStack);
    }

    /**
     */
    public function getObj(string $name, array $arguments, string $type, bool $cache, ?string $cacheName)
    {
        if ($name === 'Layout') {
            echo '';
        }
        // @TODO caching (used to be handled by ViewableData::obj() - and therefore ignored for overlays and underlays!!)
        // Use an overlay or underlay if there is one
        $result = $this->getInjectedValue($name, (array)$arguments);
        if ($result) {
            $obj = $result['obj'];
            return ($obj instanceof ViewLayerData) ? $obj : new ViewLayerData($obj);
        }

        // Get the actual object
        if ($type === 'method') {
            return $this->getItem()->$name(...$arguments);
        }
        return $this->getItem()->$name;
    }

    /**
     * Set scope to an intermediate value, which will be used for getting output later on,
     *
     * $Up and $Top need to restore the overlay from the parent and top-level
     * scope respectively.
     */
    public function scopeToIntermediateValue(
        string $name,
        array $arguments = [],
        string $type = '',
        bool $cache = false,
        ?string $cacheName = null
    ): SSViewer_Scope {
        // @TODO there's obviously some double handling here with up and top...
        $overlayIndex = false;

        switch ($name) {
            case 'Up':
                $upIndex = $this->getUpIndex();
                if ($upIndex === null) {
                    throw new \LogicException('Up called when we\'re already at the top of the scope');
                }
                $overlayIndex = $upIndex; // Parent scope
                $this->preserveOverlay = true; // Preserve overlay
                break;
            case 'Top':
                $overlayIndex = 0; // Top-level scope
                $this->preserveOverlay = true; // Preserve overlay
                break;
            default:
                $this->preserveOverlay = false;
                break;
        }

        if ($overlayIndex !== false) {
            $itemStack = $this->getItemStack();
            if (!$this->overlay && isset($itemStack[$overlayIndex][SSViewer_Scope::ITEM_OVERLAY])) {
                $this->overlay = $itemStack[$overlayIndex][SSViewer_Scope::ITEM_OVERLAY];
            }
        }

        switch ($name) {
            case 'Up':
                if ($this->upIndex === null) {
                    throw new \LogicException('Up called when we\'re already at the top of the scope');
                }

                list(
                    $this->item,
                    $this->itemIterator,
                    $this->itemIteratorTotal,
                    /* dud */,
                    $this->upIndex,
                    $this->currentIndex
                ) = $this->itemStack[$this->upIndex];
                break;
            case 'Top':
                list(
                    $this->item,
                    $this->itemIterator,
                    $this->itemIteratorTotal,
                    /* dud */,
                    $this->upIndex,
                    $this->currentIndex
                ) = $this->itemStack[0];
                break;
            default:
                $this->item = $this->getObj($name, $arguments, $type, $cache, $cacheName);
                $this->itemIterator = null;
                $this->upIndex = $this->currentIndex ? $this->currentIndex : count($this->itemStack) - 1;
                $this->currentIndex = count($this->itemStack);
                break;
        }

        $this->itemStack[] = [
            $this->item,
            $this->itemIterator,
            $this->itemIteratorTotal,
            null,
            $this->upIndex,
            $this->currentIndex
        ];
        return $this;
    }

    public function getOutputValue(string $name, array $arguments = [], string $type = '', bool $cache = false, ?string $cacheName = null): string
    {
        // @TODO caching
        $retval = $this->getObj($name, $arguments, $type, $cache, $cacheName);
        $this->resetLocalScope();
        return is_object($retval) ? $retval->__toString() : $retval;
    }

    /**
     * Gets the current object and resets the scope.
     *
     * @return object
     */
    public function self()
    {
        $result = $this->getItem();
        $this->resetLocalScope();

        return $result;
    }

    /**
     * Jump to the last item in the stack, called when a new item is added before a loop/with
     *
     * Store the current overlay (as it doesn't directly apply to the new scope
     * that's being pushed). We want to store the overlay against the next item
     * "up" in the stack (hence upIndex), rather than the current item, because
     * SSViewer_Scope::obj() has already been called and pushed the new item to
     * the stack by this point
     *
     * @return SSViewer_Scope
     */
    public function pushScope()
    {
        $newLocalIndex = count($this->itemStack ?? []) - 1;

        $this->popIndex = $this->itemStack[$newLocalIndex][SSViewer_Scope::POP_INDEX] = $this->localIndex;
        $this->localIndex = $newLocalIndex;

        // $Up now becomes the parent scope - the parent of the current <% loop %> or <% with %>
        $this->upIndex = $this->itemStack[$newLocalIndex][SSViewer_Scope::UP_INDEX] = $this->popIndex;

        // We normally keep any previous itemIterator around, so local $Up calls reference the right element. But
        // once we enter a new global scope, we need to make sure we use a new one
        $this->itemIterator = $this->itemStack[$newLocalIndex][SSViewer_Scope::ITEM_ITERATOR] = null;

        $upIndex = $this->getUpIndex() ?: 0;

        $itemStack = $this->getItemStack();
        $itemStack[$upIndex][SSViewer_Scope::ITEM_OVERLAY] = $this->overlay;
        $this->setItemStack($itemStack);

        // Remove the overlay when we're changing to a new scope, as values in
        // that scope take priority. The exceptions that set this flag are $Up
        // and $Top as they require that the new scope inherits the overlay
        if (!$this->preserveOverlay) {
            $this->overlay = [];
        }

        return $this;
    }

    /**
     * Now that we're going to jump up an item in the item stack, we need to
     * restore the overlay that was previously stored against the next item "up"
     * in the stack from the current one
     *
     * Jump back to "previous" item in the stack, called after a loop/with block
     *
     * @return SSViewer_Scope
     */
    public function popScope()
    {
        $upIndex = $this->getUpIndex();

        if ($upIndex !== null) {
            $itemStack = $this->getItemStack();
            $this->overlay = $itemStack[$upIndex][SSViewer_Scope::ITEM_OVERLAY];
        }

        $this->localIndex = $this->popIndex;
        $this->resetLocalScope();

        return $this;
    }

    /**
     * Fast-forwards the current iterator to the next item
     *
     * @return mixed
     */
    public function next()
    {
        if (!$this->item) {
            return false;
        }

        if (!$this->itemIterator) {
            // Note: it is important that getIterator() is called before count() as implemenations may rely on
            // this to efficiency get both the number of records and an iterator (e.g. DataList does this)

            // Item may be an array or a regular IteratorAggregate
            if (is_array($this->item)) {
                $this->itemIterator = new ArrayIterator($this->item);
            } elseif ($this->item instanceof Iterator) {
                $this->itemIterator = $this->item;
            } else {
                $this->itemIterator = $this->item->getIterator();

                // This will execute code in a generator up to the first yield. For example, this ensures that
                // DataList::getIterator() is called before Datalist::count()
                $this->itemIterator->rewind();
            }

            // If the item implements Countable, use that to fetch the count, otherwise we have to inspect the
            // iterator and then rewind it.
            if ($this->item instanceof Countable) {
                $this->itemIteratorTotal = count($this->item);
            } else {
                $this->itemIteratorTotal = iterator_count($this->itemIterator);
                $this->itemIterator->rewind();
            }

            $this->itemStack[$this->localIndex][SSViewer_Scope::ITEM_ITERATOR] = $this->itemIterator;
            $this->itemStack[$this->localIndex][SSViewer_Scope::ITEM_ITERATOR_TOTAL] = $this->itemIteratorTotal;
        } else {
            $this->itemIterator->next();
        }

        $this->resetLocalScope();

        if (!$this->itemIterator->valid()) {
            return false;
        }

        return $this->itemIterator->key();
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        // Extract the method name and parameters
        $property = $arguments[0];  // The name of the public function being called

        // The public function parameters in an array
        $params = (isset($arguments[1])) ? (array)$arguments[1] : [];

        $val = $this->getInjectedValue($property, $params);
        if ($val) {
            $obj = $val['obj'];
            if ($name === 'hasValue') { // @TODO nothing is ViewableData anymore, so we have to resolve this, probably in ViewLayerData::__isset()
                $result = ($obj instanceof ViewableData) ? $obj->exists() : (bool)$obj;
            } elseif (is_null($obj) || (is_scalar($obj) && !is_string($obj))) {
                $result = $obj; // Nulls and non-string scalars don't need casting
            } else {
                $result = $obj->forTemplate(); // XML_val
            }

            $this->resetLocalScope();
            return $result;
        }

        $on = $this->getItem();
        $retval = $on ? $on->$name(...$arguments) : null;

        $this->resetLocalScope();
        return $retval;
    }


    /**
     * Build cache of global properties
     */
    protected function cacheGlobalProperties()
    {
        if (SSViewer_Scope::$globalProperties !== null) {
            return;
        }

        SSViewer_Scope::$globalProperties = $this->getPropertiesFromProvider(
            TemplateGlobalProvider::class,
            'get_template_global_variables'
        );
    }

    /**
     * Build cache of global iterator properties
     */
    protected function cacheIteratorProperties()
    {
        if (SSViewer_Scope::$iteratorProperties !== null) {
            return;
        }

        SSViewer_Scope::$iteratorProperties = $this->getPropertiesFromProvider(
            TemplateIteratorProvider::class,
            'get_template_iterator_variables',
            true // Call non-statically
        );
    }

    /**
     * @var string $interfaceToQuery
     * @var string $variableMethod
     * @var boolean $createObject
     * @return array
     */
    public function getPropertiesFromProvider($interfaceToQuery, $variableMethod, $createObject = false)
    {
        $implementors = ClassInfo::implementorsOf($interfaceToQuery);
        if ($implementors) {
            foreach ($implementors as $implementor) {
                // Create a new instance of the object for method calls
                if ($createObject) {
                    $implementor = new $implementor();
                    $exposedVariables = $implementor->$variableMethod();
                } else {
                    $exposedVariables = $implementor::$variableMethod();
                }

                foreach ($exposedVariables as $varName => $details) {
                    if (!is_array($details)) {
                        $details = [
                            'method' => $details,
                            'casting' => ViewableData::config()->uninherited('default_cast')
                        ];
                    }

                    // If just a value (and not a key => value pair), use method name for both key and value
                    if (is_numeric($varName)) {
                        $varName = $details['method'];
                    }

                    // Add in a reference to the implementing class (might be a string class name or an instance)
                    $details['implementor'] = $implementor;

                    // And a callable array
                    if (isset($details['method'])) {
                        $details['callable'] = [$implementor, $details['method']];
                    }

                    // Save with both uppercase & lowercase first letter, so either works
                    $lcFirst = strtolower($varName[0] ?? '') . substr($varName ?? '', 1);
                    $result[$lcFirst] = $details;
                    $result[ucfirst($varName)] = $details;
                }
            }
        }

        return $result;
    }

    /**
     * Look up injected value - it may be part of an "overlay" (arguments passed to <% include %>),
     * set on the current item, part of an "underlay" ($Layout or $Content), or an iterator/global property
     *
     * @param string $property Name of property
     * @param array $params
     * @param bool $cast If true, an object is always returned even if not an object.
     * @return array|null
     */
    public function getInjectedValue(string $property, array $params, $cast = true)
    {
        // Get source for this value
        $result = $this->getValueSource($property);
        if (!array_key_exists('source', $result)) {
            return null;
        }

        // Look up the value - either from a callable, or from a directly provided value
        $source = $result['source'];
        $res = [];
        if (isset($source['callable'])) {
            $res['value'] = $source['callable'](...$params);
        } elseif (array_key_exists('value', $source)) {
            $res['value'] = $source['value'];
        } else {
            throw new InvalidArgumentException(
                "Injected property $property doesn't have a value or callable value source provided"
            );
        }

        // If we want to provide a casted object, look up what type object to use
        if ($cast) {
            $res['obj'] = $this->castValue($res['value'], $source);
        }

        return $res;
    }


    /**
     * Evaluate a template override. Returns an array where the presence of
     * a 'value' key indiciates whether an override was successfully found,
     * as null is a valid override value
     *
     * @param string $property Name of override requested
     * @param array $overrides List of overrides available
     * @return array An array with a 'value' key if a value has been found, or empty if not
     */
    protected function processTemplateOverride($property, $overrides)
    {
        if (!array_key_exists($property, $overrides)) {
            return [];
        }

        // Detect override type
        $override = $overrides[$property];

        // Late-evaluate this value
        if (!is_string($override) && is_callable($override)) {
            $override = $override();

            // Late override may yet return null
            if (!isset($override)) {
                return [];
            }
        }

        return ['value' => $override];
    }

    /**
     * Determine source to use for getInjectedValue. Returns an array where the presence of
     * a 'source' key indiciates whether a value source was successfully found, as a source
     * may be a null value returned from an override
     *
     * @param string $property
     * @return array An array with a 'source' key if a value source has been found, or empty if not
     */
    protected function getValueSource($property)
    {
        // Check for a presenter-specific override
        $result = $this->processTemplateOverride($property, $this->overlay);
        if (array_key_exists('value', $result)) {
            return ['source' => $result];
        }

        // Check if the method to-be-called exists on the target object - if so, don't check any further
        // injection locations
        $on = $this->getItem();
        if (is_object($on) && (isset($on->$property) || method_exists($on, $property ?? ''))) {
            return [];
        }

        // Check for a presenter-specific override
        $result = $this->processTemplateOverride($property, $this->underlay);
        if (array_key_exists('value', $result)) {
            return ['source' => $result];
        }

        // Then for iterator-specific overrides
        if (array_key_exists($property, SSViewer_Scope::$iteratorProperties)) {
            $source = SSViewer_Scope::$iteratorProperties[$property];
            /** @var TemplateIteratorProvider $implementor */
            $implementor = $source['implementor'];
            if ($this->itemIterator) {
                // Set the current iterator position and total (the object instance is the first item in
                // the callable array)
                $implementor->iteratorProperties(
                    $this->itemIterator->key(),
                    $this->itemIteratorTotal
                );
            } else {
                // If we don't actually have an iterator at the moment, act like a list of length 1
                $implementor->iteratorProperties(0, 1);
            }

            return ($source) ? ['source' => $source] : [];
        }

        // And finally for global overrides
        if (array_key_exists($property, SSViewer_Scope::$globalProperties)) {
            return [
                'source' => SSViewer_Scope::$globalProperties[$property] // get the method call
            ];
        }

        // No value
        return [];
    }

    /**
     * Ensure the value is cast safely
     *
     * @param mixed $value
     * @param array $source
     * @return DBField
     */
    protected function castValue($value, $source)
    {
        // If the value has already been cast, is null, or is a non-string scalar
        if (is_object($value) || is_null($value) || (is_scalar($value) && !is_string($value))) {
            return $value;
        }

        // Get provided or default cast
        $casting = empty($source['casting'])
            ? ViewableData::config()->uninherited('default_cast')
            : $source['casting'];

        return DBField::create_field($casting, $value);
    }

    /**
     * @return array
     */
    protected function getItemStack()
    {
        return $this->itemStack;
    }

    /**
     * @param array $stack
     */
    protected function setItemStack(array $stack)
    {
        $this->itemStack = $stack;
    }

    /**
     * @return int|null
     */
    protected function getUpIndex()
    {
        return $this->upIndex;
    }

    private function convertScalarToDBField(bool|string|float|int $value): DBField
    {
        return match (gettype($value)) {
            'boolean' => DBBoolean::create()->setValue($value),
            'string' => DBText::create()->setValue($value),
            'double' => DBFloat::create()->setValue($value),
            'integer' => DBInt::create()->setValue($value),
        };
    }
}
