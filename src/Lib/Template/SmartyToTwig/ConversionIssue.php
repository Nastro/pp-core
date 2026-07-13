<?php

namespace PP\Lib\Template\SmartyToTwig;

/**
 * Single problem found during template conversion.
 *
 * @package PP\Lib\Template\SmartyToTwig
 */
class ConversionIssue
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    /** @var int 1-based template source line */
    public $line;

    /** @var string offending source fragment */
    public $fragment;

    /** @var string human readable explanation */
    public $reason;

    /** @var string self::ERROR — manual intervention required, self::WARNING — review recommended */
    public $severity;

    /**
     * @param int $line
     * @param string $fragment
     * @param string $reason
     * @param string $severity
     */
    public function __construct($line, $fragment, $reason, $severity = self::ERROR)
    {
        $this->line = $line;
        $this->fragment = mb_strlen($fragment) > 80 ? mb_substr($fragment, 0, 77) . '...' : $fragment;
        $this->reason = $reason;
        $this->severity = $severity;
    }
}
