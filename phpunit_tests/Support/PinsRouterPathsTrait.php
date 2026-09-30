<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

use LiturgicalCalendar\Api\Router;

/**
 * Pins Router::$apiPath and Router::$apiFilePath to the project for a pure-logic test class.
 *
 * Both are typed statics initialised only while a request is routed, so code that builds the
 * calendars index ({@see \LiturgicalCalendar\Api\Services\CalendarMetadataProvider}) throws
 * "must not be accessed before initialization" outside a handler. Handler tests get this from
 * AbstractHandlerTestCase; a class that only exercises such code in-process uses this instead.
 * Call pinRouterPaths() from setUpBeforeClass() and restoreRouterPaths() from
 * tearDownAfterClass().
 *
 * Restoration is one-way: a typed static cannot be returned to its uninitialised state, so a path
 * that was uninitialised before pinRouterPaths() keeps its pinned value after restoreRouterPaths().
 * A later test class in the same process must therefore not depend on either path being
 * uninitialised; the same already holds after any AbstractHandlerTestCase subclass has run.
 */
trait PinsRouterPathsTrait
{
    private static ?string $pinnedSavedApiPath     = null;
    private static ?string $pinnedSavedApiFilePath = null;

    private static function pinRouterPaths(): void
    {
        self::$pinnedSavedApiPath     = isset(Router::$apiPath) ? Router::$apiPath : null;
        self::$pinnedSavedApiFilePath = isset(Router::$apiFilePath) ? Router::$apiFilePath : null;
        Router::$apiPath              = '';
        Router::$apiFilePath          = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }

    private static function restoreRouterPaths(): void
    {
        if (null !== self::$pinnedSavedApiPath) {
            Router::$apiPath = self::$pinnedSavedApiPath;
        }
        if (null !== self::$pinnedSavedApiFilePath) {
            Router::$apiFilePath = self::$pinnedSavedApiFilePath;
        }
    }
}
