<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

/**
 * Builds a throwaway shadow of the project root under `sys_get_temp_dir()`, for test
 * classes that exercise handlers which write to — or delete from — `jsondata/`.
 *
 * `Router::$apiFilePath` is the single seam every `JsonData::…->path()` resolves
 * against, so repointing it at a shadow root moves the code under test AND the test's
 * own assertions together. Nothing in the working tree is then written, chmod'ed or
 * deleted, and an interruption at any instant is harmless: the worst outcome is an
 * abandoned temp directory (issues #921, #935).
 *
 * The contract deliberately has no `tearDown` hook of its own: a class that pins
 * `Router::$apiFilePath` must restore it itself (`AbstractHandlerTestCase` does), and
 * a class that allocates a shadow root must discard it in `tearDownAfterClass()`.
 */
trait ShadowProjectRootTrait
{
    /**
     * Allocate a shadow project root containing a real copy of `jsondata/` plus a
     * symlink to the gettext catalogs in `i18n/`.
     *
     * The catalogs are symlinked rather than copied because they are only ever read —
     * no handler and no test writes or chmods them — and copying 1.6 MB of `.mo`/`.po`
     * files per test class would buy nothing. `removeTree()` unlinks symlinks instead
     * of descending them, so the shipped catalogs are never at risk from the cleanup.
     *
     * @param string $realRoot Project root WITH a trailing directory separator, i.e. the
     *                         value `Router::$apiFilePath` holds in production and in
     *                         `AbstractHandlerTestCase::setUpBeforeClass()`.
     * @param string $prefix   Short name identifying the owning test class in the temp
     *                         directory listing, e.g. `litcal-regionaldata-fixture`.
     *
     * @return string The shadow root, WITHOUT a trailing directory separator.
     */
    private static function createShadowProjectRoot(string $realRoot, string $prefix): string
    {
        $shadowRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . '-' . bin2hex(random_bytes(6));

        self::copyTree($realRoot . 'jsondata', $shadowRoot . DIRECTORY_SEPARATOR . 'jsondata');

        if (is_dir($realRoot . 'i18n')) {
            symlink($realRoot . 'i18n', $shadowRoot . DIRECTORY_SEPARATOR . 'i18n');
        }

        return $shadowRoot;
    }

    /** Recursive copy of a directory tree; files only, symlinks are skipped rather than followed. */
    private static function copyTree(string $from, string $to): void
    {
        if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) {
            throw new \RuntimeException('Could not create fixture directory ' . $to);
        }

        foreach (scandir($from) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $source = $from . DIRECTORY_SEPARATOR . $entry;
            $target = $to . DIRECTORY_SEPARATOR . $entry;
            if (is_link($source)) {
                continue;
            }
            if (is_dir($source)) {
                self::copyTree($source, $target);
                continue;
            }
            if (!copy($source, $target)) {
                throw new \RuntimeException('Could not copy ' . $source . ' to ' . $target);
            }
        }
    }

    /**
     * Recursive delete, hard-fenced to `sys_get_temp_dir()`.
     *
     * Symlinks are unlinked, never descended into: a shadow root holds a symlink to the
     * repository's `i18n/` folder, and following it would be the very class of accident
     * this helper exists to remove.
     */
    private static function removeTree(string $dir): void
    {
        $tempDir = realpath(sys_get_temp_dir());
        $target  = realpath($dir);
        if (false === $tempDir || false === $target) {
            return;
        }
        if (!str_starts_with($target . DIRECTORY_SEPARATOR, $tempDir . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException(sprintf('Refusing to delete %s: it is outside %s.', $target, $tempDir));
        }

        foreach (scandir($target) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $target . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
                continue;
            }
            self::removeTree($path);
        }
        rmdir($target);
    }
}
