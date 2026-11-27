<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `phpdup init` — generate a starter phpdup.json for the current project.
 *
 * Auto-detects the framework via ProjectProfileDetector and bakes the
 * profile's exclude/kinds/min_block_size/min_cluster_impact into the
 * generated file. No `profile` key is written — settings are inline.
 */
final class InitCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->setName('init')
            ->setDescription('Scaffold a phpdup.json in the current directory.')
            ->setHelp(<<<'HELP'
Generate a <info>phpdup.json</info> tuned for the current project.

The command sniffs the working directory for known framework markers
(Laravel, Symfony, Drupal, WordPress, …) and produces a config with
appropriate <comment>exclude</comment> globs, <comment>kinds</comment>,
<comment>min_block_size</comment>, and <comment>min_cluster_impact</comment>.

Use <info>--profile=<name></info> to override auto-detection.
Use <info>--force</info> to overwrite an existing phpdup.json without prompting.
HELP
            )
            ->addOption(
                'profile',
                'p',
                InputOption::VALUE_REQUIRED,
                'Manually specify a profile (laravel|symfony|drupal|wordpress|generic|db-aware-*)',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Overwrite phpdup.json if it already exists (no interactive prompt)',
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd = getcwd();
        if ($cwd === false) {
            $output->writeln('<error>phpdup init: cannot determine current working directory</error>');
            return Command::FAILURE;
        }

        $configFile = $cwd . DIRECTORY_SEPARATOR . 'phpdup.json';

        if (is_file($configFile) && !$input->getOption('force')) {
            // once-S98 (php-dup-finder-once-123, QA-L5b): "nothing to do"
            // used to exit 1 with an <error> tag even though re-running
            // init over an already-present config writes nothing and
            // breaks nothing. Honest no-op: announce the nothing-written
            // state and exit 0 — scripts that run `phpdup init` before
            // every analysis no longer see a spurious failure. --force
            // keeps its overwrite contract; unknown-profile and write
            // failures still exit 1 (loud failure posture untouched).
            $output->writeln("<comment>phpdup init: phpdup.json already exists — nothing written (use --force to overwrite)</comment>");
            return Command::SUCCESS;
        }

        $profileOpt = $input->getOption('profile');
        if ($profileOpt !== null) {
            $profileName = (string)$profileOpt;
        } else {
            $detector = new ProjectProfileDetector();
            $profileName = $detector->detectIn($cwd) ?? 'generic';
            $output->writeln("<info>phpdup</info> auto-detected profile: " . strtoupper($profileName));
        }

        $registry = ProfileRegistry::bundled();
        if (!in_array($profileName, $registry->listAvailable(), true)) {
            $output->writeln(sprintf(
                '<error>phpdup init: unknown profile "%s" (available: %s)</error>',
                $profileName,
                implode('|', $registry->listAvailable()),
            ));
            return Command::FAILURE;
        }

        $profileData = $registry->load($profileName);

        $written = $this->writeConfig($configFile, $profileData, $output);
        if (!$written) {
            return Command::FAILURE;
        }

        $output->writeln("<info>phpdup</info> config written: {$configFile}");
        return Command::SUCCESS;
    }

    /**
     * Write the phpdup.json file from profile data.
     *
     * @param array<string, mixed> $profileData
     * @return bool true on success, false on failure
     */
    private function writeConfig(string $path, array $profileData, OutputInterface $output): bool
    {
        $exclude = $profileData['exclude'] ?? null;
        $kinds = $profileData['kinds'] ?? null;
        $minBlockSize = $profileData['min_block_size'] ?? null;
        $minClusterImpact = $profileData['min_cluster_impact'] ?? null;

        $out = [];
        if ($exclude !== null) {
            $out['exclude'] = $exclude;
        }
        if ($kinds !== null) {
            $out['kinds'] = $kinds;
        }
        if ($minBlockSize !== null) {
            $out['min_block_size'] = $minBlockSize;
        }
        if ($minClusterImpact !== null) {
            $out['min_cluster_impact'] = $minClusterImpact;
        }

        $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $output->writeln('<error>phpdup init: failed to encode JSON</error>');
            return false;
        }

        $result = file_put_contents($path, $json . "\n");
        if ($result === false) {
            $output->writeln("<error>phpdup init: failed to write {$path}</error>");
            return false;
        }

        return true;
    }
}
