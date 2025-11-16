<?php

namespace SilverStripe\Forms;

/**
 * Interface that allows non-CompositeField implementations to surface their child fields
 * for handling requests and getting/setting data.
 */
interface ChildFieldManager
{
    /**
     * Returns true if this field manages a child field with the given name.
     *
     * If this form field manages fields which may handle actions, and this is not a subclass of
     * CompositeField, this allows FormRequestHandler to find child fields to handle requests.
     *
     * If this method returns true the field must be accessible via getManagedFieldByName()
     * and be returned in getManagedFields().
     */
    public function isManagedField(string $fieldName): bool;

    /**
     * Returns a named field which is managed by this parent field.
     */
    public function getManagedFieldByName(string $fieldName): ?FormField;

    /**
     * Returns a flat iterable of all fields managed by this parent field.
     * @return iterable<FormField>
     */
    public function getManagedFields(): iterable;
}
