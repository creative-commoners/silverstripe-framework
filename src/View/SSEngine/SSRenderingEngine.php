<?php

namespace SilverStripe\View\TemplateLayer;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Security\Permission;
use SilverStripe\View\SSTemplateParser;
use SilverStripe\View\SSViewer;
use SilverStripe\View\SSViewer_Scope;
use SilverStripe\View\TemplateLayer\RenderingEngine;
use SilverStripe\View\ThemeResourceLoader;

class SSRenderingEngine implements RenderingEngine, Flushable
{
    use Configurable;

    /**
     * Default prepended cache key for partial caching
     */
    private static string $global_key = '$CurrentReadingMode, $CurrentUser.ID';

    /**
     * List of items being processed
     * @todo make private
     */
    public static array $topLevel = [];

    /**
     * List of templates to select from
     */
    private TemplateCandidate $templates;

    /**
     * Absolute path to chosen template file
     */
    private ?string $chosen = null;

    /**
     * Templates to use when looking up 'Layout' or 'Content'
     */
    private array $subTemplates = [];

    private SSTemplateParser $parser;

    /**
     * @internal
     */
    private static bool $template_cache_flushed = false;

    /**
     * @internal
     */
    private static bool $cacheblock_cache_flushed = false;

    public function __construct(TemplateCandidate $template)
    {
        $this->setTemplate($template);
        $this->parser = Injector::inst()->get(SSTemplateParser::class);
    }

    /**
     * @inheritDoc
     */
    public static function flush(): void
    {
        SSRenderingEngine::flush_template_cache(true);
        SSRenderingEngine::flush_cacheblock_cache(true);
    }

    /**
     * Find the template to use for a given list
     *
     * @param array $templates
     * @return string
     */
    public static function chooseTemplate(TemplateCandidate $template)
    {
        // We'll want to abstract that out to the SSEngine dir/module too - doesn't make sense
        // to have a "findTemplate()" method in framework that only finds ss templates if the
        // point is to move this stuff out of framework.
        return ThemeResourceLoader::inst()->findTemplate($template, SSViewer::get_themes());
    }

    /**
     * @inheritDoc
     */
    public static function hasTemplate(TemplateCandidate $template): bool
    {
        return SSRenderingEngine::chooseTemplate($template) !== null;
    }

    /**
     * Get the current item being processed
     */
    public static function topLevel(): ?ViewLayerData
    {
        if (SSRenderingEngine::$topLevel) {
            return SSRenderingEngine::$topLevel[sizeof(SSRenderingEngine::$topLevel)-1];
        }
        return null;
    }

    public function setTemplate(TemplateCandidate $template)
    {
        $this->templates = $template;
        $this->chosen = $this->chooseTemplate($template);
        $this->subTemplates = [];
    }

    /**
     * @inheritDoc
     */
    public function process(ViewLayerData $item, array $arguments = [], $inheritedScope = null): string
    {
        SSRenderingEngine::$topLevel[] = $item;

        $template = $this->chosen;

        $cacheFile = TEMP_PATH . DIRECTORY_SEPARATOR . '.cache'
            . str_replace(['\\','/',':'], '.', Director::makeRelative(realpath($template ?? '')) ?? '');
        $lastEdited = filemtime($template ?? '');

        if (!file_exists($cacheFile ?? '') || filemtime($cacheFile ?? '') < $lastEdited) {
            $content = file_get_contents($template ?? '');
            $content = $this->parseTemplateContent($content, $template);

            $fh = fopen($cacheFile ?? '', 'w');
            fwrite($fh, $content ?? '');
            fclose($fh);
        }

        $underlay = ['I18NNamespace' => basename($template ?? '')];

        // Makes the rendered sub-templates available on the parent item,
        // through $Content and $Layout placeholders.
        foreach (['Content', 'Layout'] as $subtemplate) {
            // Detect sub-template to use
            $sub = $this->getSubtemplateFor($subtemplate);
            if (!$sub) {
                continue;
            }

            // Create lazy-evaluated underlay for this subtemplate
            $underlay[$subtemplate] = function () use ($item, $arguments, $sub, $subtemplate) {
                $subtemplateViewer = clone $this;
                // Select the right template
                $subtemplateViewer->setTemplate($sub);

                // Render if available
                if ($subtemplateViewer->exists()) {
                    return DBField::create_field('HTMLText', $subtemplateViewer->process($item, $arguments), $subtemplate);
                }
                return null;
            };
        }

        return $this->includeGeneratedTemplate($cacheFile, $item, $arguments, $underlay, $inheritedScope);
    }


    /**
     * Clears all parsed template files in the cache folder.
     *
     * Can only be called once per request (there may be multiple SSViewer instances).
     *
     * @param bool $force Set this to true to force a re-flush. If left to false, flushing
     * may only be performed once a request.
     */
    private static function flush_template_cache($force = false): void
    {
        if (!SSRenderingEngine::$template_cache_flushed || $force) {
            $dir = dir(TEMP_PATH);
            while (false !== ($file = $dir->read())) {
                if (strstr($file ?? '', '.cache')) {
                    unlink(TEMP_PATH . DIRECTORY_SEPARATOR . $file);
                }
            }
            SSRenderingEngine::$template_cache_flushed = true;
        }
    }

    /**
     * Clears all partial cache blocks.
     *
     * Can only be called once per request (there may be multiple SSViewer instances).
     *
     * @param bool $force Set this to true to force a re-flush. If left to false, flushing
     * may only be performed once a request.
     */
    private static function flush_cacheblock_cache($force = false): void
    {
        if (!SSRenderingEngine::$cacheblock_cache_flushed || $force) {
            $cache = Injector::inst()->get(CacheInterface::class . '.cacheblock');
            $cache->clear();


            SSRenderingEngine::$cacheblock_cache_flushed = true;
        }
    }

    private function exists(): bool
    {
        return (bool) $this->chosen;
    }

    /**
     * Get the cache object to use when storing / retrieving partial cache blocks.
     */
    private function getPartialCacheStore(): CacheInterface
    {
        return Injector::inst()->get(CacheInterface::class . '.cacheblock');
    }

    /**
     * Get the appropriate template to use for the named sub-template, or null if none are appropriate
     *
     * @param string $subtemplate Sub-template to use
     */
    private function getSubtemplateFor(string $subtemplate): ?templateCandidate
    {
        // Get explicit subtemplate name
        if (isset($this->subTemplates[$subtemplate])) {
            return $this->subTemplates[$subtemplate];
        }

        // // Don't apply sub-templates if type is already specified (e.g. 'Includes')
        // // @TODO fix this
        // if (isset($this->templates['type'])) {
        //     return null;
        // }

        // // Filter out any other typed templates as we can only add, not change type
        // $templates = array_filter(
        //     (array)$this->templates,
        //     function ($template) {
        //         return $template->getType() !== TemplateCandidate::TYPE_ROOT;
        //     }
        // );
        // if (empty($templates)) {
        //     return null;
        // }

        // $candidates = [];
        // foreach ($this->templates as $candidate) {
        //     $candidates[] = new TemplateCandidate($subtemplate, $candidate->getName());
        // }

        return new TemplateCandidate($subtemplate, $this->templates->getName());
    }

    /**
     * An internal utility function to set up variables in preparation for including a compiled
     * template, then do the include
     *
     * Effectively this is the common code that both SSViewer#process and SSViewer_FromString#process call
     *
     * @param string $cacheFile The path to the file that contains the template compiled to PHP
     * @param ViewLayerData $item The item to use as the root scope for the template
     * @param array $overlay Any variables to layer on top of the scope
     * @param array $underlay Any variables to layer underneath the scope
     * @param SSViewer_Scope|null $inheritedScope The current scope of a parent template including a sub-template
     */
    private function includeGeneratedTemplate(string $cacheFile, ViewLayerData $item, array $overlay, array $underlay, ?SSViewer_Scope $inheritedScope = null): string
    {
        if (isset($_GET['showtemplate']) && $_GET['showtemplate'] && Permission::check('ADMIN')) {
            $lines = file($cacheFile ?? '');
            echo "<h2>Template: $cacheFile</h2>";
            echo "<pre>";
            foreach ($lines as $num => $line) {
                echo str_pad($num+1, 5) . htmlentities($line, ENT_COMPAT, 'UTF-8');
            }
            echo "</pre>";
        }

        $cache = $this->getPartialCacheStore();
        $scope = new SSViewer_Scope($item, $overlay, $underlay, $inheritedScope);
        $val = '';

        // Placeholder for values exposed to $cacheFile
        [$cache, $scope, $val];
        include($cacheFile);

        return $val;
    }

    /**
     * Parse given template contents
     *
     * @param string $content The template contents
     * @param string $template The template file name
     * @return string
     */
    private function parseTemplateContent($content, $template = "")
    {
        return $this->parser->compileString(
            $content,
            $template,
            Director::isDev() && SSViewer::config()->uninherited('source_file_comments')
        );
    }
}
