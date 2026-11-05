<?php

namespace NourApi\Installer;

use RuntimeException;

/** Fill a blank application key without changing an existing key. */
class EnvironmentFile
{
    public function prepare(string $directory): void
    {
        $destination = $directory.'/.env';
        if (is_link($destination)) {
            throw new RuntimeException('Refusing to configure a symbolic .env link.');
        }
        $exists = file_exists($destination);
        $template = $directory.'/.env.example';
        if (! $exists && ! is_file($template)) {
            throw new RuntimeException('The installed skeleton is missing .env.example.');
        }
        $stream = fopen($destination, $exists ? 'r+' : 'x+');
        if ($stream === false) {
            throw new RuntimeException('Cannot open .env for configuration.');
        }
        try {
            if (! flock($stream, LOCK_EX)) {
                throw new RuntimeException('Cannot lock .env.');
            }
            $contents = $exists ? stream_get_contents($stream) : file_get_contents($template);
            if ($contents === false) {
                throw new RuntimeException('Cannot read environment configuration.');
            }
            preg_match_all('/^APP_KEY=(.*)$/m', $contents, $keys);
            if (count($keys[0]) !== 1) {
                throw new RuntimeException('Expected exactly one APP_KEY entry in environment configuration.');
            }
            if (trim($keys[1][0], " \t\r\n\"'") === '') {
                $contents = preg_replace_callback('/^APP_KEY=.*$/m', function () {
                    return 'APP_KEY=base64:'.base64_encode(random_bytes(32));
                }, $contents);
            } elseif ($exists) {
                return;
            }
            if (! chmod($destination, 0600)) {
                throw new RuntimeException('Cannot secure .env permissions.');
            }
            rewind($stream);
            if (fwrite($stream, $contents) !== strlen($contents) || ! ftruncate($stream, strlen($contents)) || ! fflush($stream)) {
                throw new RuntimeException('Cannot write the complete .env configuration.');
            }
        } finally {
            fclose($stream);
        }
    }
}
