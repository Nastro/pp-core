<?php

namespace PP\Lib\Html\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig filters/functions mimicking Smarty 2 modifiers, so templates
 * converted from Smarty keep rendering byte-identical output.
 *
 * Modifiers that are plain PHP functions (trim, quot, json_encode, ...)
 * are resolved by TwigLayout undefined-filter fallback and are
 * intentionally not duplicated here.
 *
 * @package PP\Lib\Html\Twig
 */
class SmartyCompatExtension extends AbstractExtension
{
    /**
     * {@inheritdoc}
     */
    public function getFilters()
    {
        return [
            new TwigFilter('smarty_escape', [$this, 'escape'], ['is_safe' => ['all']]),
            new TwigFilter('smarty_capitalize', [$this, 'capitalize']),
            new TwigFilter('cat', [$this, 'cat']),
            new TwigFilter('smarty_replace', [$this, 'replace']),
            new TwigFilter('regex_replace', [$this, 'regexReplace']),
            new TwigFilter('strip', [$this, 'strip']),
            new TwigFilter('strip_tags', [$this, 'stripTags']),
            new TwigFilter('date_format', [$this, 'dateFormat']),
            new TwigFilter('string_format', [$this, 'stringFormat']),
            new TwigFilter('truncate', [$this, 'truncate']),
            new TwigFilter('indent', [$this, 'indent']),
            new TwigFilter('spacify', [$this, 'spacify']),
            new TwigFilter('wordwrap', [$this, 'smartyWordwrap']),
            new TwigFilter('count', [$this, 'count']),
            new TwigFilter('sizeof', [$this, 'count']),
            new TwigFilter('isset', [$this, 'issetModifier']),
            new TwigFilter('empty', [$this, 'emptyModifier']),
            new TwigFilter('smarty_default', [$this, 'defaultModifier']),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getFunctions()
    {
        return [
            new TwigFunction('constant', 'constant'),
        ];
    }

    /**
     * smarty_modifier_escape
     *
     * @param mixed $string
     * @param string $type
     * @return string
     */
    public function escape($string, $type = 'html')
    {
        $string = (string)$string;

        switch ($type) {
            case 'htmlall':
                return htmlentities($string, ENT_QUOTES, DEFAULT_CHARSET);

            case 'url':
                return rawurlencode($string);

            case 'urlpathinfo':
                return str_replace('%2F', '/', rawurlencode($string));

            case 'quotes':
                // escape unescaped single quotes
                return preg_replace("%(?<!\\\\)'%", "\\'", $string);

            case 'hex':
                $return = '';
                foreach (str_split($string) as $char) {
                    $return .= '%' . bin2hex($char);
                }
                return $return;

            case 'hexentity':
                $return = '';
                foreach (str_split($string) as $char) {
                    $return .= '&#x' . bin2hex($char) . ';';
                }
                return $return;

            case 'decentity':
                $return = '';
                foreach (str_split($string) as $char) {
                    $return .= '&#' . ord($char) . ';';
                }
                return $return;

            case 'javascript':
                return strtr($string, [
                    '\\' => '\\\\',
                    "'" => "\\'",
                    '"' => '\\"',
                    "\r" => '\\r',
                    "\n" => '\\n',
                    '</' => '<\/',
                ]);

            case 'mail':
                return str_replace(['@', '.'], [' [AT] ', ' [DOT] '], $string);

            case 'nonstd':
                $return = '';
                for ($i = 0, $len = strlen($string); $i < $len; $i++) {
                    $ord = ord($string[$i]);
                    if ($ord >= 126) {
                        $return .= '&#' . $ord . ';';
                    } else {
                        $return .= $string[$i];
                    }
                }
                return $return;

            case 'html':
            default:
                return htmlspecialchars($string, ENT_QUOTES, DEFAULT_CHARSET);
        }
    }

    /**
     * smarty_modifier_capitalize — ucfirst per word, keeping the rest of
     * the word intact (unlike twig `title`/php ucwords); words containing
     * digits are left untouched unless $ucDigits is set.
     *
     * @param mixed $string
     * @param bool $ucDigits
     * @return string
     */
    public function capitalize($string, $ucDigits = false)
    {
        return preg_replace_callback(
            "!'?\\b\\w(\\w|')*\\b!",
            static function ($match) use ($ucDigits) {
                if ((substr($match[0], 0, 1) !== "'" && !preg_match('!\d!', $match[0])) || $ucDigits) {
                    return ucfirst($match[0]);
                }

                return $match[0];
            },
            (string)$string
        );
    }

    /**
     * smarty_modifier_cat
     *
     * @param mixed $string
     * @param mixed $cat
     * @return string
     */
    public function cat($string, $cat = '')
    {
        return (string)$string . (string)$cat;
    }

    /**
     * smarty_modifier_replace ($string|replace:'a':'b')
     *
     * @param mixed $string
     * @param string $search
     * @param string $replace
     * @return string
     */
    public function replace($string, $search, $replace)
    {
        return str_replace((string)$search, (string)$replace, (string)$string);
    }

    /**
     * smarty_modifier_regex_replace
     *
     * @param mixed $string
     * @param string|array $search
     * @param string|array $replace
     * @return string
     */
    public function regexReplace($string, $search, $replace)
    {
        // mimic Smarty 2 protection against eval-modifier
        foreach ((array)$search as $pattern) {
            if (($pos = strpos((string)$pattern, "\0")) !== false) {
                $pattern = substr((string)$pattern, 0, $pos);
            }
            if (preg_match('!([a-zA-Z\s]+)$!s', (string)$pattern, $match) && (strpos($match[1], 'e') !== false)) {
                trigger_error("regex_replace: 'e' modifier is not allowed", E_USER_WARNING);
                return (string)$string;
            }
        }

        return preg_replace($search, $replace, (string)$string);
    }

    /**
     * smarty_modifier_strip — collapse whitespace runs.
     *
     * @param mixed $string
     * @param string $replace
     * @return string
     */
    public function strip($string, $replace = ' ')
    {
        return preg_replace('!\s+!' . REGEX_MOD, $replace, (string)$string);
    }

    /**
     * smarty_modifier_strip_tags (replaces tags with space by default)
     *
     * @param mixed $string
     * @param bool $replaceWithSpace
     * @return string
     */
    public function stripTags($string, $replaceWithSpace = true)
    {
        if ($replaceWithSpace) {
            return preg_replace('!<[^>]*?>!', ' ', (string)$string);
        }

        return strip_tags((string)$string);
    }

    /**
     * smarty_modifier_string_format
     *
     * @param mixed $string
     * @param string $format
     * @return string
     */
    public function stringFormat($string, $format)
    {
        return sprintf($format, $string);
    }

    /**
     * smarty_modifier_truncate
     *
     * @param mixed $string
     * @param int $length
     * @param string $etc
     * @param bool $breakWords
     * @param bool $middle
     * @return string
     */
    public function truncate($string, $length = 80, $etc = '...', $breakWords = false, $middle = false)
    {
        $string = (string)$string;

        if ($length == 0) {
            return '';
        }

        if (mb_strlen($string, DEFAULT_CHARSET) > $length) {
            $length -= min($length, mb_strlen($etc, DEFAULT_CHARSET));

            if (!$breakWords && !$middle) {
                $string = preg_replace('/\s+?(\S+)?$/' . REGEX_MOD, '', mb_substr($string, 0, $length + 1, DEFAULT_CHARSET));
            }

            if (!$middle) {
                return mb_substr($string, 0, $length, DEFAULT_CHARSET) . $etc;
            }

            return mb_substr($string, 0, (int)($length / 2), DEFAULT_CHARSET)
                . $etc
                . mb_substr($string, -(int)($length / 2), null, DEFAULT_CHARSET);
        }

        return $string;
    }

    /**
     * smarty_modifier_indent
     *
     * @param mixed $string
     * @param int $chars
     * @param string $char
     * @return string
     */
    public function indent($string, $chars = 4, $char = ' ')
    {
        return preg_replace('!^!m', str_repeat($char, $chars), (string)$string);
    }

    /**
     * smarty_modifier_spacify
     *
     * @param mixed $string
     * @param string $spacifyChar
     * @return string
     */
    public function spacify($string, $spacifyChar = ' ')
    {
        return implode($spacifyChar, preg_split('//u', (string)$string, -1, PREG_SPLIT_NO_EMPTY));
    }

    /**
     * smarty_modifier_wordwrap
     *
     * @param mixed $string
     * @param int $length
     * @param string $break
     * @param bool $cut
     * @return string
     */
    public function smartyWordwrap($string, $length = 80, $break = "\n", $cut = false)
    {
        return wordwrap((string)$string, $length, $break, $cut);
    }

    /**
     * smarty_modifier_date_format (strftime-compatible, without deprecated strftime)
     *
     * @param mixed $string timestamp or parsable date
     * @param string $format strftime format
     * @param mixed $default value used when $string is empty
     * @return string
     */
    public function dateFormat($string, $format = '%b %e, %Y', $default = null)
    {
        if ($string != '') {
            $timestamp = $this->makeTimestamp($string);
        } elseif ($default != '') {
            $timestamp = $this->makeTimestamp($default);
        } else {
            return '';
        }

        return $this->strftimeCompat($format, $timestamp);
    }

    /**
     * @param mixed $value
     * @return int
     */
    protected function makeTimestamp($value)
    {
        if (empty($value)) {
            return time();
        }

        if (is_numeric($value) && (int)$value == $value) {
            return (int)$value;
        }

        $time = strtotime((string)$value);
        if ($time === false || $time === -1) {
            return time();
        }

        return $time;
    }

    /**
     * Minimal strftime replacement for the specifiers used in Smarty templates.
     *
     * @param string $format
     * @param int $timestamp
     * @return string
     */
    protected function strftimeCompat($format, $timestamp)
    {
        static $map = [
            '%a' => 'D', '%A' => 'l', '%d' => 'd', '%u' => 'N', '%w' => 'w',
            '%b' => 'M', '%B' => 'F', '%h' => 'M', '%m' => 'm',
            '%y' => 'y', '%Y' => 'Y', '%C' => '',
            '%H' => 'H', '%I' => 'h', '%l' => 'g', '%M' => 'i', '%p' => 'A', '%P' => 'a',
            '%S' => 's', '%s' => 'U',
            '%D' => 'm/d/y', '%F' => 'Y-m-d', '%R' => 'H:i', '%T' => 'H:i:s',
            '%n' => "\n", '%t' => "\t", '%%' => '%',
        ];

        $result = '';
        $length = strlen($format);

        for ($i = 0; $i < $length; $i++) {
            if ($format[$i] === '%' && $i + 1 < $length) {
                $spec = substr($format, $i, 2);

                // strftime %e is space-padded, date('j') is not
                if ($spec === '%e') {
                    $result .= sprintf('%2d', (int)date('j', $timestamp));
                    $i++;
                    continue;
                }

                if (isset($map[$spec])) {
                    $result .= date($map[$spec], $timestamp);
                    $i++;
                    continue;
                }
            }
            $result .= $format[$i];
        }

        return $result;
    }

    /**
     * @param mixed $value
     * @return int
     */
    public function count($value)
    {
        return is_countable($value) ? count($value) : 0;
    }

    /**
     * smarty_modifier_isset
     *
     * @param mixed $var
     * @return bool
     */
    public function issetModifier($var = null)
    {
        return $var !== null;
    }

    /**
     * smarty_modifier_empty
     *
     * @param mixed $var
     * @return bool
     */
    public function emptyModifier($var = null)
    {
        return empty($var);
    }

    /**
     * smarty_modifier_default — unlike twig native `default`, treats
     * empty string as missing value (Smarty 2 semantics).
     *
     * @param mixed $string
     * @param mixed $default
     * @return mixed
     */
    public function defaultModifier($string = null, $default = '')
    {
        if (!isset($string) || $string === '') {
            return $default;
        }

        return $string;
    }
}
