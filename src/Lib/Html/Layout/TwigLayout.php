<?php

namespace PP\Lib\Html\Layout;

use PP\Lib\Html\Twig\CoreFunctionsExtension;
use PP\Lib\Html\Twig\SmartyCompatExtension;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig-backed client-side layout — the default template engine.
 *
 * A project may swap the engine by defining a public DI service with id
 * `PP\Lib\Html\Layout\LayoutInterface` in services.yml pointing to its
 * own UserLayoutInterface implementation.
 *
 * Templates are looked up in local/templates, then libpp/templates with
 * `.twig` extension; legacy `.tmpl` names requested by the modules are
 * remapped transparently.
 *
 * Class TwigLayout
 * @package PP\Lib\Html\Layout
 */
class TwigLayout implements UserLayoutInterface
{
    /** @var Environment */
    protected $twig;

    /** @var array template variables */
    protected $vars = [];

    /** @var array post-render output filters: list of [callable, args] */
    protected $filters = [];

    /** @var string */
    protected $indexTemplate = 'index.twig';

    /** @var \PXUserHTMLLang */
    protected $lang;

    /** @var string[]|null */
    protected $customDirs;

    /**
     * @param string[]|null $templateDirs override template lookup paths
     */
    public function __construct(array $templateDirs = null)
    {
        $this->customDirs = $templateDirs;

        $loader = new FilesystemLoader(array_filter($this->templateDirs(), 'is_dir'));

        $cacheDir = CACHE_PATH . '/twig_templates_c';
        \MakeDirIfNotExists($cacheDir);

        $this->twig = new Environment($loader, [
            // Smarty 2 neither autoescapes nor complains about unknown vars
            'autoescape' => false,
            'strict_variables' => false,
            'cache' => $cacheDir,
            'auto_reload' => true,
        ]);

        $this->twig->addExtension(new SmartyCompatExtension());
        $this->twig->addExtension(new CoreFunctionsExtension($this));

        // Smarty 2 fell back to plain PHP functions for unknown
        // modifiers ({$var|quot}) and template functions — keep that
        // for templates converted from Smarty.
        $this->twig->registerUndefinedFilterCallback(function ($name) {
            if (!function_exists($name)) {
                return false;
            }

            // functions taking the argument by reference cannot be called
            // with a twig expression value directly
            $byRef = [
                'reset' => static fn ($a) => is_array($a) && $a ? $a[array_key_first($a)] : false,
                'current' => static fn ($a) => is_array($a) && $a ? $a[array_key_first($a)] : false,
                'end' => static fn ($a) => is_array($a) && $a ? $a[array_key_last($a)] : false,
                'key' => static fn ($a) => is_array($a) && $a ? array_key_first($a) : null,
                'array_shift' => static fn ($a) => is_array($a) && $a ? $a[array_key_first($a)] : null,
                'array_pop' => static fn ($a) => is_array($a) && $a ? $a[array_key_last($a)] : null,
            ];

            return new TwigFilter($name, $byRef[$name] ?? $name);
        });
        $this->twig->registerUndefinedFunctionCallback(function ($name) {
            return function_exists($name) ? new TwigFunction($name, $name) : false;
        });

        $this->initLang();
        $this->registerCore();
    }

    /**
     * @return string[]
     */
    protected function templateDirs()
    {
        return $this->customDirs ?? [
            BASEPATH . '/local/templates/',
            BASEPATH . '/libpp/templates/',
        ];
    }

    private function initLang()
    {
        require_once PPLIBPATH . 'HTML/lang.class.inc';

        $this->lang = new \PXUserHTMLLang();
    }

    private function registerCore()
    {
        $this->addTemplateFunction('property', [$this, 'getProperty']);
        $this->addTemplateModifier('property', [$this, 'getPropertyModifier']);

        $this->addTemplateFunction('lang', [$this->lang, 'lang']);
        $this->addTemplateModifier('lang', [$this->lang, 'lang_modifier']);

        $this->addTemplateFunction('pager', [$this, 'pager']);
        $this->addTemplateFunction('autopager', [$this, 'autopager']);

        // dynamic access to layout vars assigned mid-render (legacy
        // plugins mutate template scope; Twig context is a snapshot)
        $this->twig->addFunction(new TwigFunction('layout_var', [$this, 'getVar']));
        $this->twig->addFunction(new TwigFunction('pager_href', [$this, 'buildPagerHref'], ['is_safe' => ['all']]));
    }

    /**
     * {@inheritdoc}
     */
    public function setApp(\PXApplication $app)
    {
        $this->lang->setTree($app->langTree);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setLang($lang = 'rus')
    {
        $this->lang->setLang($lang);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getLang()
    {
        return $this->lang;
    }

    /**
     * @return Environment
     */
    public function getTwig()
    {
        return $this->twig;
    }

    /**
     * {@inheritdoc}
     */
    public function getIndexTemplate()
    {
        return $this->indexTemplate;
    }

    /**
     * {@inheritdoc}
     */
    public function setContent($content)
    {
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getContent()
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function setDebug($value)
    {
        if ($value) {
            $this->twig->enableDebug();
            if (!$this->twig->hasExtension(DebugExtension::class)) {
                $this->twig->addExtension(new DebugExtension());
            }
        } else {
            $this->twig->disableDebug();
        }

        return $this;
    }

    /**
     * TODO: remove byReference flag to conform LayoutInterface
     * {@inheritdoc}
     */
    public function assign($varName, $variable, $byReference = false)
    {
        $this->vars[$varName] = $variable;

        return $this;
    }

    public function assignByRef($varName, &$variable)
    {
        $this->vars[$varName] = &$variable;
    }

    /**
     * {@inheritdoc}
     */
    public function assignArray($variablesArray, $byReference = false)
    {
        foreach ($variablesArray as $k => $v) {
            $this->assign($k, $v);
        }
    }

    /**
     * Exposes request data under the `smarty` template variable, so
     * converted `$smarty.get.*` / `$smarty.post.*` / `$smarty.cookies.*`
     * lookups keep working.
     *
     * {@inheritdoc}
     */
    public function assignRequest($request)
    {
        // smarty 2 compiles $smarty.get straight to $_GET — mirror that
        // for byte-identical rendering of converted templates
        $this->vars['smarty'] = [
            'get' => $_GET,
            'post' => $_POST,
            'cookies' => $_COOKIE,
            'request' => $_REQUEST,
            'server' => $_SERVER,
            'session' => $_SESSION ?? [],
            'env' => $_ENV,
            'now' => time(),
            'version' => defined('PP_VERSION') ? PP_VERSION : '',
        ];
    }

    /**
     * Smarty-compatible template vars getter: legacy plugin callbacks
     * receive the layout as their `&$smarty` argument and may call it.
     *
     * @param string|null $varName
     * @return mixed
     */
    public function get_template_vars($varName = null)
    {
        if ($varName === null) {
            return $this->vars;
        }

        return $this->vars[$varName] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function getVar($varName, $default = null)
    {
        return $this->vars[$varName] ?? $default;
    }

    /**
     * @param string $varName
     * @return mixed
     */
    public function &getVarByRef($varName)
    {
        if (!array_key_exists($varName, $this->vars)) {
            $this->vars[$varName] = null;
        }

        return $this->vars[$varName];
    }

    /**
     * {@inheritdoc}
     */
    public function changeIndexTemplate($filename = 'index.tmpl')
    {
        $this->indexTemplate = $this->resolveName($filename);
    }

    /**
     * Maps legacy `.tmpl` template names to `.twig` ones.
     *
     * @param string $template
     * @return string
     */
    protected function resolveName($template)
    {
        return preg_replace('/\.tmpl$/', '.twig', (string)$template);
    }

    /**
     * {@inheritdoc}
     */
    public function html($template)
    {
        return $this->twig->render($this->resolveName($template), $this->vars);
    }

    /**
     * {@inheritdoc}
     */
    public function display()
    {
        $html = $this->html($this->indexTemplate);

        foreach ($this->filters as [$callback, $args]) {
            $html = call_user_func_array($callback, array_merge([$html, $this], $args));
        }

        return $html;
    }

    /**
     * {@inheritdoc}
     */
    public function addFilter($functionName)
    {
        if (!is_callable($functionName)) {
            return;
        }

        $args = array_slice(func_get_args(), 1);

        foreach ($this->filters as $i => [$callback]) {
            if ($callback === $functionName) {
                $this->filters[$i] = [$functionName, $args];
                return;
            }
        }

        $this->filters[] = [$functionName, $args];
    }

    /**
     * {@inheritdoc}
     */
    public function removeFilter($functionName)
    {
        foreach ($this->filters as $i => [$callback]) {
            if ($callback === $functionName) {
                unset($this->filters[$i]);
            }
        }
    }

    // back-compat. deprecated
    public function addModifier($name, $callback)
    {
        $this->addTemplateModifier($name, $callback);
    }

    /**
     * Registers Smarty-style template function: callback receives
     * ($params, &$smarty); direct output (echo) is captured.
     *
     * {@inheritdoc}
     */
    public function addTemplateFunction($name, $callback)
    {
        $layout = $this;

        $this->twig->addFunction(new TwigFunction(
            $name,
            function (array $params = []) use ($callback, $layout) {
                ob_start();
                $result = $callback($params, $layout);
                $echoed = ob_get_clean();

                return $echoed . ($result ?? '');
            },
            ['is_safe' => ['all']]
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function addTemplateModifier($name, $callback)
    {
        $this->twig->addFilter(new TwigFilter($name, $callback));
    }

    /**
     * Registers Smarty-style block function as a Twig filter, so
     * `{name attr=x}...{/name}` becomes `{% apply name({attr: x}) %}...{% endapply %}`.
     *
     * {@inheritdoc}
     */
    public function addTemplateBlock($name, $callback, $cacheable = true)
    {
        $layout = $this;

        $this->twig->addFilter(new TwigFilter(
            $name,
            function ($content, array $params = []) use ($callback, $layout) {
                $repeat = false;

                return $callback($params, $content, $layout, $repeat);
            },
            ['is_safe' => ['all']]
        ));
    }

    /**
     * Get property inside template, function
     *
     * @param array $param
     * @param object $smarty
     * @return string
     */
    public function getProperty($param, &$smarty)
    {
        $default = '';
        if (isset($param['default'])) {
            $default = $param['default'];
        }

        return \PXRegistry::getApp()->getProperty($param['name'], $default);
    }

    /**
     * Get property inside template, modifier
     *
     * @param string $name
     * @return string
     */
    public function getPropertyModifier($name)
    {
        return \PXRegistry::getApp()->getProperty($name);
    }

    public function getLangVar($path)
    {
        $param = ['get' => $path, 'return' => true];

        return $this->lang->lang($param, $this);
    }

    /**
     * Pagination: same contract as the legacy Smarty-era htmlPager().
     *
     * @param int $totalObjects
     * @param int $objectsPerPage
     * @param object $smarty layout to assign page vars to
     * @param array $param
     * @return string|null
     */
    public function htmlPager($totalObjects, $objectsPerPage, &$smarty, $param)
    {
        $maxPage = ceil($totalObjects / $objectsPerPage);

        $request = \PXRegistry::getRequest();

        $currentPage = $request->GetVar('page', $this->getVar('FP_' . mb_strtoupper((string)$param['format']) . '_DEFAULT_PAGE', 1));
        $currentPage = (int)preg_replace('/[^0-9]+/' . REGEX_MOD, '', (string)$currentPage);
        $currentPage = $currentPage > 0 ? $currentPage : 1;

        if ($currentPage > $maxPage) {
            $currentPage = $maxPage;
        }

        $smarty->assign('a_per_page', $objectsPerPage);
        $smarty->assign('page', $currentPage);
        $smarty->assign('pages', $maxPage);
        $smarty->assign('max_page', $maxPage);

        if (isset($param['notshow'])) {
            return null;
        }

        return $this->html('misc/pager/pages.twig');
    }

    /**
     * `{autopager format=...}` template function.
     *
     * @param array $param
     * @param object $smarty
     * @return string|null
     */
    public function autopager($param, &$smarty)
    {
        $total = $this->getVar('FP_' . mb_strtoupper((string)$param['format']) . '_TOTAL');
        $a_per_page = $this->getVar('FP_' . mb_strtoupper((string)$param['format']) . '_PER_PAGE');

        if (!isset($total) || !isset($a_per_page)) {
            return '';
        }

        return $this->htmlPager($total, $a_per_page, $smarty, $param);
    }

    /**
     * Builds pager base href from current GET params, keeping the html
     * produced by legacy misc/pager/pages.tmpl byte-identical.
     *
     * @param string $pageVar
     * @return string
     */
    public function buildPagerHref($pageVar = 'page')
    {
        $request = \PXRegistry::getRequest();

        $pagehref = '?';
        $hasArgs = false;

        foreach ($request->getAllGetData() as $param => $value) {
            if ($param != $pageVar) {
                $hasArgs = true;
                $pagehref = appendParamToUrl($pagehref, $param, $value, false, true);
            }
        }

        if ($hasArgs) {
            $pagehref .= '&';
        }

        $pagehref = preg_replace('/^.+\?/', '?', $pagehref);

        return htmlspecialchars($pagehref, ENT_QUOTES, DEFAULT_CHARSET);
    }

    /**
     * `{pager objects=... format=...}` template function.
     *
     * @param array $param
     * @param object $smarty
     * @return string|null
     */
    public function pager($param, &$smarty)
    {
        $total = sizeof($param['objects']);

        /* Нужна ли постраничка? */
        $p = mb_strtoupper((string)$param['format']) . '_PER_PAGE';

        $a_per_page = (int)\PXRegistry::getApp()->getProperty($p, $total);
        if ($a_per_page == 0) {
            return '';
        }

        return $this->htmlPager($total, $a_per_page, $smarty, $param);
    }
}
