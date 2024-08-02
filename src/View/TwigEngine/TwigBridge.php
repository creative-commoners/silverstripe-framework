<?php

namespace SilverStripe\View\TwigEngine;

use ArgumentCountError;
use SilverStripe\i18n\i18n;
use SilverStripe\View\SSViewer;
use SilverStripe\View\SSViewer_Scope;
use SilverStripe\View\TemplateLayer\RenderingEngine;
use SilverStripe\View\TemplateLayer\ViewLayerData;
use SilverStripe\View\TemplateGlobalProvider;
use SilverStripe\View\TemplateLayer\TemplateCandidate;
use SilverStripe\View\ThemeResourceLoader;
use Symfony\Component\Filesystem\Path;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Twig\TwigFunction;

class TwigBridge implements RenderingEngine
{
    // Right now just a string, which is the name of the template minus the extension.
    //
    // TODO:
    // 1. Figure out a nice data format (maybe even a class with properties) for what
    // is currently an array syntax indicating the "type" of template, with fallbacks.
    // 2. In setTemplate use that new data format to boil down to a single template,
    // similar to SSViewer's $chosen property.
    private string $template;

    private Environment $twig;

    private static ?LoaderInterface $templateLoader = null;

    public function __construct(TemplateCandidate|array $template = [])
    {
        $this->setTemplate($template);
        $this->twig = $this->getEngine();
    }

    public static function hasTemplate(array|TemplateCandidate $template): bool
    {
        $templateList = is_array($template) ? $template : [$template];
        /** @var TemplateCandidate $candidate */
        foreach ($templateList as $candidate) {
            $name = $candidate->getName();
            if (!str_ends_with($name, '.twig')) {
                $name .= '.twig';
            }
            // @TODO might need to handle absolute or relative paths that already include the type?
            return TwigBridge::getLoader()->exists(Path::join($candidate->getType(), $name));
        }
    }

    public function process(ViewLayerData $item, array $arguments = [], $inheritedScope = null): string
    {
        if (!empty($arguments)) {
            $item = $item->customise($arguments);
        }

        return $this->twig->render($this->template, [
            // We'd probably wrap it before it even comes to the bridge - but for now this is simpler.
            'model' => $item,
        ]);
    }

    public function setTemplate(TemplateCandidate|array $template): void
    {
        // @TODO Handle arrays properly
        if (is_array($template)) {
            $template = $template[array_key_first($template)];
        }
        $name = $template->getName();
        if (!str_ends_with($name, '.twig')) {
            $name .= '.twig';
        }
        // @TODO might need to handle absolute or relative paths that already include the type?
        $this->template = Path::join($template->getType(), $name);
    }

    private function getEngine()
    {
        $twig = new TwigEnvironment(TwigBridge::getLoader(), [
            'cache' => Path::join(TEMP_PATH, 'twig'), // TODO: Bust cache on flush
            'auto_reload' => true, // could be configurable, might want false in prod
            'autoescape' => false,
            // could add debug as configurable option
        ]);

        // Everything in this method below this line should arguably be done in a twig extension.
        // Failure to do so could result in stale compiled templates after deployment if devs deploy but don't flush.
        // see https://twig.symfony.com/doc/3.x/advanced.html#extending-twig for more details

        // This would obviously be refactored out to somewhere more sensible.
        // Template globals are available both as functions and global properties.
        $dataPresenter = new SSViewer_Scope(null);
        $globalStuff = $dataPresenter->getPropertiesFromProvider(
            TemplateGlobalProvider::class,
            'get_template_global_variables'
        );
        foreach ($globalStuff as $key => $stuff) {
            $twig->addFunction(new TwigFunction($key, $stuff['callable']));
            try {
                $twig->addGlobal($key, call_user_func($stuff['callable']));
            } catch (ArgumentCountError) {
                // This means it needs arguments, which means it's not a "global" in this sense.
                // no-op, twig will just ignore if you try to use it as a property in a template.
            }
        }

        // Use like `{% require javascript('my-script') %}` which maps nicely to the `<% require javascript('my-script') %>` from ss templates.
        // Could alternatively pass the requirements backend as an object in the context and use like `{{ require.javascript('my-script') }}`
        // Could alternatively pass all of the static methods on the Requirements class as global functions like `{{ javascript('my-script') }}`
        $twig->addTokenParser(new RequireTokenParser());

        // Use like `{{ _t('My.Translation.Key', 'Default {var} goes here', {'var': 'string'}) }}`
        // Could alternatively use a token parser like with requirements above, which would allow for a syntax that more closely maps
        // to how localisation is done in ss templates, e.g: `{% t My.Translation.Key 'Default {var} goes here' var='string' %}`
        // but personally I don't like that syntax and that's more work, so I've done the simple thing for now.
        // Note that as per https://twig.symfony.com/doc/3.x/advanced.html#extending-twig `{{ }}` is for printing results of expressions, while `{% %}` is for executing statements
        // and therefore the `{{ }}` braces are more appropriate and therefore using a function is probably best.
        $twig->addFunction(new TwigFunction('_t', [i18n::class, '_t'], ['is_safe' => false]));

        return $twig;
    }

    private static function getLoader(): LoaderInterface
    {
        if (static::$templateLoader === null) {
            static::$templateLoader = new FilesystemLoader(TwigBridge::getTemplateDirs());
        }
        return static::$templateLoader;
    }

    private static function getTemplateDirs()
    {
        $themePaths = ThemeResourceLoader::inst()->getThemePaths(SSViewer::get_themes());
        $dirs = [];
        foreach ($themePaths as $themePath) {
            // Undecided right now if we shuld have `templates/ss/` and `templates/twig/` but for now
            // we just have `templates/` and the .ss and .twig live as sibling files.
            $pathParts = [ BASE_PATH, $themePath, 'templates' ];
            $path = Path::join(...$pathParts);
            if (is_dir($path ?? '')) {
                $dirs[] = $path;
            }
        }
        return $dirs;
    }
}
