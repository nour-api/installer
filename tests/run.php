<?php

require __DIR__.'/../vendor/autoload.php';

use NourApi\Installer\EnvironmentFile;
use NourApi\Installer\NewCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class FakeNewCommand extends NewCommand
{
    public array $calls = [];
    public int $composerExit = 0;
    public int $gitExit = 0;
    public bool $throw = false;

    protected function runProcess(array $command, ?string $cwd, OutputInterface $output, int $timeout = 1800): int
    {
        $this->calls[] = [$command, $cwd];
        if ($this->throw) {
            throw new RuntimeException('Process could not start.');
        }
        if ($command[1] === 'create-project') {
            if ($this->composerExit !== 0) {
                return $this->composerExit;
            }
            $target = $command[array_search('nour-api/nour-api', $command, true) + 1];
            mkdir($target, 0700, true);
            file_put_contents($target.'/.env.example', "APP_KEY=\nAPP_DEBUG=false\n");
            // Reproduce the current skeleton Composer post-root-package-install hook.
            copy($target.'/.env.example', $target.'/.env');
            return 0;
        }
        return $this->gitExit;
    }
}

$root = sys_get_temp_dir().'/nour-installer-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

try {
    $command = new FakeNewCommand;
    $tester = new CommandTester($command);
    $target = $root.'/project with spaces';
    check($tester->execute(['name' => $target, '--no-git' => true, '--project-version' => '^2.1', '--no-scripts' => true, '--no-plugins' => true]) === 0, 'create-project must succeed');
    check(count($command->calls) === 1, '--no-git must skip Git');
    $args = $command->calls[0][0];
    check(in_array('--no-scripts', $args, true) && in_array('--no-plugins', $args, true), 'Composer flags must be forwarded');
    check(array_slice($args, -4) === ['--', 'nour-api/nour-api', $target, '^2.1'], 'Use separate positional version and absolute path arguments');
    $env = file_get_contents($target.'/.env');
    preg_match('/^APP_KEY=base64:(.+)$/m', $env, $key);
    check(isset($key[1]) && strlen(base64_decode($key[1], true)) === 32, 'Generate a 256-bit key');
    // chmod may leave cached stat metadata on older supported PHP versions.
    clearstatcache(true, $target.'/.env');
    check((fileperms($target.'/.env') & 0777) === 0600, '.env must have private permissions');
    (new EnvironmentFile)->prepare($target);
    check(file_get_contents($target.'/.env') === $env, 'Existing key must be preserved');
    check($tester->execute(['name' => $target]) !== 0 && count($command->calls) === 1, 'Never run Composer on an existing directory');
    $file = $root.'/file';
    file_put_contents($file, 'user data');
    check($tester->execute(['name' => $file]) !== 0 && file_get_contents($file) === 'user data', 'Preserve existing files');
    symlink($root.'/absent', $root.'/link');
    check($tester->execute(['name' => $root.'/link']) !== 0, 'Reject dangling project symlinks');
    check($tester->execute(['name' => ' ']) !== 0, 'Reject empty path');
    check($tester->execute(['name' => $root.'/empty-version', '--project-version' => '']) !== 0, 'Reject empty version');

    $command = new FakeNewCommand;
    $command->composerExit = 42;
    $tester = new CommandTester($command);
    check($tester->execute(['name' => $root.'/failed']) !== 0 && count($command->calls) === 1, 'Composer failure must stop all post steps');
    check(! str_contains($tester->getDisplay(), 'project is ready'), 'Do not report success after failure');
    $command = new FakeNewCommand;
    $command->gitExit = 1;
    $tester = new CommandTester($command);
    check($tester->execute(['name' => $root.'/git-failure']) !== 0, 'Report Git failure');
    check(count($command->calls) === 2 && $command->calls[1][1] === $root.'/git-failure', 'Git cwd must be absolute');
    $command = new FakeNewCommand;
    $command->throw = true;
    check((new CommandTester($command))->execute(['name' => $root.'/exception']) !== 0, 'Report process startup exception');

    mkdir($root.'/fresh');
    file_put_contents($root.'/fresh/.env.example', "APP_KEY=\r\nAPP_NAME=test\r\n");
    (new EnvironmentFile)->prepare($root.'/fresh');
    check(str_contains(file_get_contents($root.'/fresh/.env'), 'APP_KEY=base64:'), 'Create .env when scripts are disabled');
    symlink($file, $root.'/fresh/.env-link');
    unlink($root.'/fresh/.env');
    symlink($file, $root.'/fresh/.env');
    try {
        (new EnvironmentFile)->prepare($root.'/fresh');
        throw new LogicException('Symbolic env must be rejected');
    } catch (RuntimeException $e) {
        check(file_get_contents($file) === 'user data', 'Never follow .env symlinks');
    }
    $autoload = $root.'/proxy-autoload.php';
    file_put_contents($autoload, '<?php echo "COMPOSER-PROXY-AUTOLOAD\\n"; require '.var_export(__DIR__.'/../vendor/autoload.php', true).';');
    $proxy = $root.'/proxy.php';
    file_put_contents($proxy, '<?php $_composer_autoload_path = '.var_export($autoload, true).'; require '.var_export(__DIR__.'/../bin/nour-api', true).';');
    $process = new Process([PHP_BINARY, $proxy, '--help']);
    $process->run();
    check($process->isSuccessful() && str_contains($process->getOutput(), 'COMPOSER-PROXY-AUTOLOAD'), 'Honor Composer global binary proxy autoload path');
    echo "PASS: {$checks} installer regression checks\n";
} finally {
    (new Filesystem)->remove($root);
}
