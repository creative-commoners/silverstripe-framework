<?php

namespace SilverStripe\View\TemplateLayer;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Represents a possible candidate in a set of possible templates that could be used to render a piece of data.
 */
class TemplateCandidate implements JsonSerializable
{
    public const TYPE_CONTENT = 'Content';
    public const TYPE_LAYOUT = 'Layout';
    public const TYPE_INCLUDE = 'Includes';
    public const TYPE_ROOT = '';

    public function __construct(private string $type, private string $name)
    {
        if (!$name) {
            throw new InvalidArgumentException('$name must not be an empty string.');
        }

        $allowedTypes = [
            TemplateCandidate::TYPE_CONTENT,
            TemplateCandidate::TYPE_LAYOUT,
            TemplateCandidate::TYPE_INCLUDE,
            TemplateCandidate::TYPE_ROOT
        ];
        if (!in_array($type, $allowedTypes)) {
            throw new InvalidArgumentException('$type must be one of the type constants. Got ' . $type);
        }
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
        ];
    }

    public function __toString(): string
    {
        return json_encode($this);
    }
}
