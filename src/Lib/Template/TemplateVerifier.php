<?php

namespace PP\Lib\Template;

use PP\Lib\Html\Layout\TwigLayout;

/**
 * Golden-master check: renders a template with the legacy Smarty layout
 * and with TwigLayout using identical variables, then compares html.
 *
 * @package PP\Lib\Template
 */
class TemplateVerifier
{
    public const STATUS_OK = 'OK';
    public const STATUS_DIFF = 'DIFF';
    public const STATUS_SMARTY_ERROR = 'SMARTY_ERROR';
    public const STATUS_TWIG_ERROR = 'TWIG_ERROR';
    public const STATUS_BOTH_ERROR = 'BOTH_ERROR';

    /** @var \PXUserHTMLLayout */
    protected $smartyLayout;

    /** @var TwigLayout */
    protected $twigLayout;

    /** @var bool byte-exact comparison instead of whitespace-insensitive */
    protected $strict = false;

    /**
     * @param string[] $templateDirs template roots for both engines
     * @param bool $strict
     */
    public function __construct(array $templateDirs, $strict = false)
    {
        $this->strict = $strict;

        require_once PPLIBPATH . 'HTML/layout.class.inc';

        $this->smartyLayout = new \PXUserHTMLLayout();
        $this->smartyLayout->getSmarty()->template_dir = $templateDirs;

        $this->twigLayout = new TwigLayout($templateDirs);
    }

    /**
     * @return \PXUserHTMLLayout
     */
    public function getSmartyLayout()
    {
        return $this->smartyLayout;
    }

    /**
     * @return TwigLayout
     */
    public function getTwigLayout()
    {
        return $this->twigLayout;
    }

    /**
     * Assigns the same variable to both layouts.
     *
     * @param string $name
     * @param mixed $value
     * @return $this
     */
    public function assign($name, $value)
    {
        $this->smartyLayout->assign($name, $value);
        $this->twigLayout->assign($name, $value);

        return $this;
    }

    /**
     * Fills both layouts with the standard client-side variable set,
     * mirroring PXEngineIndex::fillLayout().
     *
     * @return $this
     */
    public function assignDefaults()
    {
        $app = \PXRegistry::getApp();
        $request = \PXRegistry::getRequest();

        $this->smartyLayout->setApp($app);
        $this->twigLayout->setApp($app);

        $tree = new \PXTreeObjects();
        $objects = new \PXObjects();
        $subObjects = new \PXSubObjects();

        $this->assign('app', $app);
        $this->assign('user', \PXRegistry::getUser());
        $this->assign('request', $request);
        $this->assign('response', \PP\Lib\Http\Response::getInstance());
        $this->assign('tree', $tree);
        $this->assign('objects', $objects);
        $this->assign('subObjects', $subObjects);
        $this->assign('currentSid', -1);
        $this->assign('currentCid', -1);
        $this->assign('currentSCid', -1);
        $this->assign('currentCtype', $objects->getCurrentType());
        $this->assign('pathId', $tree->pathId);
        $this->assign('rootId', $tree->rootId);
        $this->assign('REGEX_MOD', REGEX_MOD);
        $this->assign('DEFAULT_CHARSET', DEFAULT_CHARSET);
        $this->assign('CHARSET_UTF8', CHARSET_UTF8);
        $this->assign('CHARSET_WINDOWS', CHARSET_WINDOWS);

        if ($request !== null) {
            $this->assign('urlFile', $request->getFile());
            $this->assign('requestHost', $request->getHttpHost());
            $this->assign('requestUri', $request->getRequestUri());
            $this->assign('requestReferer', $request->getHttpReferer());
            $this->assign('requestPath', $request->getPathAsString());
            $this->smartyLayout->assignRequest($request);
            $this->twigLayout->assignRequest($request);
        }

        return $this;
    }

    /**
     * Assigns extra variables (e.g. fixture data decoded from json).
     *
     * @param array $vars
     * @return $this
     */
    public function assignVars(array $vars)
    {
        foreach ($vars as $name => $value) {
            $this->assign($name, $value);
        }

        return $this;
    }

    /**
     * @param string $template relative smarty template name (with .tmpl)
     * @return array{status: string, smarty: ?string, twig: ?string, error: ?string, diff: ?string}
     */
    public function verify($template)
    {
        $smartyHtml = null;
        $twigHtml = null;
        $errors = [];

        try {
            $smartyHtml = $this->smartyLayout->html($template);
        } catch (\Throwable $e) {
            $errors['smarty'] = $e->getMessage();
        }

        try {
            $twigHtml = $this->twigLayout->html($template);
        } catch (\Throwable $e) {
            $errors['twig'] = $e->getMessage();
        }

        if (isset($errors['smarty']) && isset($errors['twig'])) {
            return $this->reportRow(self::STATUS_BOTH_ERROR, $smartyHtml, $twigHtml, 'smarty: ' . $errors['smarty'] . '; twig: ' . $errors['twig']);
        }
        if (isset($errors['smarty'])) {
            return $this->reportRow(self::STATUS_SMARTY_ERROR, $smartyHtml, $twigHtml, $errors['smarty']);
        }
        if (isset($errors['twig'])) {
            return $this->reportRow(self::STATUS_TWIG_ERROR, $smartyHtml, $twigHtml, $errors['twig']);
        }

        if ($this->normalize($smartyHtml) === $this->normalize($twigHtml)) {
            return $this->reportRow(self::STATUS_OK, $smartyHtml, $twigHtml, null);
        }

        return $this->reportRow(self::STATUS_DIFF, $smartyHtml, $twigHtml, null);
    }

    /**
     * @param string $status
     * @param string|null $smartyHtml
     * @param string|null $twigHtml
     * @param string|null $error
     * @return array
     */
    protected function reportRow($status, $smartyHtml, $twigHtml, $error)
    {
        $diff = null;

        if ($status === self::STATUS_DIFF) {
            $diff = $this->firstDifference((string)$smartyHtml, (string)$twigHtml);
        }

        return [
            'status' => $status,
            'smarty' => $smartyHtml,
            'twig' => $twigHtml,
            'error' => $error,
            'diff' => $diff,
        ];
    }

    /**
     * Whitespace-insensitive html normalization: twig and smarty treat
     * whitespace around tags differently while html rendering does not care.
     *
     * @param string $html
     * @return string
     */
    protected function normalize($html)
    {
        if ($this->strict) {
            return $html;
        }

        return trim(preg_replace('/\s+/u', ' ', $html));
    }

    /**
     * @param string $a
     * @param string $b
     * @return string short description of the first difference
     */
    protected function firstDifference($a, $b)
    {
        $a = $this->normalize($a);
        $b = $this->normalize($b);

        $max = min(strlen($a), strlen($b));
        $pos = 0;
        while ($pos < $max && $a[$pos] === $b[$pos]) {
            $pos++;
        }

        $from = max(0, $pos - 40);

        return sprintf(
            'at char %d: smarty `...%s` vs twig `...%s`',
            $pos,
            substr($a, $from, 80),
            substr($b, $from, 80)
        );
    }
}
