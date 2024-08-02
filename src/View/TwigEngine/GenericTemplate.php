<?php

namespace SilverStripe\View\TwigEngine;

use SilverStripe\View\SSViewer;
use Twig\Environment;
use Twig\Source;
use Twig\Template;

class GenericTemplate extends Template
{
    public function __construct(Environment $env, private string $templateName, private string $templatePath)
    {
        parent::__construct($env);
    }

    public function getTemplateName()
    {
        return $this->templateName;
    }

    public function getDebugInfo()
    {
        return [];
    }

    public function getSourceContext()
    {
        // Hopefully this doesn't break stuff
        return new Source('', $this->templateName, $this->templatePath);
    }

    public function render(array $context): string
    {
        // Hopefully nobody adds more to the context...
        return SSViewer::execute_template($this->templatePath, $context['model'], globalRequirements: true);
    }

    protected function doDisplay(array $context, array $blocks = [])
    {
        // Here's hoping no blocks are ever passed in lol
        yield $this->render($context);
    }

}
