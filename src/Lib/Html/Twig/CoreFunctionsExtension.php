<?php

namespace PP\Lib\Html\Twig;

use PP\Lib\Html\Layout\UserLayoutInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Core template functions historically shipped as engine plugins
 * (createpath, img, html_import, jquery, assets_apply, date_to_time).
 * Registered by TwigLayout out of the box.
 *
 * @package PP\Lib\Html\Twig
 */
class CoreFunctionsExtension extends AbstractExtension
{
    /** @var UserLayoutInterface */
    protected $layout;

    public function __construct(UserLayoutInterface $layout)
    {
        $this->layout = $layout;
    }

    /**
     * {@inheritdoc}
     */
    public function getFunctions()
    {
        return [
            new TwigFunction('createpath', [$this, 'createpath'], ['is_safe' => ['all']]),
            new TwigFunction('img', [$this, 'img'], ['is_safe' => ['all']]),
            new TwigFunction('html_import', [$this, 'htmlImport'], ['is_safe' => ['all']]),
            new TwigFunction('jquery', [$this, 'jquery'], ['is_safe' => ['all']]),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getFilters()
    {
        return [
            new TwigFilter('date_to_time', [$this, 'dateToTime']),
            new TwigFilter('assets_apply', [$this, 'assetsApply'], ['is_safe' => ['all']]),
        ];
    }

    /**
     * `{{ createpath({tree: ..., id: ..., assign: 'var'}) }}`
     *
     * @param array $params
     * @return string
     */
    public function createpath(array $params = [])
    {
        if (empty($params['tree']) || empty($params['id'])) {
            return '';
        }

        $path = createPathByParentId($params['tree'], $params['id']);

        if (empty($params['assign'])) {
            return $path;
        }

        $this->layout->assign($params['assign'], $path);

        return '';
    }

    /**
     * `{{ img({...}) }}` — builds an <img> tag.
     *
     * @param array $params
     * @return string
     */
    public function img(array $params = [])
    {
        return \PXHtmlImageTag::getInstance()->buildTag($params);
    }

    /**
     * `{{ html_import({tag: ..., src: ...}) }}` — style/script inclusion
     * with asset id modifier to manipulate client caching.
     *
     * @param array $params
     * @return string
     */
    public function htmlImport(array $params = [])
    {
        return \PXHTMLAssets::getInstance()->import($params);
    }

    /**
     * `{{ jquery({v: ..., provider: ..., dev: ...}) }}` — JS-tag loading
     * jQuery from a popular AJAX CDN with desired version.
     *
     * @param array $params
     * @return string
     */
    public function jquery(array $params = [])
    {
        $providers = [
            'microsoft' => '//ajax.aspnetcdn.com/ajax/jquery/jquery-%s.%sjs',
            'google' => '//ajax.googleapis.com/ajax/libs/jquery/%s/jquery.%sjs',
            'yandex' => '//yandex.st/jquery/%s/jquery.%sjs',
        ];

        if (empty($params['v']) || $params['v'] == '1.6') {
            // load latest 1.x.x
            $params['v'] = '1.6.1';
        }

        $min = (empty($params['dev']) ? 'min.' : '');
        $provider = $params['provider'] ?? '';
        if (!array_key_exists($provider, $providers)) {
            $provider = 'yandex';
        }

        $src = sprintf($providers[$provider], $params['v'], $min);

        return '<script type="text/javascript" src="' . $src . '"></script>' . "\n";
    }

    /**
     * `{{ date|date_to_time }}` — 'today', 'month' or 'dd.mm.yyyy hh:mm:ss'
     * to unix timestamp.
     *
     * @param mixed $string
     * @return int
     */
    public function dateToTime($string)
    {
        if ($string == 'today') {
            return mktime(0, 0, 0);
        }

        if ($string == 'month') {
            return mktime(0, 0, 0, date('n'), 1);
        }

        if ($string != '') {
            if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}):(\d{2}):(\d{2})$/si' . REGEX_MOD, trim((string)$string), $date)) {
                return time();
            }

            return mktime($date[4], $date[5], $date[6], $date[2], $date[1], $date[3]);
        }

        return time();
    }

    /**
     * `{% apply assets_apply %}...{% endapply %}` — delayed assets
     * substitution in the captured block.
     *
     * @param mixed $content
     * @param array $params
     * @return string
     */
    public function assetsApply($content, array $params = [])
    {
        if (empty($content)) {
            return '';
        }

        $assets = \PXHTMLAssets::getInstance();
        if (!$assets->delayed_print) {
            return $content;
        }

        return $assets->applyDelayed($content);
    }
}
