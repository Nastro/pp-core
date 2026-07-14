<?php

namespace PP\Lib\Template\SmartyToTwig;

/**
 * Converts a single Smarty 2 expression (variable with modifiers,
 * {if} condition, attribute value) into a Twig expression.
 *
 * @package PP\Lib\Template\SmartyToTwig
 */
class ExpressionConverter
{
    /** word/symbol operators, smarty => twig */
    private const OP_MAP = [
        'eq' => '==', 'ne' => '!=', 'neq' => '!=',
        'gt' => '>', 'lt' => '<',
        'gte' => '>=', 'ge' => '>=', 'lte' => '<=', 'le' => '<=',
        'mod' => '%',
        'and' => 'and', 'or' => 'or', 'not' => 'not',
        'true' => 'true', 'false' => 'false', 'null' => 'null',
        'is_array' => 'is_array',
    ];

    /** modifiers renamed on the twig side (see SmartyCompatExtension) */
    private const MODIFIER_MAP = [
        'escape' => 'smarty_escape',
        'default' => 'smarty_default',
        'replace' => 'smarty_replace',
        'capitalize' => 'title',
        'indent' => 'indent',
    ];

    /** modifiers registered by TwigLayout/SmartyCompatExtension */
    private const KNOWN_MODIFIERS = [
        'smarty_escape', 'smarty_default', 'smarty_replace', 'cat', 'regex_replace',
        'strip', 'strip_tags', 'date_format', 'string_format', 'truncate', 'indent',
        'spacify', 'wordwrap', 'count', 'sizeof', 'isset', 'empty', 'property',
        'lang', 'date_to_time', 'title',
    ];

    /** twig built-in filters considered semantically compatible */
    private const TWIG_NATIVE_FILTERS = [
        'abs', 'batch', 'capitalize', 'column', 'date', 'date_modify', 'e', 'escape',
        'filter', 'first', 'format', 'join', 'json_encode', 'keys', 'last', 'length',
        'lower', 'map', 'merge', 'nl2br', 'raw', 'reduce', 'reverse', 'round',
        'slice', 'sort', 'split', 'striptags', 'trim', 'upper', 'url_encode',
    ];

    /** @var Converter */
    private $converter;

    public function __construct(Converter $converter)
    {
        $this->converter = $converter;
    }

    /**
     * Converts full smarty expression: operand with optional modifier chain.
     *
     * @param string $expr
     * @return string twig expression
     * @throws ConversionException
     */
    public function convert($expr)
    {
        $expr = trim($expr);

        if ($expr === '') {
            throw new ConversionException('empty expression');
        }

        $parts = $this->splitTopLevel($expr, '|');
        $result = $this->convertOperand(trim(array_shift($parts)));

        foreach ($parts as $modifier) {
            $result .= $this->convertModifier(trim($modifier));
        }

        return $result;
    }

    /**
     * Converts `name:arg1:arg2` modifier chain link into `|name(arg1, arg2)`.
     *
     * @param string $modifier
     * @return string
     * @throws ConversionException
     */
    private function convertModifier($modifier)
    {
        if ($modifier === '') {
            throw new ConversionException('empty modifier');
        }

        // `|@count` — "apply to whole array" marker, no twig counterpart needed
        if ($modifier[0] === '@') {
            $modifier = substr($modifier, 1);
        }

        if (!preg_match('/^(\w+)/', $modifier, $m)) {
            throw new ConversionException("malformed modifier '{$modifier}'");
        }

        $name = $m[1];
        $pos = strlen($name);
        $length = strlen($modifier);

        // `:arg` list ends at first top-level whitespace: the tail is a
        // continuation of the surrounding expression ({if $x|strlen > 5})
        $args = [];
        while ($pos < $length && $modifier[$pos] === ':') {
            $pos++;
            $argStart = $pos;
            $quote = null;
            $depth = 0;

            for (; $pos < $length; $pos++) {
                $char = $modifier[$pos];

                if ($quote !== null) {
                    if ($char === '\\') {
                        $pos++;
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
                } elseif ($depth === 0 && ($char === ':' || ctype_space($char))) {
                    break;
                }
            }

            $args[] = substr($modifier, $argStart, $pos - $argStart);
        }

        $tail = trim(substr($modifier, $pos));

        $twigName = self::MODIFIER_MAP[$name] ?? $name;

        if (!in_array($twigName, self::KNOWN_MODIFIERS, true)
            && !in_array($twigName, self::TWIG_NATIVE_FILTERS, true)
            && !function_exists($twigName)
        ) {
            $this->converter->issue(
                "modifier '{$name}' is not a known twig filter nor a PHP function — register it in TwigLayout or fix manually",
                ConversionIssue::ERROR
            );
        }

        $out = '|' . $twigName;

        if (count($args)) {
            $converted = [];
            foreach ($args as $arg) {
                $converted[] = $this->convertOperand(trim($arg));
            }
            $out .= '(' . implode(', ', $converted) . ')';
        }

        if ($tail !== '') {
            $out .= ' ' . $this->convertOperand($tail);
        }

        return $out;
    }

    /**
     * Converts a modifier-free smarty operand/condition.
     *
     * @param string $expr
     * @return string
     * @throws ConversionException
     */
    public function convertOperand($expr)
    {
        $out = '';
        $length = strlen($expr);
        $i = 0;

        while ($i < $length) {
            $char = $expr[$i];

            // whitespace
            if (ctype_space($char)) {
                $out .= ' ';
                $i++;
                while ($i < $length && ctype_space($expr[$i])) {
                    $i++;
                }
                continue;
            }

            // variable
            if ($char === '$') {
                $out .= $this->parseVariable($expr, $i);
                continue;
            }

            // strings
            if ($char === "'") {
                $out .= $this->parseSingleQuoted($expr, $i);
                continue;
            }
            if ($char === '"') {
                $out .= $this->parseDoubleQuoted($expr, $i);
                continue;
            }
            if ($char === '`') {
                // stray backtick outside double quotes
                throw new ConversionException('unexpected backtick');
            }

            // numbers
            if (ctype_digit($char) || ($char === '.' && $i + 1 < $length && ctype_digit($expr[$i + 1]))) {
                $start = $i;
                $i++;
                while ($i < $length && (ctype_digit($expr[$i]) || $expr[$i] === '.')) {
                    $i++;
                }
                $out .= substr($expr, $start, $i - $start);
                continue;
            }

            // words: operators, constants, function calls, bare strings
            if (ctype_alpha($char) || $char === '_') {
                $start = $i;
                while ($i < $length && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) {
                    $i++;
                }
                $word = substr($expr, $start, $i - $start);
                $lower = strtolower($word);

                if (isset(self::OP_MAP[$lower])) {
                    $out .= self::OP_MAP[$lower];
                    continue;
                }

                // function call?
                $j = $i;
                while ($j < $length && ctype_space($expr[$j])) {
                    $j++;
                }

                if ($j < $length && $expr[$j] === '(') {
                    $out .= $this->parseFunctionCall($word, $expr, $i);
                    continue;
                }

                // bare word position: smarty treats it as a string, but a
                // word followed by `.` or word-chars is likely a mistake
                if ($this->converter->isOpenSectionName($word)) {
                    $out .= $this->converter->sectionLoopVar($word);
                    continue;
                }

                $out .= "'" . $word . "'";
                continue;
            }

            // multi-char operators
            $three = substr($expr, $i, 3);
            if ($three === '===' || $three === '!==') {
                $i += 3;
                $rhs = $this->parseStrictComparisonRhs($expr, $i);
                $out = rtrim($out);
                $out .= ($three === '===' ? ' is same as(' : ' is not same as(') . $rhs . ')';
                continue;
            }

            $two = substr($expr, $i, 2);
            if (in_array($two, ['==', '!=', '<=', '>=', '&&', '||'], true)) {
                $out .= ($two === '&&') ? 'and' : (($two === '||') ? 'or' : $two);
                $i += 2;
                continue;
            }

            if (strpos('+-*/%()<>,!', $char) !== false) {
                $out .= ($char === '!') ? 'not ' : $char;
                $i++;
                continue;
            }

            throw new ConversionException("unexpected character '{$char}'");
        }

        return trim($out);
    }

    /**
     * Parses the single operand to the right of `===`/`!==` so it can be
     * wrapped into a twig `is same as(...)` identity test.
     *
     * @param string $expr
     * @param int $i byref position (just past the operator)
     * @return string
     * @throws ConversionException
     */
    private function parseStrictComparisonRhs($expr, &$i)
    {
        $length = strlen($expr);
        while ($i < $length && ctype_space($expr[$i])) {
            $i++;
        }
        if ($i >= $length) {
            throw new ConversionException('missing right operand of strict comparison');
        }

        $char = $expr[$i];

        if ($char === '$') {
            return $this->parseVariable($expr, $i);
        }
        if ($char === "'") {
            return $this->parseSingleQuoted($expr, $i);
        }
        if ($char === '"') {
            return $this->parseDoubleQuoted($expr, $i);
        }
        if ($char === '(') {
            $inner = $this->extractBalanced($expr, $i, '(', ')');
            return '(' . $this->convertOperand(trim($inner)) . ')';
        }
        if (ctype_digit($char) || $char === '-'
            || ($char === '.' && $i + 1 < $length && ctype_digit($expr[$i + 1]))
        ) {
            $start = $i;
            $i++;
            while ($i < $length && (ctype_digit($expr[$i]) || $expr[$i] === '.')) {
                $i++;
            }
            return substr($expr, $start, $i - $start);
        }
        if (ctype_alpha($char) || $char === '_') {
            $start = $i;
            while ($i < $length && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) {
                $i++;
            }
            $word = substr($expr, $start, $i - $start);
            $lower = strtolower($word);

            return in_array($lower, ['true', 'false', 'null'], true) ? $lower : "'" . $word . "'";
        }

        throw new ConversionException('unsupported right operand of strict comparison');
    }

    /**
     * Parses `$var`, `$var.key`, `$var->prop`, `$var->method(...)`,
     * `$var[expr]`, `$smarty.*` starting at $i (which points at '$').
     *
     * @param string $expr
     * @param int $i byref position
     * @return string
     * @throws ConversionException
     */
    private function parseVariable($expr, &$i)
    {
        $length = strlen($expr);
        $i++; // skip '$'

        $start = $i;
        while ($i < $length && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) {
            $i++;
        }
        $name = substr($expr, $start, $i - $start);

        if ($name === '') {
            throw new ConversionException('dangling $');
        }

        if ($name === 'smarty') {
            return $this->parseSmartyVariable($expr, $i);
        }

        $out = $name;

        while ($i < $length) {
            // ->prop | ->method(...)
            if (substr($expr, $i, 2) === '->') {
                $i += 2;

                if ($i < $length && $expr[$i] === '$') {
                    throw new ConversionException('dynamic property access `->$var` requires manual conversion');
                }

                $pStart = $i;
                while ($i < $length && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) {
                    $i++;
                }
                $prop = substr($expr, $pStart, $i - $pStart);
                if ($prop === '') {
                    throw new ConversionException('malformed `->` access');
                }

                if ($i < $length && $expr[$i] === '(') {
                    $args = $this->extractBalanced($expr, $i, '(', ')');
                    $out .= '.' . $prop . '(' . $this->convertArgList($args) . ')';
                } else {
                    $out .= '.' . $prop;
                }
                continue;
            }

            // .key | .$dynamicKey
            if ($expr[$i] === '.') {
                $next = $i + 1 < $length ? $expr[$i + 1] : '';

                if ($next === '$') {
                    // Smarty 2 dynamic index `.$key` is a plain word only;
                    // `->prop` after it continues the outer path.
                    $i += 2; // skip '.$'
                    $kStart = $i;
                    while ($i < $length && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) {
                        $i++;
                    }
                    $key = substr($expr, $kStart, $i - $kStart);
                    if ($key === '') {
                        throw new ConversionException('malformed dynamic key `.$`');
                    }
                    $out .= '[' . $key . ']';
                    continue;
                }

                if (ctype_alnum($next) || $next === '_') {
                    $i++;
                    $kStart = $i;
                    while ($i < $length && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) {
                        $i++;
                    }
                    $key = substr($expr, $kStart, $i - $kStart);
                    $out .= is_numeric($key) ? '[' . $key . ']' : '.' . $key;
                    continue;
                }

                break;
            }

            // [expr]
            if ($expr[$i] === '[') {
                $inner = $this->extractBalanced($expr, $i, '[', ']');
                $out .= '[' . $this->convertOperand(trim($inner)) . ']';
                continue;
            }

            break;
        }

        return $out;
    }

    /**
     * Handles `$smarty.*` special variable starting after the `smarty` word.
     *
     * @param string $expr
     * @param int $i byref position
     * @return string
     * @throws ConversionException
     */
    private function parseSmartyVariable($expr, &$i)
    {
        $segments = [];
        $length = strlen($expr);

        while ($i < $length && $expr[$i] === '.') {
            $i++;
            $sStart = $i;
            while ($i < $length && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) {
                $i++;
            }
            $segments[] = substr($expr, $sStart, $i - $sStart);
        }

        if (!count($segments)) {
            throw new ConversionException('bare $smarty variable');
        }

        $kind = array_shift($segments);

        switch ($kind) {
            case 'get':
            case 'post':
            case 'cookies':
            case 'request':
            case 'server':
            case 'session':
            case 'env':
            case 'now':
            case 'version':
                return implode('.', array_merge(['smarty', $kind], $segments));

            case 'const':
                if (count($segments) !== 1) {
                    throw new ConversionException('malformed $smarty.const');
                }
                return "constant('" . $segments[0] . "')";

            case 'capture':
                if (count($segments) !== 1) {
                    throw new ConversionException('malformed $smarty.capture');
                }
                return $this->converter->captureVar($segments[0]);

            case 'foreach':
                if (count($segments) !== 2) {
                    throw new ConversionException('malformed $smarty.foreach');
                }
                return $this->converter->resolveLoopProperty('for', $segments[0], $segments[1]);

            case 'section':
                if (count($segments) !== 2) {
                    throw new ConversionException('malformed $smarty.section');
                }
                if ($segments[1] === 'index') {
                    return $this->converter->sectionLoopVar($segments[0]);
                }
                return $this->converter->resolveLoopProperty('section', $segments[0], $segments[1]);

            case 'ldelim':
                return "'{'";
            case 'rdelim':
                return "'}'";

            default:
                throw new ConversionException("\$smarty.{$kind} is not supported");
        }
    }

    /**
     * @param string $name function name
     * @param string $expr
     * @param int $i byref position (at '(')
     * @return string
     * @throws ConversionException
     */
    private function parseFunctionCall($name, $expr, &$i)
    {
        $length = strlen($expr);
        while ($i < $length && $expr[$i] !== '(') {
            $i++;
        }

        $args = $this->extractBalanced($expr, $i, '(', ')');
        $lower = strtolower($name);

        // php-only constructs with registered twig filter counterparts
        if ($lower === 'isset' || $lower === 'empty') {
            return '(' . $this->convertOperand(trim($args)) . ')|' . $lower;
        }

        if (!function_exists($name)) {
            $this->converter->issue(
                "function '{$name}()' does not exist — twig will fail at runtime",
                ConversionIssue::ERROR
            );
        }

        return $name . '(' . $this->convertArgList($args) . ')';
    }

    /**
     * @param string $args raw argument list without parens
     * @return string
     * @throws ConversionException
     */
    private function convertArgList($args)
    {
        $args = trim($args);
        if ($args === '') {
            return '';
        }

        $converted = [];
        foreach ($this->splitTopLevel($args, ',') as $arg) {
            $converted[] = $this->convert(trim($arg));
        }

        return implode(', ', $converted);
    }

    /**
     * @param string $expr
     * @param int $i byref position at opening char
     * @param string $open
     * @param string $close
     * @return string content between balanced pair, position moved past closing char
     * @throws ConversionException
     */
    private function extractBalanced($expr, &$i, $open, $close)
    {
        $length = strlen($expr);
        $depth = 0;
        $start = $i + 1;
        $quote = null;

        for (; $i < $length; $i++) {
            $char = $expr[$i];

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
            } elseif ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    $content = substr($expr, $start, $i - $start);
                    $i++;
                    return $content;
                }
            }
        }

        throw new ConversionException("unbalanced {$open}{$close}");
    }

    /**
     * @param string $expr
     * @param int $i byref position at quote
     * @return string
     * @throws ConversionException
     */
    private function parseSingleQuoted($expr, &$i)
    {
        $length = strlen($expr);
        $i++;
        $raw = '';

        while ($i < $length) {
            $char = $expr[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $next = $expr[$i + 1];
                // php single-quote semantics: only \\ and \' are escapes
                $raw .= ($next === '\\' || $next === "'") ? $next : '\\' . $next;
                $i += 2;
                continue;
            }

            if ($char === "'") {
                $i++;
                // twig lexer runs stripcslashes() on the literal, so every
                // backslash must be doubled to survive (e.g. regex '\?')
                $encoded = strtr($raw, ['\\' => '\\\\', "'" => "\\'"]);
                return $this->replaceTmplExtension("'" . $encoded . "'");
            }

            $raw .= $char;
            $i++;
        }

        throw new ConversionException('unterminated string');
    }

    /**
     * Double-quoted smarty strings interpolate `$var` and `` `$expr` ``;
     * twig counterpart is `"...#{expr}..."`.
     *
     * @param string $expr
     * @param int $i byref position at quote
     * @return string
     * @throws ConversionException
     */
    private function parseDoubleQuoted($expr, &$i)
    {
        $length = strlen($expr);
        $i++;
        $out = '"';

        while ($i < $length) {
            $char = $expr[$i];

            if ($char === '\\') {
                $next = $i + 1 < $length ? $expr[$i + 1] : '';
                if ($next !== '' && strpos("nrtvfe\\\"\$0123456789xu", $next) !== false) {
                    // escape sequences twig's stripcslashes() treats like php
                    $out .= '\\' . $next;
                } else {
                    // php keeps unknown escapes verbatim (\? stays \?),
                    // stripcslashes would swallow the backslash — double it
                    $out .= '\\\\' . $next;
                }
                $i += 2;
                continue;
            }

            if ($char === '"') {
                $i++;
                return $this->replaceTmplExtension($out . '"');
            }

            if ($char === '`') {
                $end = strpos($expr, '`', $i + 1);
                if ($end === false) {
                    throw new ConversionException('unterminated backtick');
                }
                $inner = substr($expr, $i + 1, $end - $i - 1);
                $out .= '#{' . $this->convert($inner) . '}';
                $i = $end + 1;
                continue;
            }

            if ($char === '$') {
                // smarty interpolates only simple $var names in quotes;
                // dotted/complex paths require backticks
                if (preg_match('/\G\$(\w+)/', $expr, $m, 0, $i)) {
                    $out .= '#{' . $m[1] . '}';
                    $i += strlen($m[0]);
                } else {
                    $out .= '$';
                    $i++;
                }
                continue;
            }

            if ($char === '#' && ($i + 1 < $length) && $expr[$i + 1] === '{') {
                $out .= '\\#{';
                $i += 2;
                continue;
            }

            $out .= $char;
            $i++;
        }

        throw new ConversionException('unterminated string');
    }

    /**
     * Literal template names inside expressions point to twig files after
     * conversion: 'dt/foo.tmpl' => 'dt/foo.twig'.
     *
     * @param string $string quoted literal
     * @return string
     */
    private function replaceTmplExtension($string)
    {
        return preg_replace('/\.tmpl(?=["\'#]|$)/', '.twig', $string);
    }

    /**
     * Splits string by one-char delimiter respecting quotes, backticks,
     * parens and brackets.
     *
     * @param string $s
     * @param string $delim
     * @return string[]
     */
    public function splitTopLevel($s, $delim)
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($s);

        for ($i = 0; $i < $length; $i++) {
            $char = $s[$i];

            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $s[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            switch (true) {
                case $char === "'" || $char === '"' || $char === '`':
                    $quote = $char;
                    $current .= $char;
                    break;

                case $char === '(' || $char === '[':
                    $depth++;
                    $current .= $char;
                    break;

                case $char === ')' || $char === ']':
                    $depth--;
                    $current .= $char;
                    break;

                case $char === $delim && $depth === 0:
                    // `||` is an operator, not two modifier separators
                    if ($delim === '|' && (($i + 1 < $length && $s[$i + 1] === '|') || substr($current, -1) === '|')) {
                        $current .= $char;
                        break;
                    }
                    $parts[] = $current;
                    $current = '';
                    break;

                default:
                    $current .= $char;
            }
        }

        $parts[] = $current;

        return $parts;
    }
}
