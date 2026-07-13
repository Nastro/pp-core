<?php

namespace PP\Command\Templates;

use PP\Lib\Command\AbstractCommand;
use PP\Lib\Template\TemplateVerifier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders every converted template with both engines (Smarty 2 and Twig)
 * using an identical variable set and reports html differences.
 *
 * Usage:
 *   pp templates:verify
 *   pp templates:verify local/templates/lt --context=fixtures/vars.json
 *
 * Class VerifyTemplatesCommand
 * @package PP\Command\Templates
 */
class VerifyTemplatesCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('templates:verify')
            ->setDescription('Render templates with Smarty and Twig and compare output')
            ->addArgument('path', InputArgument::OPTIONAL, 'templates directory, relative to project root')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'json file with extra template variables (fixture data)')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'byte-exact comparison (default ignores whitespace)');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');
        $root = empty($path) ? rtrim(BASEPATH, '/') . '/local/templates' : $path;

        if (!is_dir($root) && is_dir(rtrim(BASEPATH, '/') . '/' . ltrim((string)$path, '/'))) {
            $root = rtrim(BASEPATH, '/') . '/' . ltrim($path, '/');
        }

        if (!is_dir($root)) {
            $output->writeln('<error>Templates directory not found: ' . $root . '</error>');
            return Command::INVALID;
        }

        $root = rtrim(realpath($root), '/');

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.tmpl$/', $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $extraVars = [];
        if ($contextFile = $input->getOption('context')) {
            if (!file_exists($contextFile)) {
                $output->writeln('<error>Context file not found: ' . $contextFile . '</error>');
                return Command::INVALID;
            }
            $extraVars = json_decode(file_get_contents($contextFile), true);
            if (!is_array($extraVars)) {
                $output->writeln('<error>Context file is not a json object</error>');
                return Command::INVALID;
            }
        }

        return static::verifyFiles($output, $root, $files, (bool)$input->getOption('strict'), $extraVars);
    }

    /**
     * Shared verification runner, also used by templates:convert --verify.
     *
     * @param OutputInterface $output
     * @param string $root templates root directory
     * @param string[] $files absolute .tmpl paths
     * @param bool $strict
     * @param array $extraVars
     * @return int exit code
     */
    public static function verifyFiles(OutputInterface $output, $root, array $files, $strict = false, array $extraVars = [])
    {
        $pairs = [];
        foreach ($files as $file) {
            if (file_exists(preg_replace('/\.tmpl$/', '.twig', $file))) {
                $pairs[] = ltrim(str_replace($root, '', $file), '/');
            }
        }

        if (!count($pairs)) {
            $output->writeln('<comment>Nothing to verify: no .tmpl/.twig pairs found</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Verifying <info>%d</info> template pair(s), Smarty vs Twig%s', count($pairs), $strict ? ' (strict)' : ''));
        $output->writeln('');

        $verifier = new TemplateVerifier([$root . '/'], $strict);
        $verifier->assignDefaults();

        if (count($extraVars)) {
            $verifier->assignVars($extraVars);
        }

        $stats = [
            TemplateVerifier::STATUS_OK => 0,
            TemplateVerifier::STATUS_DIFF => 0,
            TemplateVerifier::STATUS_SMARTY_ERROR => 0,
            TemplateVerifier::STATUS_TWIG_ERROR => 0,
            TemplateVerifier::STATUS_BOTH_ERROR => 0,
        ];

        foreach ($pairs as $template) {
            $row = $verifier->verify($template);
            $stats[$row['status']]++;

            switch ($row['status']) {
                case TemplateVerifier::STATUS_OK:
                    $output->writeln("<info>OK</info>           {$template}");
                    break;

                case TemplateVerifier::STATUS_DIFF:
                    $output->writeln("<error>DIFF</error>         {$template}");
                    $output->writeln('             ' . $row['diff']);
                    break;

                case TemplateVerifier::STATUS_TWIG_ERROR:
                    $output->writeln("<error>TWIG_ERROR</error>   {$template}");
                    $output->writeln('             ' . $row['error']);
                    break;

                case TemplateVerifier::STATUS_SMARTY_ERROR:
                    $output->writeln("<comment>SMARTY_ERROR</comment> {$template}");
                    $output->writeln('             ' . $row['error']);
                    break;

                default:
                    $output->writeln("<comment>BOTH_ERROR</comment>   {$template}");
                    $output->writeln('             ' . $row['error']);
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            'Verification: <info>%d identical</info>, <error>%d different</error>, %d smarty-only errors, <error>%d twig-only errors</error>, %d failed in both',
            $stats[TemplateVerifier::STATUS_OK],
            $stats[TemplateVerifier::STATUS_DIFF],
            $stats[TemplateVerifier::STATUS_SMARTY_ERROR],
            $stats[TemplateVerifier::STATUS_TWIG_ERROR],
            $stats[TemplateVerifier::STATUS_BOTH_ERROR]
        ));

        $failed = $stats[TemplateVerifier::STATUS_DIFF] + $stats[TemplateVerifier::STATUS_TWIG_ERROR];

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
