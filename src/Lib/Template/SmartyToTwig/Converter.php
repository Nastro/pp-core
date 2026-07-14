<?php

namespace PP\Lib\Template\SmartyToTwig;

/**
 * Smarty 2 template source to Twig 3 source converter.
 *
 * Constructs that cannot be converted automatically are collected as
 * issues (see ConversionResult); offending tags are kept in the output
 * inside `{# UNCONVERTED: ... #}` comments for manual porting.
 *
 * @package PP\Lib\Template\SmartyToTwig
 */
class Converter
{
    /** tags removed from output with a warning */
    private const DROPPED_TAGS = ['debug', 'popup_init'];

    /** tags that always require manual porting */
    private const MANUAL_TAGS = [
        'php', 'include_php', 'insert', 'eval', 'fetch', 'config_load',
        'counter', 'cycle', 'textformat', 'mailto', 'popup',
        'html_options', 'html_checkboxes', 'html_image', 'html_radios',
        'html_select_date', 'html_select_time', 'html_table',
        'break', 'continue', 'return',
    ];

    /** template functions registered by TwigLayout out of the box */
    private const KNOWN_FUNCTIONS = [
        'pager', 'autopager', 'property', 'lang', 'createpath', 'img',
        'html_import', 'jquery', 'layout_var', 'pager_href',
    ];

    /** @var ExpressionConverter */
    private $expression;

    /** @var ConversionResult */
    private $result;

    /** @var array scope stack of open block tags */
    private $stack = [];

    /** @var bool inside {strip} */
    private $stripDepth = 0;

    /** @var int current source line (1-based) */
    private $line = 1;

    /** @var string tag being converted, for issue messages */
    private $currentFragment = '';

    /** @var array set of `{/name}` closing tag names present in source */
    private $closingTags = [];

    /** @var string output buffer */
    private $out = '';

    /**
     * Whether the smarty construct emitted last swallowed the newline
     * following it (block tags, comments do; output tags, literal
     * boundaries don't). Compared against twig's own newline eating
     * (after `%}` and `#}`) to fix up the next text chunk.
     * @var bool
     */
    private $smartyAte = false;

    /** @var bool ltrim next text chunk (smarty does it after {strip}) */
    private $pendingLtrim = false;

    public function __construct()
    {
        $this->expression = new ExpressionConverter($this);
    }

    /**
     * @param string $source smarty template source
     * @return ConversionResult
     */
    public function convertSource($source)
    {
        $this->result = new ConversionResult();
        $this->stack = [];
        $this->stripDepth = 0;
        $this->line = 1;
        $this->out = '';
        $this->smartyAte = false;
        $this->pendingLtrim = false;

        preg_match_all('~\{/(\w+)~', $source, $m);
        $this->closingTags = array_flip($m[1]);

        $length = strlen($source);
        $pos = 0;

        while ($pos < $length) {
            $brace = strpos($source, '{', $pos);

            if ($brace === false) {
                $this->emitText(substr($source, $pos));
                break;
            }

            if ($brace > $pos) {
                $this->emitText(substr($source, $pos, $brace - $pos));
                $pos = $brace;
            }

            // comment {* ... *}
            if (substr($source, $pos, 2) === '{*') {
                $end = strpos($source, '*}', $pos + 2);
                if ($end === false) {
                    $this->issue('unterminated comment', ConversionIssue::ERROR);
                    $this->emitText(substr($source, $pos));
                    break;
                }
                $comment = substr($source, $pos + 2, $end - $pos - 2);
                $this->line += substr_count($comment, "\n");
                $this->emitRaw('{#' . str_replace(['{#', '#}'], ['{ #', '# }'], $comment) . '#}');
                $pos = $end + 2;
                continue;
            }

            $tagEnd = $this->findTagEnd($source, $pos);
            if ($tagEnd === false) {
                $this->issue('unbalanced { — brace kept as text; wrap in {literal} in the source', ConversionIssue::ERROR, substr($source, $pos, 40));
                $this->emitText('{');
                $pos++;
                continue;
            }

            $tag = substr($source, $pos + 1, $tagEnd - $pos - 1);
            $rawTag = substr($source, $pos, $tagEnd - $pos + 1);

            // {literal}: consume verbatim till {/literal}
            if (trim($tag) === 'literal') {
                $close = strpos($source, '{/literal}', $tagEnd);
                if ($close === false) {
                    $this->issue('unterminated {literal}', ConversionIssue::ERROR);
                    $this->emitText(substr($source, $pos));
                    break;
                }
                $content = substr($source, $tagEnd + 1, $close - $tagEnd - 1);
                $this->line += substr_count($rawTag . $content, "\n") + 1;
                $this->emitLiteral($content);
                $pos = $close + strlen('{/literal}');
                continue;
            }

            // {php}: cannot be converted, comment out entire block
            if (trim($tag) === 'php') {
                $close = strpos($source, '{/php}', $tagEnd);
                $blockEnd = $close === false ? $length : $close + strlen('{/php}');
                $block = substr($source, $pos, $blockEnd - $pos);
                $this->currentFragment = $block;
                $this->issue('{php} block requires manual porting', ConversionIssue::ERROR);
                $this->emitRaw('{# UNCONVERTED: ' . $this->commentSafe($block) . ' #}');
                $this->line += substr_count($block, "\n");
                $pos = $blockEnd;
                continue;
            }

            $this->currentFragment = $rawTag;
            $this->convertTag(trim($tag), $rawTag);
            $this->line += substr_count($rawTag, "\n");
            $pos = $tagEnd + 1;
        }

        foreach ($this->stack as $frame) {
            $this->issue("unclosed {{$frame['tag']}} at line {$frame['line']}", ConversionIssue::ERROR, '{' . $frame['tag'] . '}');
        }

        // smarty 2 always swallows the trailing newline of a template file
        $this->result->twig = preg_replace('/\r?\n$/', '', $this->out, 1);

        return $this->result;
    }

    /**
     * Finds the tag closing brace respecting quotes and backticks.
     *
     * @param string $source
     * @param int $pos position of '{'
     * @return int|false position of '}'
     */
    private function findTagEnd($source, $pos)
    {
        $length = strlen($source);
        $quote = null;

        for ($i = $pos + 1; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '}') {
                return $i;
            } elseif ($char === '{') {
                // nested opening brace — malformed smarty tag
                return false;
            }
        }

        return false;
    }

    /**
     * @param string $tag trimmed tag content without braces
     * @param string $rawTag original tag with braces
     */
    private function convertTag($tag, $rawTag)
    {
        // closing tags
        if ($tag !== '' && $tag[0] === '/') {
            $this->convertClosingTag(substr($tag, 1), $rawTag);
            return;
        }

        // output expression: {$var...}, {"str"|mod}, {'str'}, {(...)}, {!...}
        $first = $tag === '' ? '' : $tag[0];
        if (in_array($first, ['$', '"', "'", '(', '`'], true)) {
            $this->emitExpression($tag, $rawTag);
            return;
        }

        if (!preg_match('/^(\w+)(.*)$/s', $tag, $m)) {
            $this->issue('unrecognized tag', ConversionIssue::ERROR);
            $this->emitUnconverted($rawTag);
            return;
        }

        $name = strtolower($m[1]);
        $rest = trim($m[2]);

        switch ($name) {
            case 'if':
                $this->pushFrame('if');
                $this->emitBlock('if ' . $this->safeExpr($rest, '1 == 0'));
                return;

            case 'elseif':
                $this->emitBlock('elseif ' . $this->safeExpr($rest, '1 == 0'));
                return;

            case 'else':
                $this->emitBlock('else');
                return;

            case 'foreach':
                $this->convertForeach($rest, $rawTag);
                return;

            case 'foreachelse':
            case 'sectionelse':
                $this->emitBlock('else');
                return;

            case 'section':
                $this->convertSection($rest, $rawTag);
                return;

            case 'strip':
                $this->stripDepth++;
                $this->pendingLtrim = true;
                return;

            case 'capture':
                $this->convertCapture($rest);
                return;

            case 'assign':
                $this->convertAssign($rest, $rawTag);
                return;

            case 'math':
                $this->convertMath($rest, $rawTag);
                return;

            case 'include':
                $this->convertInclude($rest, $rawTag);
                return;

            case 'ldelim':
                // unlike output tags, smarty {ldelim} swallows the newline
                $this->emitRaw("{{ '{' }}");
                return;

            case 'rdelim':
                $this->emitRaw("{{ '}' }}");
                return;

            default:
                if (in_array($name, self::DROPPED_TAGS, true)) {
                    $this->issue("{{$name}} dropped from output", ConversionIssue::WARNING);
                    return;
                }

                if (in_array($name, self::MANUAL_TAGS, true)) {
                    $this->issue("{{$name}} has no twig counterpart — port manually", ConversionIssue::ERROR);
                    $this->emitUnconverted($rawTag);
                    return;
                }

                $this->convertFunctionTag($name, $rest, $rawTag);
        }
    }

    /**
     * @param string $name
     * @param string $rawTag
     */
    private function convertClosingTag($name, $rawTag)
    {
        $name = strtolower(trim($name));

        switch ($name) {
            case 'if':
                $this->popFrame('if');
                $this->emitBlock('endif');
                return;

            case 'foreach':
                $this->popFrame('for');
                $this->emitBlock('endfor');
                return;

            case 'section':
                $this->popFrame('section');
                $this->emitBlock('endfor');
                return;

            case 'capture':
                $this->popFrame('capture');
                $this->emitBlock('endset');
                return;

            case 'strip':
                $this->stripDepth = max(0, $this->stripDepth - 1);
                // smarty rtrims the text block right before {/strip}
                // and keeps the newline following the tag
                $this->out = rtrim($this->out);
                $this->smartyAte = false;
                return;

            default:
                if ($this->topFrameIs('apply', $name)) {
                    $this->popFrame('apply');
                    $this->emitBlock('endapply');
                    return;
                }

                $this->issue("unexpected closing tag {/{$name}}", ConversionIssue::ERROR);
                $this->emitUnconverted($rawTag);
        }
    }

    /**
     * {foreach from=$arr item=x key=k name=n} => {% for k, x in arr ?: [] %}
     *
     * @param string $attrString
     * @param string $rawTag
     */
    private function convertForeach($attrString, $rawTag)
    {
        $attrs = $this->parseAttributes($attrString);

        if (!isset($attrs['from'], $attrs['item'])) {
            $this->issue('{foreach} without from/item attrs', ConversionIssue::ERROR);
            $this->emitUnconverted($rawTag);
            return;
        }

        $from = $this->safeExpr($attrs['from'], '[]');
        $item = trim($attrs['item'], '\'" ');
        $key = isset($attrs['key']) ? trim($attrs['key'], '\'" ') : null;
        $name = isset($attrs['name']) ? trim($attrs['name'], '\'" ') : null;

        $this->pushFrame('for', $name);

        $target = $key !== null ? "{$key}, {$item}" : $item;
        $this->emitBlock("for {$target} in ({$from}) ?: []");
    }

    /**
     * {section name=x start=a loop=b} => guarded {% for %} over range.
     *
     * @param string $attrString
     * @param string $rawTag
     */
    private function convertSection($attrString, $rawTag)
    {
        $attrs = $this->parseAttributes($attrString);

        if (!isset($attrs['name'], $attrs['loop'])) {
            $this->issue('{section} without name/loop attrs', ConversionIssue::ERROR);
            $this->emitUnconverted($rawTag);
            return;
        }

        if (isset($attrs['step']) && trim($attrs['step'], '\'" ') === '1') {
            unset($attrs['step']); // explicit step=1 equals the default
        }

        foreach (['step', 'max', 'show'] as $unsupported) {
            if (isset($attrs[$unsupported])) {
                $this->issue("{section} attribute '{$unsupported}' is not supported — port manually", ConversionIssue::ERROR);
            }
        }

        $name = trim($attrs['name'], '\'" ');
        $loopVar = $this->sectionLoopVar($name);
        $totalVar = $loopVar . '_total';

        $loop = $this->safeExpr($attrs['loop'], '0');
        $start = isset($attrs['start']) ? $this->safeExpr($attrs['start'], '0') : '0';

        $this->pushFrame('section', $name);

        // smarty: loop=$array means count($array), loop=5 means 5
        $this->emitBlock("set {$totalVar} = ({$loop}) is iterable ? ({$loop})|length : ({$loop})");
        $this->emitBlock("for {$loopVar} in (({$start}) < {$totalVar} ? range({$start}, {$totalVar} - 1) : [])");
    }

    /**
     * @param string $attrString
     */
    private function convertCapture($attrString)
    {
        $attrs = $this->parseAttributes($attrString);

        if (isset($attrs['assign'])) {
            $var = trim($attrs['assign'], '\'" ');
        } elseif (isset($attrs['name'])) {
            $var = $this->captureVar(trim($attrs['name'], '\'" '));
        } else {
            $var = $this->captureVar('default');
        }

        $this->pushFrame('capture');
        $this->emitBlock("set {$var}");
    }

    /**
     * @param string $attrString
     * @param string $rawTag
     */
    private function convertAssign($attrString, $rawTag)
    {
        $attrs = $this->parseAttributes($attrString);

        if (!isset($attrs['var'], $attrs['value'])) {
            $this->issue('{assign} without var/value attrs', ConversionIssue::ERROR);
            $this->emitUnconverted($rawTag);
            return;
        }

        if (isset($attrs['scope'])) {
            $this->issue("{assign} scope attribute ignored", ConversionIssue::WARNING);
        }

        $var = trim($attrs['var'], '\'" ');

        if ($this->insideLoop()) {
            $this->issue(
                "{assign var={$var}} inside a loop: twig {% set %} is loop-scoped, smarty assign leaks outside — verify '{$var}' is not read after the loop",
                ConversionIssue::WARNING
            );
        }

        $this->emitBlock("set {$var} = " . $this->safeExpr($attrs['value'], 'null'));
    }

    /**
     * {math equation="x + y" x=$a y=$b assign=z}
     *
     * @param string $attrString
     * @param string $rawTag
     */
    private function convertMath($attrString, $rawTag)
    {
        $attrs = $this->parseAttributes($attrString);

        if (!isset($attrs['equation'])) {
            $this->issue('{math} without equation', ConversionIssue::ERROR);
            $this->emitUnconverted($rawTag);
            return;
        }

        if (isset($attrs['format'])) {
            $this->issue('{math} format attribute is not supported — port manually', ConversionIssue::ERROR);
            $this->emitUnconverted($rawTag);
            return;
        }

        $equation = trim($attrs['equation'], '\'"');
        $assign = isset($attrs['assign']) ? trim($attrs['assign'], '\'" ') : null;

        $vars = [];
        foreach ($attrs as $key => $value) {
            if (!in_array($key, ['equation', 'assign', 'format'], true)) {
                $vars[$key] = $this->safeExpr($value, 'null');
            }
        }

        $failed = false;
        $converted = preg_replace_callback('/[a-zA-Z_]\w*/', function ($m) use ($vars, $equation, &$failed) {
            $word = $m[0];

            if (isset($vars[$word])) {
                return '(' . $vars[$word] . ')';
            }

            // math functions fall back to php functions in twig
            if (function_exists($word)) {
                return $word;
            }

            $failed = true;
            return $word;
        }, $equation);

        if ($failed) {
            $this->issue('{math} equation references unknown identifiers — port manually', ConversionIssue::ERROR);
            $this->emitUnconverted($rawTag);
            return;
        }

        if ($assign !== null) {
            if ($this->insideLoop()) {
                $this->issue(
                    "{math assign={$assign}} inside a loop: twig {% set %} is loop-scoped — verify '{$assign}' is not read after the loop",
                    ConversionIssue::WARNING
                );
            }
            $this->emitBlock("set {$assign} = {$converted}");
        } else {
            $this->emitRaw('{{ ' . $converted . ' }}', false);
        }
    }

    /**
     * @param string $attrString
     * @param string $rawTag
     */
    private function convertInclude($attrString, $rawTag)
    {
        $attrs = $this->parseAttributes($attrString);

        if (!isset($attrs['file'])) {
            $this->issue('{include} without file attr', ConversionIssue::ERROR);
            $this->emitUnconverted($rawTag);
            return;
        }

        $file = $this->safeExpr($attrs['file'], "''");
        $assign = isset($attrs['assign']) ? trim($attrs['assign'], '\'" ') : null;

        $extra = [];
        foreach ($attrs as $key => $value) {
            if (!in_array($key, ['file', 'assign'], true)) {
                $extra[] = $key . ': ' . $this->safeExpr($value, 'null');
            }
        }

        $include = 'include ' . $file;
        if (count($extra)) {
            $include .= ' with {' . implode(', ', $extra) . '}';
        }

        if ($assign !== null) {
            $this->emitBlock("set {$assign}");
            $this->emitBlock($include);
            $this->emitBlock('endset');
            return;
        }

        $this->emitBlock($include);
    }

    /**
     * Generic smarty function tag: {name attr=val ...}.
     *
     * @param string $name
     * @param string $attrString
     * @param string $rawTag
     */
    private function convertFunctionTag($name, $attrString, $rawTag)
    {
        $attrs = $this->parseAttributes($attrString);

        // block function {name}...{/name} => {% apply name(...) %}
        if (isset($this->closingTags[$name])) {
            $this->pushFrame('apply', $name);

            $hash = $this->attrsToHash($attrs);
            $this->emitBlock('apply ' . $name . ($hash !== '' ? '(' . $hash . ')' : ''));
            $this->issue(
                "block {{$name}} converted to twig `apply` filter — make sure the block is registered via addTemplateBlock()",
                ConversionIssue::WARNING
            );
            return;
        }

        $assign = null;
        if (isset($attrs['assign'])) {
            $assign = trim($attrs['assign'], '\'" ');
            unset($attrs['assign']);
        }

        $call = $name . '({' . $this->attrsToHash($attrs) . '})';

        if (!in_array($name, self::KNOWN_FUNCTIONS, true)) {
            $this->issue(
                "function {{$name}} is not registered by TwigLayout out of the box — make sure the project registers it via addTemplateFunction()",
                ConversionIssue::WARNING
            );
        }

        if ($assign !== null) {
            if ($this->insideLoop()) {
                $this->issue(
                    "{{$name} assign={$assign}} inside a loop: twig {% set %} is loop-scoped — verify '{$assign}' is not read after the loop",
                    ConversionIssue::WARNING
                );
            }
            $this->emitBlock("set {$assign} = " . $call);
        } else {
            // smarty function tags with output keep the following newline
            $this->emitRaw('{{ ' . $call . ' }}', false);
        }

        // smarty pager plugins assign page vars into template scope at
        // render time; resync them into twig context
        if ($name === 'pager' || $name === 'autopager') {
            $this->emitBlock(
                "set a_per_page, page, pages, max_page = " .
                "layout_var('a_per_page'), layout_var('page'), layout_var('pages'), layout_var('max_page')",
                $assign !== null
            );
        }
    }

    /**
     * @param array $attrs raw attr map
     * @return string twig hash body `key: value, ...`
     */
    private function attrsToHash($attrs)
    {
        $pairs = [];
        foreach ($attrs as $key => $value) {
            $pairs[] = $key . ': ' . $this->safeExpr($value, 'null');
        }

        return implode(', ', $pairs);
    }

    /**
     * {$expr|mods} output tag.
     *
     * @param string $tag
     * @param string $rawTag
     */
    private function emitExpression($tag, $rawTag)
    {
        // smarty3-style `nofilter` flag: twig applies no output filters anyway
        if (preg_match('/\s+nofilter\s*$/', $tag)) {
            $tag = preg_replace('/\s+nofilter\s*$/', '', $tag);
            $this->issue('`nofilter` flag dropped (twig applies no output filters)', ConversionIssue::WARNING);
        }

        try {
            // smarty output tags keep the newline following them
            $this->emitRaw('{{ ' . $this->expression->convert($tag) . ' }}', false);
        } catch (ConversionException $e) {
            $this->issue($e->getMessage(), ConversionIssue::ERROR);
            $this->emitUnconverted($rawTag);
        }
    }

    /**
     * Converts expression reporting failures as issues.
     *
     * @param string $expr raw smarty expression (or attr value)
     * @param string $fallback twig expression used when conversion fails
     * @return string
     */
    private function safeExpr($expr, $fallback)
    {
        $expr = trim($expr);

        // bare words (unquoted attr values): file=misc/pager/item.tmpl
        if (preg_match('~^[\w./\-]+$~', $expr) && !is_numeric($expr)) {
            $lower = strtolower($expr);
            if ($lower === 'true' || $lower === 'on' || $lower === 'yes') {
                return 'true';
            }
            if ($lower === 'false' || $lower === 'off' || $lower === 'no') {
                return 'false';
            }
            if ($lower === 'null') {
                return 'null';
            }
            if (preg_match('/^\w+$/', $expr) && $this->isOpenSectionName($expr)) {
                return $this->sectionLoopVar($expr);
            }

            return "'" . preg_replace('/\.tmpl$/', '.twig', $expr) . "'";
        }

        try {
            return $this->expression->convert($expr);
        } catch (ConversionException $e) {
            $this->issue($e->getMessage() . " in `{$expr}`", ConversionIssue::ERROR);

            return $fallback;
        }
    }

    /**
     * Parses smarty tag attributes: name=value pairs and bare flags.
     *
     * @param string $s
     * @return array name => raw value
     */
    public function parseAttributes($s)
    {
        $attrs = [];
        $length = strlen($s);
        $i = 0;

        while ($i < $length) {
            while ($i < $length && ctype_space($s[$i])) {
                $i++;
            }
            if ($i >= $length) {
                break;
            }

            $start = $i;
            while ($i < $length && (ctype_alnum($s[$i]) || $s[$i] === '_')) {
                $i++;
            }
            $name = substr($s, $start, $i - $start);

            if ($name === '') {
                // unparseable leftover
                $this->issue("malformed attributes near `" . substr($s, $i, 20) . "`", ConversionIssue::WARNING);
                break;
            }

            while ($i < $length && ctype_space($s[$i])) {
                $i++;
            }

            if ($i >= $length || $s[$i] !== '=') {
                $attrs[$name] = 'true'; // bare flag, e.g. nocache
                continue;
            }

            $i++; // skip '='
            while ($i < $length && ctype_space($s[$i])) {
                $i++;
            }

            $vStart = $i;
            $quote = null;
            $depth = 0;

            for (; $i < $length; $i++) {
                $char = $s[$i];

                if ($quote !== null) {
                    if ($char === '\\') {
                        $i++;
                    } elseif ($char === $quote) {
                        $quote = null;
                    }
                    continue;
                }

                if ($char === "'" || $char === '"' || $char === '`') {
                    $quote = $char;
                } elseif ($char === '(' || $char === '[') {
                    $depth++;
                } elseif ($char === ')' || $char === ']') {
                    $depth--;
                } elseif ($depth === 0 && ctype_space($char)) {
                    break;
                }
            }

            $attrs[$name] = substr($s, $vStart, $i - $vStart);
        }

        return $attrs;
    }

    // ------------------------------------------------------------------
    // scope stack helpers (also used by ExpressionConverter)
    // ------------------------------------------------------------------

    private function pushFrame($tag, $name = null)
    {
        $this->stack[] = ['tag' => $tag, 'name' => $name, 'line' => $this->line];
    }

    private function popFrame($tag)
    {
        $top = end($this->stack);

        if ($top === false || $top['tag'] !== $tag) {
            $this->issue(
                "mismatched closing tag: expected {/" . ($top === false ? '?' : $top['tag']) . "}",
                ConversionIssue::ERROR
            );
            return;
        }

        array_pop($this->stack);
    }

    private function topFrameIs($tag, $name)
    {
        $top = end($this->stack);

        return $top !== false && $top['tag'] === $tag && $top['name'] === $name;
    }

    private function insideLoop()
    {
        foreach ($this->stack as $frame) {
            if ($frame['tag'] === 'for' || $frame['tag'] === 'section') {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves $smarty.foreach.NAME.prop / $smarty.section.NAME.prop into
     * twig loop variable respecting nesting.
     *
     * @param string $type 'for' or 'section'
     * @param string $name smarty loop name
     * @param string $prop
     * @return string
     * @throws ConversionException
     */
    public function resolveLoopProperty($type, $name, $prop)
    {
        $depth = 0;
        $found = false;

        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            $frame = $this->stack[$i];

            if ($frame['tag'] !== 'for' && $frame['tag'] !== 'section') {
                continue;
            }

            if ($frame['tag'] === $type && $frame['name'] === $name) {
                $found = true;
                break;
            }

            $depth++;
        }

        if (!$found) {
            throw new ConversionException("\$smarty.{$type}.{$name} referenced outside of the named loop");
        }

        $loop = 'loop' . str_repeat('.parent.loop', $depth);

        switch ($prop) {
            case 'iteration':
            case 'rownum':
                return $loop . '.index';
            case 'index':
                return $loop . '.index0';
            case 'first':
                return $loop . '.first';
            case 'last':
                return $loop . '.last';
            case 'total':
                return $loop . '.length';
            case 'index_prev':
                return '(' . $loop . '.index0 - 1)';
            case 'index_next':
                return '(' . $loop . '.index0 + 1)';
            default:
                throw new ConversionException("\$smarty.{$type}.{$name}.{$prop} is not supported");
        }
    }

    /**
     * @param string $name
     * @return bool
     */
    public function isOpenSectionName($name)
    {
        foreach ($this->stack as $frame) {
            if ($frame['tag'] === 'section' && $frame['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $name
     * @return string
     */
    public function sectionLoopVar($name)
    {
        return '__sec_' . $name;
    }

    /**
     * @param string $name
     * @return string
     */
    public function captureVar($name)
    {
        return '__capture_' . $name;
    }

    /**
     * Registers an issue at the current source position.
     *
     * @param string $reason
     * @param string $severity
     * @param string|null $fragment
     */
    public function issue($reason, $severity = ConversionIssue::ERROR, $fragment = null)
    {
        $this->result->issues[] = new ConversionIssue(
            $this->line,
            $fragment ?? $this->currentFragment,
            $reason,
            $severity
        );
    }

    // ------------------------------------------------------------------
    // emitters
    // ------------------------------------------------------------------

    /**
     * @param string $text raw template text between tags
     */
    private function emitText($text)
    {
        $this->line += substr_count($text, "\n");

        if ($this->pendingLtrim) {
            $text = ltrim($text);
            $this->pendingLtrim = false;
        }

        if ($this->stripDepth > 0) {
            $text = preg_replace('/[ \t]*\r?\n[ \t]*/', '', $text);
        } else {
            $twigEats = $this->twigEatsNewline();
            $startsWithNewline = (bool)preg_match('/^\r?\n/', $text);

            if ($this->smartyAte && !$twigEats) {
                $text = preg_replace('/^\r?\n/', '', $text, 1);
            } elseif (!$this->smartyAte && $twigEats && $startsWithNewline) {
                $text = "\n" . $text;
            }
        }

        $this->out .= $text;
        $this->smartyAte = false;
    }

    /**
     * @return bool twig removes one newline right after `%}` and `#}`
     */
    private function twigEatsNewline()
    {
        $tail = substr($this->out, -2);

        return $tail === '%}' || $tail === '#}';
    }

    /**
     * @param string $body block tag body without delimiters
     * @param bool $smartyAte whether the original smarty construct
     *                        swallowed the newline following it
     */
    private function emitBlock($body, $smartyAte = true)
    {
        $this->out .= '{% ' . $body . ' %}';
        $this->smartyAte = $smartyAte;
    }

    /**
     * @param string $chunk ready twig code
     * @param bool $smartyAte
     */
    private function emitRaw($chunk, $smartyAte = true)
    {
        $this->out .= $chunk;
        $this->smartyAte = $smartyAte;
    }

    /**
     * Keeps offending smarty tag in output as a twig comment.
     *
     * @param string $rawTag
     */
    private function emitUnconverted($rawTag)
    {
        $this->emitRaw('{# UNCONVERTED: ' . $this->commentSafe($rawTag) . ' #}');
    }

    /**
     * @param string $s
     * @return string
     */
    private function commentSafe($s)
    {
        return str_replace(['{#', '#}'], ['{ #', '# }'], $s);
    }

    /**
     * Literal block: raw text unless it contains twig delimiters.
     * Smarty {literal}/{/literal} keep surrounding newlines.
     *
     * @param string $content
     */
    private function emitLiteral($content)
    {
        if (preg_match('/\{\{|\{%|\{#/', $content)) {
            // {% verbatim %} eats the first newline of its content,
            // while smarty {literal} keeps it
            if (preg_match('/^\r?\n/', $content)) {
                $content = "\n" . $content;
            }
            $this->emitRaw('{% verbatim %}' . $content . '{% endverbatim %}', false);
            return;
        }

        // smarty {literal} keeps the newline before $content; compensate
        // when the twig tag preceding it in the generated source eats one
        if ($this->twigEatsNewline() && preg_match('/^\r?\n/', $content)) {
            $content = "\n" . $content;
        }

        $this->emitRaw($content, false);
    }
}
