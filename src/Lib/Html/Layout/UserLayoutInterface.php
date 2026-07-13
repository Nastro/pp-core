<?php

namespace PP\Lib\Html\Layout;

/**
 * Client-side (user area) layout contract.
 *
 * Implemented by template-engine backed layouts (Smarty: PXUserHTMLLayout,
 * Twig: TwigLayout). The concrete engine is selected through the DI container
 * by defining a public service with id `PP\Lib\Html\Layout\LayoutInterface`
 * in the project services.yml; when no such service is defined, the engine
 * falls back to the legacy Smarty-based PXUserHTMLLayout.
 *
 * Interface UserLayoutInterface
 * @package PP\Lib\Html\Layout
 */
interface UserLayoutInterface extends LayoutInterface
{
    /**
     * Renders a single template and returns html.
     *
     * @param string $template template name, e.g. 'misc/pager/pages.tmpl'
     * @return string
     */
    public function html($template);

    /**
     * Returns previously assigned template variable.
     *
     * @param string $varName
     * @param mixed $default
     * @return mixed
     */
    public function getVar($varName, $default = null);

    /**
     * Assigns array of template variables.
     *
     * @param array $variablesArray
     * @return void
     */
    public function assignArray($variablesArray);

    /**
     * Exposes request data (get/post/cookies) to templates.
     *
     * @param \PXRequest $request
     * @return void
     */
    public function assignRequest($request);

    /**
     * Overrides index (root) template.
     *
     * @param string $filename
     * @return void
     */
    public function changeIndexTemplate($filename = 'index.tmpl');

    /**
     * Registers template function, e.g. {pager ...}.
     *
     * @param string $name
     * @param callable $callback
     * @return void
     */
    public function addTemplateFunction($name, $callback);

    /**
     * Registers template modifier, e.g. {$var|property}.
     *
     * @param string $name
     * @param callable $callback
     * @return void
     */
    public function addTemplateModifier($name, $callback);

    /**
     * Registers template block function, e.g. {assets_apply}...{/assets_apply}.
     *
     * @param string $name
     * @param callable $callback
     * @param bool $cacheable
     * @return void
     */
    public function addTemplateBlock($name, $callback, $cacheable = true);

    /**
     * Registers a post-render output filter.
     *
     * @param callable|string $functionName
     * @return void
     */
    public function addFilter($functionName);

    /**
     * Removes previously registered output filter.
     *
     * @param callable|string $functionName
     * @return void
     */
    public function removeFilter($functionName);
}
