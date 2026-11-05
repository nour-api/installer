<?php

// Optional integration check against sibling local checkouts; never publishes anything.
require __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

$workspace = dirname(__DIR__, 2);
$skeleton = $workspace.'/nour-api';
$framework = $workspace.'/framework';
$composer = (new ExecutableFinder)->find('composer');
if (! is_file($skeleton.'/composer.json') || ! is_file($framework.'/composer.json') || $composer === null) {
    throw new RuntimeException('This check requires sibling nour-api and framework checkouts and Composer.');
}

$temporary = sys_get_temp_dir().'/nour-integration-'.bin2hex(random_bytes(8));
$files = new Filesystem;
$files->mkdir($temporary, 0700);
$run = static function (array $command, ?string $cwd = null, ?array $env = null): string {
    $process = new Process($command, $cwd, $env, null, 300);
    $process->mustRun();
    return $process->getOutput();
};

try {
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($skeleton, FilesystemIterator::SKIP_DOTS),
        static fn ($file) => ! in_array($file->getFilename(), ['.git', 'vendor', '.env', 'composer.local.json', 'composer.local.lock', 'composer.lock', '.phpunit.cache'], true)
            && ! str_ends_with($file->getFilename(), '.log')
    ), RecursiveIteratorIterator::SELF_FIRST);
    $files->mirror($skeleton, $temporary.'/skeleton', $iterator);
    $manifest = json_decode(file_get_contents($temporary.'/skeleton/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $manifest['repositories'] = [[
        'type' => 'path', 'url' => $framework,
        'options' => ['versions' => ['nour-api/framework' => '2.1.1'], 'symlink' => false],
    ]];
    file_put_contents($temporary.'/skeleton/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $repository = json_encode([
        'type' => 'path', 'url' => $temporary.'/skeleton',
        'options' => ['versions' => ['nour-api/nour-api' => '2.1.1'], 'symlink' => false],
    ], JSON_UNESCAPED_SLASHES);
    $files->mkdir($temporary.'/bin');
    $wrapper = "#!/usr/bin/env php\n<?php\nrequire ".var_export(__DIR__.'/../vendor/autoload.php', true).";\n";
    $wrapper .= '$command = array_merge(['.var_export(PHP_BINARY, true).', '.var_export($composer, true).', $argv[1], '.var_export('--repository='.$repository, true).'], array_slice($argv, 2));' . "\n";
    $wrapper .= '$process = new Symfony\\Component\\Process\\Process($command, null, null, null, 300);' . "\n";
    $wrapper .= '$process->run(static function ($type, $buffer) { fwrite($type === "err" ? STDERR : STDOUT, $buffer); }); exit($process->getExitCode() ?? 1);' . "\n";
    file_put_contents($temporary.'/bin/composer', $wrapper);
    chmod($temporary.'/bin/composer', 0700);

    $target = $temporary.'/installed app';
    $output = $run([PHP_BINARY, __DIR__.'/../bin/nour-api', $target, '--no-plugins'], null, [
        'PATH' => $temporary.'/bin'.PATH_SEPARATOR.getenv('PATH'),
    ]);
    if (! str_contains($output, 'project is ready')) {
        throw new RuntimeException('Installer did not report successful completion.');
    }
    $environment = file_get_contents($target.'/.env');
    if (! preg_match('/^APP_KEY=base64:(.+)$/m', $environment, $matches) || strlen(base64_decode($matches[1], true)) !== 32) {
        throw new RuntimeException('Installed application has no valid application key.');
    }
    if (! is_dir($target.'/.git') || $run(['git', 'ls-files'], $target) !== '') {
        throw new RuntimeException('Git initialization must not stage or commit project files.');
    }
    $run(['git', 'check-ignore', '.env'], $target);
    $run([PHP_BINARY, 'artisan', 'list', '--raw'], $target);
    $run([PHP_BINARY, 'vendor/bin/phpunit', '--fail-on-risky', '--fail-on-warning'], $target);
    $smoke = '$app = require "bootstrap/app.php"; $response = $app->handle(NourApi\\Framework\\Http\\Request::create("/")); if ($response->getStatusCode() !== 200 || !str_starts_with($response->getContent(), "Nour-API")) { exit(1); }';
    $run([PHP_BINARY, '-r', $smoke], $target);
    echo "PASS: real Composer create-project, private application key, empty Git index, ignored .env, Artisan list, generated application tests and HTTP dispatch using local framework\n";
} finally {
    $files->remove($temporary);
}
