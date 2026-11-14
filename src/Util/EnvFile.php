<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\Util;

/**
 * Minimal, line-based editor for dotenv files. Variables are written inside the bundle block:
 *
 *     ###> psolutions/encrypt-bundle ###
 *     PSOLUTIONS_ENCRYPT_KEY=...
 *     ###< psolutions/encrypt-bundle ###
 */
final class EnvFile
{
    public const BLOCK_START = '###> psolutions/encrypt-bundle ###';
    public const BLOCK_END = '###< psolutions/encrypt-bundle ###';

    /**
     * Whether the file defines the variable (an uncommented `VAR=` or `export VAR=` line).
     */
    public static function has(string $file, string $name): bool
    {
        return is_file($file) && 1 === preg_match(self::pattern($name), (string) file_get_contents($file));
    }

    /**
     * Sets the variable: replaces its line if defined, otherwise adds it to the bundle block (created if missing).
     */
    public static function set(string $file, string $name, string $value): void
    {
        $contents = is_file($file) ? (string) file_get_contents($file) : '';
        $line = $name . '=' . $value;

        if (1 === preg_match(self::pattern($name), $contents)) {
            $contents = preg_replace_callback(
                self::pattern($name),
                static fn (array $m) => $m[1] . $line,
                $contents,
                1
            );
        } elseif (false !== ($end = strpos($contents, self::BLOCK_END))) {
            $contents = substr($contents, 0, $end) . $line . "\n" . substr($contents, $end);
        } else {
            if ('' !== $contents && !str_ends_with($contents, "\n")) {
                $contents .= "\n";
            }
            $contents .= ('' === $contents ? '' : "\n") . self::BLOCK_START . "\n" . $line . "\n" . self::BLOCK_END . "\n";
        }

        if (false === @file_put_contents($file, $contents)) {
            throw new \RuntimeException(sprintf('Cannot write "%s".', $file));
        }
    }

    private static function pattern(string $name): string
    {
        return '/^([ \t]*(?:export[ \t]+)?)' . preg_quote($name, '/') . '[ \t]*=.*$/m';
    }
}
