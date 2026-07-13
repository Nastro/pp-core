<?php

namespace PP\Lib\Template\SmartyToTwig;

/**
 * Outcome of a single template conversion.
 *
 * @package PP\Lib\Template\SmartyToTwig
 */
class ConversionResult
{
    /** @var string converted twig source */
    public $twig = '';

    /** @var ConversionIssue[] */
    public $issues = [];

    /**
     * @return bool true when no manual intervention is required
     */
    public function isClean()
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === ConversionIssue::ERROR) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $severity
     * @return ConversionIssue[]
     */
    public function getIssues($severity = null)
    {
        if ($severity === null) {
            return $this->issues;
        }

        return array_values(array_filter($this->issues, function (ConversionIssue $issue) use ($severity) {
            return $issue->severity === $severity;
        }));
    }
}
