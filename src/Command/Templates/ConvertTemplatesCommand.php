<?php

namespace PP\Command\Templates;

use PP\Lib\Command\AbstractCommand;
use PP\Lib\Template\SmartyToTwig\Converter;
use PP\Lib\Template\SmartyToTwig\ConversionIssue;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Converts Smarty 2 `.tmpl` templates into Twig `.twig` ones.
 *
 * Usage:
 *   pp templates:convert                        # BASEPATH/local/templates
 *   pp templates:convert local/templates/lt     # subtree only
 *   pp templates:convert --dry-run              # report without writing
 *   pp templates:convert --verify               # render both engines and diff
 *
 * Class ConvertTemplatesCommand
 * @package PP\Command\Templates
 */
class ConvertTemplatesCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('templates:convert')
            ->setDescription('Convert Smarty 2 templates to Twig')
            ->setHelp('Converts .tmpl templates to .twig, reporting constructs that need manual porting')
            ->addArgument('path', InputArgument::OPTIONAL, 'templates directory or single .tmpl file, relative to project root')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'do not write .twig files')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'overwrite existing .twig files')
            ->addOption('verify', null, InputOption::VALUE_NONE, 'render each converted template with Smarty and Twig and compare html')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'byte-exact comparison on --verify (default ignores whitespace)');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->resolvePath($input->getArgument('path'));

        if ($root === null) {
            $output->writeln('<error>Path not found: ' . $input->getArgument('path') . '</error>');
            return Command::INVALID;
        }

        $files = $this->collectTemplates($root);

        if (!count($files)) {
            $output->writeln('<comment>No .tmpl templates found in ' . $root . '</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Converting <info>%d</info> template(s) from <info>%s</info>', count($files), $root));
        $output->writeln('');

        $converter = new Converter();
        $stats = ['clean' => 0, 'warnings' => 0, 'manual' => 0, 'skipped' => 0];
        $converted = [];

        foreach ($files as $file) {
            $relative = ltrim(str_replace($root, '', $file), '/');
            $target = preg_replace('/\.tmpl$/', '.twig', $file);

            if (file_exists($target) && !$input->getOption('force') && !$input->getOption('dry-run')) {
                $output->writeln("<comment>SKIP</comment>    {$relative} — .twig already exists (use --force)");
                $stats['skipped']++;
                continue;
            }

            $result = $converter->convertSource(file_get_contents($file));

            $errors = $result->getIssues(ConversionIssue::ERROR);
            $warnings = $result->getIssues(ConversionIssue::WARNING);

            if (count($errors)) {
                $stats['manual']++;
                $output->writeln("<error>MANUAL</error>  {$relative}");
            } elseif (count($warnings)) {
                $stats['warnings']++;
                $output->writeln("<comment>CHECK</comment>   {$relative}");
            } else {
                $stats['clean']++;
                $output->writeln("<info>OK</info>      {$relative}");
            }

            foreach ($result->issues as $issue) {
                $tag = $issue->severity === ConversionIssue::ERROR ? 'error' : 'comment';
                $output->writeln(sprintf(
                    '        <%s>line %d</%s>: %s',
                    $tag,
                    $issue->line,
                    $tag,
                    $issue->reason
                ));
                $output->writeln('            ' . str_replace("\n", ' ', $issue->fragment), OutputInterface::VERBOSITY_VERBOSE);
            }

            if (!$input->getOption('dry-run')) {
                file_put_contents($target, $result->twig);
                $converted[] = $file;
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            'Done: <info>%d clean</info>, <comment>%d with warnings</comment>, <error>%d need manual porting</error>, %d skipped',
            $stats['clean'],
            $stats['warnings'],
            $stats['manual'],
            $stats['skipped']
        ));

        if ($input->getOption('verify') && !$input->getOption('dry-run')) {
            $output->writeln('');
            return VerifyTemplatesCommand::verifyFiles(
                $output,
                $root,
                $converted,
                (bool)$input->getOption('strict')
            );
        }

        return $stats['manual'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param string|null $path
     * @return string|null absolute existing path
     */
    protected function resolvePath($path)
    {
        if (empty($path)) {
            $default = rtrim(BASEPATH, '/') . '/local/templates';
            return is_dir($default) ? $default : null;
        }

        foreach ([$path, rtrim(BASEPATH, '/') . '/' . ltrim($path, '/')] as $candidate) {
            if (file_exists($candidate)) {
                return rtrim(realpath($candidate), '/');
            }
        }

        return null;
    }

    /**
     * @param string $root dir or single file
     * @return string[] absolute .tmpl paths
     */
    protected function collectTemplates($root)
    {
        if (is_file($root)) {
            return preg_match('/\.tmpl$/', $root) ? [$root] : [];
        }

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

        return $files;
    }
}
