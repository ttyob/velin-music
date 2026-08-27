<?php

declare(strict_types=1);

namespace app\infrastructure\Database;

use app\domain\System\HealthProbe;
use support\Db;

/**
 * Verifies the SQLite connection invariants required by Velin Music.
 *
 * The probe performs only bounded PRAGMA reads and a schema-version lookup. It never starts
 * a write transaction and is safe to call from readiness checks. Any query failure bubbles
 * to HealthService, which returns a sanitized degraded response. The database path is never
 * included because the endpoint is intentionally unauthenticated.
 */
final class SqliteHealthProbe implements HealthProbe
{
    /**
     * Confirms connectivity, foreign-key enforcement, WAL mode, and configured lock wait.
     *
     * @return array<string, bool|int|string|null> Sanitized SQLite diagnostics.
     */
    public function inspect(): array
    {
        $foreignKeys = $this->readPragmaInteger('foreign_keys');
        $busyTimeout = $this->readPragmaInteger('busy_timeout');
        $journalMode = strtolower($this->readPragmaString('journal_mode'));

        $migration = Db::table('phinxlog')
            ->orderByDesc('version')
            ->value('version');

        $ready = $foreignKeys === 1 && $journalMode === 'wal' && $busyTimeout > 0;

        return [
            'status' => $ready ? 'ready' : 'misconfigured',
            'driver' => 'sqlite',
            'foreignKeys' => $foreignKeys === 1,
            'journalMode' => $journalMode,
            'busyTimeoutMs' => $busyTimeout,
            'schemaVersion' => $migration === null ? null : (string) $migration,
        ];
    }

    /**
     * @param string $name Hard-coded PRAGMA name owned by this class, never request input.
     */
    private function readPragmaInteger(string $name): int
    {
        $row = Db::selectOne("PRAGMA {$name}");
        $values = (array) $row;

        return (int) reset($values);
    }

    /**
     * @param string $name Hard-coded PRAGMA name owned by this class, never request input.
     */
    private function readPragmaString(string $name): string
    {
        $row = Db::selectOne("PRAGMA {$name}");
        $values = (array) $row;

        return (string) reset($values);
    }
}
