<?php

namespace App\Support;

use RuntimeException;

/**
 * Minimal KEY=VALUE env-file loader used by `mywhoosh:cron` so an unattended
 * job can keep its credentials in a dedicated file instead of the shared .env.
 *
 * Supported syntax:
 *   MYWHOOSH_EMAIL=rider@example.com
 *   export MYWHOOSH_PASSWORD="some secret"
 *   GARMIN_EMAIL='team@example.com'
 *   EMPTY=
 *   # whole-line comments, blank lines — skipped
 *   # values are trimmed; matching surrounding quotes are stripped (no escapes)
 *   # inline comments are NOT supported (keep comments on their own line)
 */
final class EnvFile
{
    /**
     * Parse an env file into key => value pairs.
     *
     * @return array<string, string>
     *
     * @throws RuntimeException when the file is unreadable
     */
    public static function read(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("env file not readable: {$path}");
        }

        $items = [];

        foreach (preg_split('/\R/', $contents) as $line) {
            $line = ltrim((string) $line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $eq = strpos($line, '=');
            if ($eq === false || $eq === 0) {
                continue; // not a KEY=VALUE assignment — ignore
            }

            $key = trim(substr($line, 0, $eq));
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            $items[$key] = self::unquote(trim(substr($line, $eq + 1)));
        }

        return $items;
    }

    /**
     * Push parsed values into the active environment so `env()`, `getenv()`
     * and Laravel's dotenv adapters all see them.
     *
     * @param  array<string, string>  $items
     */
    public static function apply(array $items): void
    {
        foreach ($items as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private static function unquote(string $value): string
    {
        if (mb_strlen($value) >= 2) {
            $first = mb_substr($value, 0, 1);
            $last = mb_substr($value, -1);

            if (in_array($first, ['"', "'"], true) && $first === $last) {
                $value = mb_substr($value, 1, -1);
            }
        }

        return $value;
    }
}
