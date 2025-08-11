<?php

namespace NourApi\Installer;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class NewCommand extends Command
{
    // remove: protected static $defaultName = 'new';

    public function __construct()
    {
        parent::__construct('new');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Create a new Nour-API application from nour-api/nour-api')
            ->addArgument('name', InputArgument::REQUIRED, 'Directory / project name')
            ->addOption('ref', 'r', InputOption::VALUE_REQUIRED, 'Skeleton tag/branch (default: latest)', 'latest')
            ->addOption('no-git', null, InputOption::VALUE_NONE, 'Skip Git initialization')
            ->addOption('prefer-source', null, InputOption::VALUE_NONE, 'Use --prefer-source instead of --prefer-dist')
            ->addOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Pass --ignore-platform-reqs to Composer')
            ->addOption('no-scripts', null, InputOption::VALUE_NONE, 'Pass --no-scripts to Composer')
            ->addOption('no-plugins', null, InputOption::VALUE_NONE, 'Pass --no-plugins to Composer');
    }

    protected function execute(InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');

        if ($name === '') {
            $io->error('Project name is required.');
            return Command::FAILURE;
        }
        if (is_dir($name) || file_exists($name)) {
            $io->error("Path '{$name}' already exists.");
            return Command::FAILURE;
        }

        $package = 'nour-api/nour-api';
        $refOpt = (string) $input->getOption('ref');
        $ref    = $refOpt === 'latest' ? $package : "{$package}:{$refOpt}";

        $io->title("Creating Nour-API app: {$name}");

        // Build composer create-project command
        $cmd = ['composer', 'create-project', $ref, $name];
        $cmd[] = $input->getOption('prefer-source') ? '--prefer-source' : '--prefer-dist';
        if ($input->getOption('ignore-platform-reqs')) $cmd[] = '--ignore-platform-reqs';
        if ($input->getOption('no-scripts'))           $cmd[] = '--no-scripts';
        if ($input->getOption('no-plugins'))           $cmd[] = '--no-plugins';

        $exit = $this->runProcess($cmd, null, $output, 3600);
        if ($exit !== 0) {
            $io->error('Composer create-project failed.');
            return Command::FAILURE;
        }

        $cwd = getcwd() . DIRECTORY_SEPARATOR . $name;

        // Post steps: copy .env if present; run your framework key:generate if applicable; init git.
        if (is_file($cwd.'/.env.example') && !is_file($cwd.'/.env')) {
            @copy($cwd.'/.env.example', $cwd.'/.env');
            $io->writeln('Created .env from .env.example');
        }

        // Example: if your skeleton includes a CLI command
        if (is_file($cwd.'/artisan')) {
            $this->runProcess(['php', 'artisan', 'migrate'], $cwd, $output);
        }

        if (! $input->getOption('no-git')) {
            $this->runProcess(['git', 'init', '-q'], $cwd, $output);
            $this->runProcess(['git', 'add', '-A'], $cwd, $output);
            $this->runProcess(['git', 'commit', '-m', 'Initialize Nour-API app'], $cwd, $output);
            $io->writeln('Initialized Git repository');
        }

        $io->success('Nour-API project is ready √');
        $io->writeln("Next steps:");
        $io->writeln("  cd {$name}");
        $io->writeln("  php -S localhost:8000 -t public   # or your preferred server");
        return Command::SUCCESS;
    }

    /** Helper: run a process and stream output (does NOT override Symfony Command::run) */
    private function runProcess(array $cmd, ?string $cwd, \Symfony\Component\Console\Output\OutputInterface $out, int $timeout = 1800): int
    {
        $out->writeln('<comment>$ '.implode(' ', array_map(
            fn($p) => preg_match('/\s/', $p) ? "\"{$p}\"" : $p,
            $cmd
        )).'</comment>');

        $proc = new Process($cmd, $cwd, null, null, $timeout);
        if (method_exists($proc, 'setTty')) {
            try { $proc->setTty(\function_exists('posix_isatty') && @posix_isatty(STDOUT)); } catch (\Throwable) {}
        }
        $proc->run(fn($type, $buffer) => $out->write($buffer));
        return $proc->getExitCode() ?? 1;
    }
}
