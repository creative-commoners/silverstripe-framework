<?php

namespace SilverStripe\View\TemplateLayer;

/**
 * Represents a rendering engine such as twig or ss templates.
 * The constructor must take a template, which it will use in the process method.
 */
interface RenderingEngine
{

    /**
     * Check if this rendering engine has a template with that name that it can use.
     */
    public static function hasTemplate(TemplateCandidate $template): bool;

    /**
     * Fully render the template using this item as the model.
     *
     * Doesn't include inserting js/css from Requirements API.
     * Doesn't include hash rewriting.
     * Those are both handled by SSViewer.
     *
     * Not sure yet what "$arguments" does.
     *
     * NOTE: inheritedScope is only there so I can avoid figuring out how to deal with that lol
     * We'll remove that eventually, when it's not needed by ss templates anymore.
     */
    public function process(ViewLayerData $item, array $arguments = [], $inheritedScope = null): string;
}
