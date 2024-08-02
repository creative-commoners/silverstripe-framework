<?php

namespace SilverStripe\View;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Control\Director;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use InvalidArgumentException;
use SilverStripe\View\TemplateLayer\RenderingEngine;
use SilverStripe\View\TemplateLayer\SSRenderingEngine;
use SilverStripe\View\TemplateLayer\TemplateCandidate;
use SilverStripe\View\TemplateLayer\ViewLayerData;

/**
 * Parses a template file with an *.ss file extension.
 *
 * In addition to a full template in the templates/ folder, a template in
 * templates/Content or templates/Layout will be rendered into $Content and
 * $Layout, respectively.
 *
 * A single template can be parsed by multiple nested {@link SSViewer} instances
 * through $Layout/$Content placeholders, as well as <% include MyTemplateFile %> template commands.
 *
 * <b>Themes</b>
 *
 * See http://doc.silverstripe.org/themes and http://doc.silverstripe.org/themes:developing
 *
 * <b>Caching</b>
 *
 * Compiled templates are cached via {@link Cache}, usually on the filesystem.
 * If you put ?flush=1 on your URL, it will force the template to be recompiled.
 *
 * @see http://doc.silverstripe.org/themes
 * @see http://doc.silverstripe.org/themes:developing
 */
class SSViewer
{
    use Configurable;
    use Injectable;

    /**
     * Identifier for the default theme
     */
    const DEFAULT_THEME = '$default';

    /**
     * Identifier for the public theme
     */
    const PUBLIC_THEME = '$public';

    /**
     * A list (highest priority first) of themes to use
     * Only used when {@link $theme_enabled} is set to TRUE.
     *
     * @config
     * @var string
     */
    private static $themes = [];

    /**
     * Overridden value of $themes config
     *
     * @var array
     */
    protected static $current_themes = null;

    /**
     * Use the theme. Set to FALSE in order to disable themes,
     * which can be useful for scenarios where theme overrides are temporarily undesired,
     * such as an administrative interface separate from the website theme.
     * It retains the theme settings to be re-enabled, for example when a website content
     * needs to be rendered from within this administrative interface.
     *
     * @config
     * @var bool
     */
    private static $theme_enabled = true;

    /**
     * @config
     * @var bool
     */
    private static $source_file_comments = false;

    /**
     * Set if hash links should be rewritten
     *
     * @config
     * @var bool
     */
    private static $rewrite_hash_links = true;

    /**
     * Overridden value of rewrite_hash_links config
     *
     * @var bool
     */
    protected static $current_rewrite_hash_links = null;

    /**
     * Instance variable to disable rewrite_hash_links (overrides global default)
     * Leave null to use global state.
     *
     * @var bool|null
     */
    protected $rewriteHashlinks = null;

    /**
     * List of templates to select from
     *
     * @var array
     */
    protected $templates = null;

    /**
     * @var bool
     */
    protected $includeRequirements = true;

    /**
     * @param string|array $templates If passed as a string with .ss extension, used as the "main" template.
     *  If passed as an array, it can be used for template inheritance (first found template "wins").
     *  Usually the array values are PHP class names, which directly correlate to template names.
     *  <code>
     *  array('MySpecificPage', 'MyPage', 'Page')
     *  </code>
     */
    public function __construct(string|array $templates)
    {
        if (!is_array($templates)) {
            $templates = [$templates];
        }
        $this->setTemplates($templates);
    }

    public function setTemplates(array $templates): SSViewer
    {
        // We probably eventally want people instantiating TemplateCandidate objects and passing them in,
        // so an array of not-that should be deprecated and the next major after this is introduced we'd
        // enforce it.
        // For now I've just pulled the logic from ThemeResourceLoader::findTemplate()
        $this->templates = $this->buildTemplateCandidateArray($templates);
        return $this;
    }

    private function buildTemplateCandidateArray(array $templates): array
    {
        // Check if templates has type specified
        if (array_key_exists('type', $templates)) {
            $type = $templates['type'];
            unset($templates['type']);
        }
        // Templates are either nested in 'templates' or just the rest of the list
        $templateList = array_key_exists('templates', $templates ?? []) ? $templates['templates'] : $templates;

        $templateSet = [];

        foreach ($templateList as $template) {
            // Check if passed list of templates in array format
            if (is_array($template)) {
                $moreTemplates = $this->buildTemplateCandidateArray($template);
                if (!empty($moreTemplates)) {
                    $templateSet = array_merge($moreTemplates, $templateSet);
                }
                continue;
            }
            $templateSet[] = new TemplateCandidate($type ?? TemplateCandidate::TYPE_ROOT, $template);
        }

        return $templateSet;
    }

    /**
     * Create a template from a string instead of a .ss file
     *
     * @param string $content The template content
     * @param bool|void $cacheTemplate Whether or not to cache the template from string
     * @return SSViewer
     */
    public static function fromString($content, $cacheTemplate = null)
    {
        // @TODO remove this
        $viewer = SSViewer_FromString::create($content);
        if ($cacheTemplate !== null) {
            $viewer->setCacheTemplate($cacheTemplate);
        }
        return $viewer;
    }

    /**
     * Assign the list of active themes to apply.
     * If default themes should be included add $default as the last entry.
     *
     * @param array $themes
     */
    public static function set_themes($themes = [])
    {
        static::$current_themes = $themes;
    }

    /**
     * Add to the list of active themes to apply
     *
     * @param array $themes
     */
    public static function add_themes($themes = [])
    {
        $currentThemes = SSViewer::get_themes();
        $finalThemes = array_merge($themes, $currentThemes);
        // array_values is used to ensure sequential array keys as array_unique can leave gaps
        static::set_themes(array_values(array_unique($finalThemes ?? [])));
    }

    /**
     * Get the list of active themes
     *
     * @return array
     */
    public static function get_themes()
    {
        $default = [SSViewer::PUBLIC_THEME, SSViewer::DEFAULT_THEME];

        if (!SSViewer::config()->uninherited('theme_enabled')) {
            return $default;
        }

        // Explicit list is assigned
        $themes = static::$current_themes;
        if (!isset($themes)) {
            $themes = SSViewer::config()->uninherited('themes');
        }
        if ($themes) {
            return $themes;
        }

        return $default;
    }

    public static function hasTemplate(TemplateCandidate|string $template): bool
    {
        $candidate = $template instanceof TemplateCandidate
            ? $template
            : new TemplateCandidate(TemplateCandidate::TYPE_ROOT, $template);
        $engineClasses = ClassInfo::implementorsOf(RenderingEngine::class);
        foreach ($engineClasses as $engineClass) {
            if ($engineClass::hasTemplate($candidate)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Traverses the given the given class context looking for candidate template names
     * which match each item in the class hierarchy. The resulting list of template candidates
     * may or may not exist, but you can invoke {@see SSViewer::chooseTemplate} on any list
     * to determine the best candidate based on the current themes.
     *
     * @param string|object $classOrObject Valid class name, or object
     * @param string $suffix
     * @param string $baseClass Class to halt ancestry search at
     * @return array
     */
    public static function get_templates_by_class($classOrObject, $suffix = '', $baseClass = null)
    {
        // Figure out the class name from the supplied context.
        if (!is_object($classOrObject) && !(
            is_string($classOrObject) && class_exists($classOrObject ?? '')
        )) {
            throw new InvalidArgumentException(
                'SSViewer::get_templates_by_class() expects a valid class name as its first parameter.'
            );
        }

        $templates = [];
        $classes = array_reverse(ClassInfo::ancestry($classOrObject) ?? []);
        foreach ($classes as $class) {
            $template = $class . $suffix;
            $templates[] = $template;
            $templates[] = ['type' => 'Includes', $template];

            // If the class is "PageController" (PSR-2 compatibility) or "Page_Controller" (legacy), look for Page.ss
            if (preg_match('/^(?<name>.+[^\\\\])_?Controller$/iU', $class ?? '', $matches)) {
                $templates[] = $matches['name'] . $suffix;
            }

            if ($baseClass && $class == $baseClass) {
                break;
            }
        }

        return $templates;
    }

    /**
     * Check if rewrite hash links are enabled on this instance
     *
     * @return bool
     */
    public function getRewriteHashLinks()
    {
        if (isset($this->rewriteHashlinks)) {
            return $this->rewriteHashlinks;
        }
        return static::getRewriteHashLinksDefault();
    }

    /**
     * Set if hash links are rewritten for this instance
     *
     * @param bool $rewrite
     * @return $this
     */
    public function setRewriteHashLinks($rewrite)
    {
        $this->rewriteHashlinks = $rewrite;
        return $this;
    }

    /**
     * Get default value for rewrite hash links for all modules
     *
     * @return bool
     */
    public static function getRewriteHashLinksDefault()
    {
        // Check if config overridden
        if (isset(static::$current_rewrite_hash_links)) {
            return static::$current_rewrite_hash_links;
        }
        return Config::inst()->get(static::class, 'rewrite_hash_links');
    }

    /**
     * Set default rewrite hash links
     *
     * @param bool $rewrite
     */
    public static function setRewriteHashLinksDefault($rewrite)
    {
        static::$current_rewrite_hash_links = $rewrite;
    }

    /**
     * Call this to disable rewriting of <a href="#xxx"> links.  This is useful in Ajax applications.
     * It returns the SSViewer objects, so that you can call new SSViewer("X")->dontRewriteHashlinks()->process();
     *
     * @return $this
     */
    public function dontRewriteHashlinks()
    {
        return $this->setRewriteHashLinks(false);
    }

    /**
     * Flag whether to include the requirements in this response.
     *
     * @param bool $incl
     */
    public function includeRequirements($incl = true)
    {
        $this->includeRequirements = $incl;
    }

    /**
     * The process() method handles the "meat" of the template processing.
     *
     * It takes care of caching the output (via {@link Cache}), as well as
     * replacing the special "$Content" and "$Layout" placeholders with their
     * respective subtemplates.
     *
     * The method injects extra HTML in the header via {@link Requirements::includeInHTML()}.
     *
     * Note: You can call this method indirectly by {@link ViewableData->renderWith()}.
     *
     * @param ViewableData $item
     * @param array $arguments Arguments to an included template
     * @param SSViewer_Scope? $inheritedScope The current scope of a parent template including a sub-template
     */
    public function process($item, array $arguments = [], $inheritedScope = null): DBHTMLText
    {
        // Set hashlinks and temporarily modify global state
        $rewrite = $this->getRewriteHashLinks();
        $origRewriteDefault = static::getRewriteHashLinksDefault();
        static::setRewriteHashLinksDefault($rewrite);

        // Render the item, using the engine which has the heighest priority and actually has a template to render.
        // @TODO provide a "priority" numeric value for each engine and sort them accordingly.
        $output = '';
        $foundEngine = false;
        $engineClasses = ClassInfo::implementorsOf(RenderingEngine::class);
        foreach ($this->templates as $template) {
            if ($foundEngine) {
                break;
            }
            foreach ($engineClasses as $engineClass) {
                if ($engineClass::hasTemplate($template)) {
                    $engine = Injector::inst()->create($engineClass, $template);
                    $data = ($item instanceof ViewLayerData) ? $item : new ViewLayerData($item);
                    $output = $engine->process($data, $arguments, $inheritedScope);
                    $foundEngine = true;
                    break;
                }
            }
        }

        // Give error (when not in live mode) if we can't find the relevant template.
        if (!$foundEngine) {
            $message = 'None of the following templates could be found: ';
            foreach ($this->templates as $template) {
                $message .= $template;
            }
            $themes = SSViewer::get_themes();
            if (!$themes) {
                $message .= ' (no theme in use)';
            } else {
                $message .= ' in themes "' . print_r($themes, true) . '"';
            }
            user_error($message ?? '', E_USER_WARNING);
        }

        if ($this->includeRequirements) {
            $output = Requirements::includeInHTML($output);
        }

        if ($engineClass === SSRenderingEngine::class) {
            array_pop(SSRenderingEngine::$topLevel); // @TODO move to SSRenderingEngine
        }

        // If we have our crazy base tag, then fix # links referencing the current page.
        // Currently the twig bridge doesn't have a <% base_tag %> equivalent so we might want to consider that.
        if ($rewrite) {
            if (strpos($output ?? '', '<base') !== false) {
                if ($rewrite === 'php') {
                    $thisURLRelativeToBase = <<<PHP
<?php echo \\SilverStripe\\Core\\Convert::raw2att(preg_replace("/^(\\\\/)+/", "/", \$_SERVER['REQUEST_URI'])); ?>
PHP;
                } else {
                    $thisURLRelativeToBase = Convert::raw2att(preg_replace("/^(\\/)+/", "/", $_SERVER['REQUEST_URI'] ?? ''));
                }

                $output = preg_replace('/(<a[^>]+href *= *)"#/i', '\\1"' . $thisURLRelativeToBase . '#', $output ?? '');
            }
        }

        /** @var DBHTMLText $html */
        $html = DBField::create_field('HTMLFragment', $output);

        // Reset global state
        static::setRewriteHashLinksDefault($origRewriteDefault);
        return $html;
    }

    /**
     * Execute the given template, passing it the given data.
     * Use this to render subtemplates from a custom rendering engine, to ensure
     * templates can be overridden with different syntaxes.
     *
     * @param string $template Template name
     * @param mixed $data Data context
     * @param array $arguments Additional arguments
     * @param Object $scope
     * @param bool $globalRequirements
     *
     * @return string Evaluated result
     */
    public static function execute_template($template, $data, $arguments = [], $scope = null, $globalRequirements = false)
    {
        $v = SSViewer::create($template);

        if ($globalRequirements) {
            $v->includeRequirements(false);
        } else {
            //nest a requirements backend for our template rendering
            $origBackend = Requirements::backend();
            Requirements::set_backend(Requirements_Backend::create());
        }
        try {
            return $v->process($data, $arguments, $scope);
        } finally {
            if (!$globalRequirements) {
                Requirements::set_backend($origBackend);
            }
        }
    }

    /**
     * Execute the evaluated string, passing it the given data.
     * Used by partial caching to evaluate custom cache keys expressed using
     * template expressions
     *
     * @param string $content Input string
     * @param mixed $data Data context
     * @param array $arguments Additional arguments
     * @param bool $globalRequirements
     *
     * @return string Evaluated result
     */
    public static function execute_string($content, $data, $arguments = [], $globalRequirements = false)
    {
        // @TODO This doesn't work anymore. Provide a `processString` or similar method on RenderingEngine instead.
        $v = SSViewer::fromString($content);

        if ($globalRequirements) {
            $v->includeRequirements(false);
        } else {
            //nest a requirements backend for our template rendering
            $origBackend = Requirements::backend();
            Requirements::set_backend(Requirements_Backend::create());
        }
        try {
            return $v->process($data, $arguments);
        } finally {
            if (!$globalRequirements) {
                Requirements::set_backend($origBackend);
            }
        }
    }

    /**
     * Return an appropriate base tag for the given template.
     * It will be closed on an XHTML document, and unclosed on an HTML document.
     *
     * @param string $contentGeneratedSoFar The content of the template generated so far; it should contain
     * the DOCTYPE declaration.
     * @return string
     * @todo make this usable in twig somehow too.
     */
    public static function get_base_tag($contentGeneratedSoFar)
    {
        // Base href should always have a trailing slash
        $base = rtrim(Director::absoluteBaseURL(), '/') . '/';

        // Is the document XHTML?
        if (preg_match('/<!DOCTYPE[^>]+xhtml/i', $contentGeneratedSoFar ?? '')) {
            return "<base href=\"$base\" />";
        } else {
            return "<base href=\"$base\"><!--[if lte IE 6]></base><![endif]-->";
        }
    }
}
