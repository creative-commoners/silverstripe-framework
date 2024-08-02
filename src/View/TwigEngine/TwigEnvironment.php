<?php

namespace SilverStripe\View\TwigEngine;

use SilverStripe\View\SSViewer;
use SilverStripe\View\TemplateLayer\TemplateCandidate;
use SilverStripe\View\ThemeResourceLoader;
use Twig\Environment;
use Twig\TemplateWrapper;

class TwigEnvironment extends Environment
{
    /**
     * @inheritDoc
     */
    public function load($name): TemplateWrapper
    {
        if (is_string($name) && !str_ends_with($name, '.twig')) {
            // @TODO  This is really bad... maybe we just want to always throw back to ssviewer?
            // I'm doing it this way for now because I feel like just dropping the parent context
            // is probably a bad idea... though it'll happen to some extend (e.g. twig includes ss includes twig)
            $path = ThemeResourceLoader::inst()->findTemplate(new TemplateCandidate('', $name), SSViewer::get_themes());
            if ($path && !str_ends_with($path, '.twig')) {
                return new TemplateWrapper($this, new GenericTemplate($this, $name, $path));
            }
            // If it couldn't be found from ssviewer, lets see if it's a twig template.
            $name .= '.twig';
        }
        return parent::load($name);
    }
}
