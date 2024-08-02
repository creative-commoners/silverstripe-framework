<?php

namespace SilverStripe\View\TwigEngine;

use SilverStripe\View\Requirements;
use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Node;

#[YieldReady]
class RequireNode extends Node
{
    private string $method;

    private array $args;

    public function __construct(int $lineno, string $method, array $args, string $tag)
    {
        parent::__construct(lineno: $lineno, tag: $tag);
        $this->method = $method;
        $this->args = $args;
    }

    /**
     * @inheritDoc
     */
    public function compile(Compiler $compiler): void
    {
        $requirementsClass = Requirements::class;
        $args = $this->getArgsString();
        $compiler
            ->write("{$requirementsClass}::{$this->method}($args);\n")
        ;
    }

    private function getArgsString(): string
    {
        $args = [];
        foreach ($this->args as $argInfo) {
            if ($argInfo['isString']) {
                $args[] = "'{$argInfo['value']}'";
            } else {
                $args[] = $argInfo['value'];
            }
        }
        return implode(', ', $args);
    }
}
