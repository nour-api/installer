<?php

namespace NourApi\Installer;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class NewCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('new')
            ->setDescription('Create a new Nour-API application from nour-api/nour-api')
            ->addArgument('name', InputArgument::REQUIRED, 'Directory / project name')
            ->addOption('project-version', null, InputOption::VALUE_REQUIRED, 'Skeleton version constraint (default: latest)', 'latest')
            ->addOption('no-git', null, InputOption::VALUE_NONE, 'Skip Git initialization')
            ->addOption('prefer-source', null, InputOption::VALUE_NONE, 'Use --prefer-source instead of --prefer-dist')
            ->addOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Pass --ignore-platform-reqs to Composer')
            ->addOption('no-scripts', null, InputOption::VALUE_NONE, 'Pass --no-scripts to Composer')
            ->addOption('no-plugins', null, InputOption::VALUE_NONE, 'Pass --no-plugins to Composer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');

        if (trim($name) === '' || str_contains($name, "\0")) {
            $io->error('Provide a non-empty project path without null bytes.');
            return Command::INVALID;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            $io->error('Cannot determine the current directory.');
            return Command::FAILURE;
        }
        $target = Path::makeAbsolute($name, $cwd);
        if (file_exists($target) || is_link($target)) {
            $io->error('The project path already exists. No files were changed.');
            return Command::FAILURE;
        }

        $composer = (new ExecutableFinder)->find('composer');
        if ($composer === null) {
            $io->error('Composer was not found in PATH. Install Composer before creating a project.');
            return Command::FAILURE;
        }

        $cmd = [$composer, 'create-project', '--no-interaction'];
        $cmd[] = $input->getOption('prefer-source') ? '--prefer-source' : '--prefer-dist';
        foreach (['ignore-platform-reqs', 'no-scripts', 'no-plugins'] as $option) {
            if ($input->getOption($option)) {
                $cmd[] = '--'.$option;
            }
        }
        $cmd = array_merge($cmd, ['--', 'nour-api/nour-api', $target]);
        $version = (string) $input->getOption('project-version');
        if ($version !== 'latest') {
            if (trim($version) === '') {
                $io->error('The version constraint cannot be empty.');
                return Command::INVALID;
            }
            $cmd[] = $version;
        }

        try {
            if ($this->runProcess($cmd, null, $output, 3600) !== 0) {
                $io->error('Composer create-project failed. Partial files were retained for diagnosis; use a new path to retry.');
                return Command::FAILURE;
            }

            (new EnvironmentFile)->prepare($target);

            if (! $input->getOption('no-git')) {
                if ($this->runProcess(['git', 'init', '-q'], $target, $output) !== 0) {
                    $io->error('Project files are ready, but Git initialization failed. Retry git init in the project.');
                    return Command::FAILURE;
                }
                $io->writeln('Initialized Git repository. Review files before your first commit.');
            }
        } catch (Throwable $e) {
            $io->error('Project setup failed: '.OutputFormatter::escape($e->getMessage()));
            return Command::FAILURE;
        }

        $io->success('Nour-API project is ready.');
        $io->writeln('Project: '.OutputFormatter::escape($target));
        $io->writeln('From the project directory, run: php -S localhost:8000 -t public');
        return Command::SUCCESS;
    }

    /** Run an argument-array process without shell expansion or a TTY. */
    protected function runProcess(array $command, ?string $cwd, OutputInterface $output, int $timeout = 1800): int
    {
        $process = new Process($command, $cwd, null, null, $timeout);
        $process->run(function ($type, $buffer) use ($output) {
            $output->write($buffer, false, OutputInterface::OUTPUT_RAW);
        });

        return $process->getExitCode() ?? Command::FAILURE;
    }
}
