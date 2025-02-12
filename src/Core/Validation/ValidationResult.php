<?php

namespace SilverStripe\Core\Validation;

use InvalidArgumentException;
use SilverStripe\Core\Injector\Injectable;

/**
 * A class that combined as a boolean result with an optional list of error messages.
 * This is used for returning validation results from validators
 *
 * Each message can have a code or field which will uniquely identify that message. However,
 * messages can be stored without a field or message as an "overall" message.
 */
class ValidationResult
{
    use Injectable;

    /**
     * Standard "error" type
     */
    const TYPE_ERROR = 'error';

    /**
     * Standard "good" message type
     */
    const TYPE_GOOD = 'good';

    /**
     * Non-error message type.
     */
    const TYPE_INFO = 'info';

    /**
     * Warning message type
     */
    const TYPE_WARNING = 'warning';

    /**
     * Message type is html
     */
    const CAST_HTML = 'html';

    /**
     * Message type is plain text
     */
    const CAST_TEXT = 'text';

    /**
     * Is the result valid or not.
     * Note that there can be non-error messages in the list.
     */
    protected bool $isValid = true;

    /**
     * List of messages
     */
    protected array $messages = [];

    /**
     * The class of the model being validated.
     */
    private string $modelClass = '';

    /**
     * The record ID of the object being validated.
     */
    private mixed $recordID = null;

    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    public function setModelClass(string $modelClass): static
    {
        $this->modelClass = $modelClass;
        return $this;
    }

    public function getRecordID(): mixed
    {
        return $this->recordID;
    }

    public function setRecordID(mixed $recordID): static
    {
        $this->recordID = $recordID;
        return $this;
    }

    /**
     * Record an error against this validation result,
     *
     * @param string $messageType The type of message: e.g. "bad", "warning", "good", or "required"
     *               Passed as a CSS class to the form, so other values can be used if desired
     * @param string $code A codename for this error. Only one message per codename will be added.
     *               This can be usedful for ensuring no duplicate messages
     * @param string $cast Cast type; One of the CAST_ constant definitions.
     */
    public function addError(
        string $message,
        string $messageType = ValidationResult::TYPE_ERROR,
        string $code = '',
        string $cast = ValidationResult::CAST_TEXT,
    ): static {
        return $this->addFieldError(
            '',
            $message,
            $messageType,
            $code,
            $cast,
        );
    }

    /**
     * Record an error against this validation result,
     *
     * @param string $fieldName The field to link the message to. If omitted; a form-wide message is assumed.
     * @param string $messageType The type of message: e.g. "bad", "warning", "good", or "required"
     *               Passed as a CSS class to the form, so other values can be used if desired
     * @param string $code A codename for this error. Only one message per codename will be added.
     *               This can be usedful for ensuring no duplicate messages
     * @param string $cast Cast type; One of the CAST_ constant definitions.
     */
    public function addFieldError(
        string $fieldName,
        string $message,
        string $messageType = ValidationResult::TYPE_ERROR,
        string $code = '',
        string $cast = ValidationResult::CAST_TEXT,
    ): static {
        $this->isValid = false;
        return $this->addFieldMessage(
            $fieldName,
            $message,
            $messageType,
            $code,
            $cast,
        );
    }

    /**
     * Add a message to this ValidationResult without necessarily marking it as an error
     *
     * @param string $messageType The type of message: e.g. "bad", "warning", "good", or "required"
     *               Passed as a CSS class to the form, so other values can be used if desired
     * @param string $code A codename for this error. Only one message per codename will be added.
     *               This can be usedful for ensuring no duplicate messages
     * @param string $cast Cast type; One of the CAST_ constant definitions.
     */
    public function addMessage(
        string $message,
        string $messageType = ValidationResult::TYPE_ERROR,
        string $code = '',
        string $cast = ValidationResult::CAST_TEXT,
    ): static {
        return $this->addFieldMessage(
            '',
            $message,
            $messageType,
            $code,
            $cast,
        );
    }

    /**
     * Add a message to this ValidationResult without necessarily marking it as an error
     *
     * @param string $fieldName The field to link the message to.  If omitted; a form-wide message is assumed.
     * @param string $messageType The type of message: e.g. "bad", "warning", "good", or "required"
     *               Passed as a CSS class to the form, so other values can be used if desired
     * @param string $code A codename for this error. Only one message per codename will be added.
     *               This can be usedful for ensuring no duplicate messages
     * @param string $cast Cast type; One of the CAST_ constant definitions.
     */
    public function addFieldMessage(
        string $fieldName,
        string $message,
        string $messageType = ValidationResult::TYPE_ERROR,
        string $code = '',
        string $cast = ValidationResult::CAST_TEXT,
    ): static {
        if ($code && is_numeric($code)) {
            throw new InvalidArgumentException("Don't use a numeric code '$code'. Use a non-numeric code instead.");
        }
        $metadata = [
            'message' => $message,
            'fieldName' => $fieldName,
            'messageType' => $messageType,
            'messageCast' => $cast,
            'modelClass' => $this->modelClass,
            'recordID' => $this->recordID,
        ];
        if ($code) {
            $this->messages[$code] = $metadata;
        } else {
            $this->messages[] = $metadata;
        }
        return $this;
    }

    /**
     * Returns true if the result is valid.
     */
    public function isValid(): bool
    {
        return $this->isValid;
    }

    /**
     * Return the full error meta-data, suitable for combining with another ValidationResult.
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Combine this Validation Result with the ValidationResult given in other.
     * It will be valid if both this and the other result are valid.
     * This object will be modified to contain the new validation information.
     */
    public function combineAnd(ValidationResult $other): static
    {
        $this->isValid = $this->isValid && $other->isValid();
        $this->messages = array_merge($this->messages, $other->getMessages());
        $this->combineModelClassAndRecordID($other);
        return $this;
    }

    public function __serialize(): array
    {
        return [
            'messages' => $this->messages,
            'isValid' => $this->isValid()
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->messages = $data['messages'];
        $this->isValid = $data['isValid'];
    }

    /**
     * Combine the model class and record ID from another ValidationResult object.
     */
    private function combineModelClassAndRecordID(ValidationResult $other): void
    {
        $otherModelClass = $other->getModelClass();
        if ($this->getModelClass() === '' && $otherModelClass !== '') {
            $this->setModelClass($otherModelClass);
        }
        $otherRecordID = $other->getRecordID();
        if ($this->getRecordID() === null && $otherRecordID !== null) {
            $this->setRecordID($otherRecordID);
        }
    }
}
