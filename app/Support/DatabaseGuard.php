<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Catches the app being pointed at a database that is real but empty.
 *
 * Herd rewrites the DB_* block in .env when it manages the site, and the resulting schema
 * answers every query happily with nothing in it. That is indistinguishable from the
 * scrape having lost everything, and it is what led to the database being hardcoded once
 * before. Comparing against the pre-MySQL SQLite file turns the guess into a fact: if that
 * file still holds listings and the configured database does not, the configuration is
 * wrong rather than the data being gone.
 */
class DatabaseGuard
{
    public static function verify(): void
    {
        $connection = config('database.default');

        // Nothing to compare against when the fallback file is itself the live database,
        // which is the case for the in-memory suite and for anyone deliberately on SQLite.
        if (self::isFallbackFile($connection)) {
            return;
        }

        try {
            if (DB::connection($connection)->table('listings')->exists()) {
                return;
            }

            if (DB::connection($connection)->table('search_profiles')->exists()) {
                return;
            }
        } catch (Throwable) {
            // An unreachable server or a schema still awaiting migrate has its own, already
            // obvious error. Only the silent-empty case is worth interrupting.
            return;
        }

        $fallback = self::fallbackCount();

        if ($fallback === null || $fallback === 0) {
            return;
        }

        $name = config("database.connections.{$connection}.database");

        throw new RuntimeException(
            "The '{$connection}' connection is pointing at '{$name}', which has no listings and "
            .'no search profile, while '.database_path('database.sqlite')." still holds {$fallback} "
            .'listings. This is almost certainly a wrong DB_* block in .env rather than lost data '
            .'— Herd rewrites that block when it manages the site. Check DB_DATABASE still names '
            .'the right schema, then reload. Nothing has been deleted.'
        );
    }

    private static function isFallbackFile(string $connection): bool
    {
        if (config("database.connections.{$connection}.driver") !== 'sqlite') {
            return false;
        }

        $configured = config("database.connections.{$connection}.database");

        return $configured === ':memory:'
            || realpath((string) $configured) === realpath(database_path('database.sqlite'));
    }

    /** Listings in the pre-MySQL file, or null if it cannot be read at all. */
    private static function fallbackCount(): ?int
    {
        $path = database_path('database.sqlite');

        if (! is_readable($path) || ! extension_loaded('pdo_sqlite')) {
            return null;
        }

        try {
            return (int) DB::connectUsing('fallback_sqlite', [
                'driver' => 'sqlite',
                'database' => $path,
                'foreign_key_constraints' => false,
            ], false)->table('listings')->count();
        } catch (Throwable) {
            return null;
        }
    }
}
