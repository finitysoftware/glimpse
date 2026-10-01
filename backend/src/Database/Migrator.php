<?php

declare(strict_types=1);

namespace Glimpse\Database;

use PDO;

final readonly class Migrator
{
    private const LOCK = 'glimpse_migrations';
    private const ENFORCEMENT_MIGRATION = '006_enforce_ownership.sql';

    /** @var array<string, callable(PDO): (string|null)> */
    private array $preconditions;

    /** @param array<string, callable(PDO): (string|null)>|null $preconditions Keyed by migration file name; a returned string blocks that migration. */
    public function __construct(private Connection $connection, private string $directory, ?array $preconditions = null)
    {
        $this->preconditions = $preconditions ?? [self::ENFORCEMENT_MIGRATION => self::ownershipBlocker(...)];
    }

    /** Applies pending migrations in order up to and including $target (all when NULL).
     * An unmet precondition stops the run before that migration is recorded; later migrations never skip it.
     */
    public function run(?string $target = null): MigrationResult
    {
        $pdo = $this->connection->get();
        self::lock($pdo);
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARBINARY(190) PRIMARY KEY,
                checksum BINARY(32) NOT NULL,
                completed BOOLEAN NOT NULL DEFAULT FALSE,
                applied_at DATETIME(6) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $files = $this->files();
            $last = $target === null ? (string) array_key_last($files) : $this->resolve($target, array_keys($files));
            $records = $this->records();
            $this->verify($files, $records);
            $applied = [];
            foreach ($files as $version => $sql) {
                if (strcmp($version, $last) > 0) {
                    break;
                }
                if (isset($records[$version])) {
                    continue;
                }
                $precondition = $this->preconditions[$version] ?? null;
                $blocker = $precondition === null ? null : $precondition($pdo);
                if ($blocker !== null) {
                    return new MigrationResult($applied, $version, $blocker);
                }
                // MySQL DDL commits implicitly. Record the attempt before running any statements.
                $pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)')
                    ->execute([$version, hash('sha256', $sql, true)]);
                foreach (explode(';', $sql) as $statement) {
                    if (trim($statement) !== '') {
                        $pdo->exec($statement);
                    }
                }
                $pdo->prepare('UPDATE schema_migrations SET completed = TRUE, applied_at = UTC_TIMESTAMP(6) WHERE version = ?')
                    ->execute([$version]);
                $applied[] = $version;
            }
            return new MigrationResult($applied);
        } finally {
            self::unlock($pdo);
        }
    }

    /** @return list<array{version: string, state: 'applied'|'pending'|'interrupted'|'changed'|'missing'}> */
    public function status(): array
    {
        $files = $this->files();
        $records = self::tableExists($this->connection->get()) ? $this->records() : [];
        $rows = [];
        foreach ($files as $version => $sql) {
            $record = $records[$version] ?? null;
            $rows[] = ['version' => $version, 'state' => match (true) {
                $record === null => 'pending',
                $record['checksum'] !== hash('sha256', $sql, true) => 'changed',
                !$record['completed'] => 'interrupted',
                default => 'applied',
            }];
        }
        foreach (array_diff_key($records, $files) as $version => $record) {
            $rows[] = ['version' => $version, 'state' => 'missing'];
        }
        return $rows;
    }

    /** One migration run at a time. */
    private static function lock(PDO $pdo): void
    {
        $lock = $pdo->query("SELECT GET_LOCK('" . self::LOCK . "', 0)");
        if ($lock === false || (int) $lock->fetchColumn() !== 1) {
            throw new MigrationException('Another migration is running.');
        }
    }

    private static function unlock(PDO $pdo): void
    {
        $pdo->query("SELECT RELEASE_LOCK('" . self::LOCK . "')");
    }

    /** @return array<string, string> SQL keyed by version, in application order. */
    private function files(): array
    {
        $paths = glob($this->directory . '/*.sql') ?: [];
        sort($paths, SORT_STRING);
        $files = [];
        foreach ($paths as $path) {
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new MigrationException('Cannot read migration: ' . basename($path));
            }
            $files[basename($path)] = $sql;
        }
        if ($files === []) {
            throw new MigrationException('No migrations found.');
        }
        return $files;
    }

    /** @param list<string> $versions */
    private function resolve(string $target, array $versions): string
    {
        $matches = array_values(array_filter($versions, static fn(string $version): bool =>
            $version === $target || $version === $target . '.sql' || str_starts_with($version, $target . '_')));
        if (preg_match('/^\d{3}(?:_[a-z0-9_]+)?(?:\.sql)?$/D', $target) !== 1 || count($matches) !== 1) {
            throw new MigrationException('Unknown migration target. Use a migration number such as 005.');
        }
        return $matches[0];
    }

    /** @return array<string, array{checksum: string, completed: bool}> */
    private function records(): array
    {
        $read = $this->connection->get()->query('SELECT version, checksum, completed FROM schema_migrations ORDER BY version');
        $records = [];
        foreach ($read === false ? [] : $read->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $records[(string) $row['version']] = ['checksum' => (string) $row['checksum'], 'completed' => (int) $row['completed'] === 1];
        }
        return $records;
    }

    /** @param array<string, string> $files
     *  @param array<string, array{checksum: string, completed: bool}> $records
     */
    private function verify(array $files, array $records): void
    {
        foreach ($records as $version => $record) {
            if (!isset($files[$version])) {
                throw new MigrationException('Applied migration is missing from this release; manual recovery required: ' . $version);
            }
            if ($record['checksum'] !== hash('sha256', $files[$version], true) || !$record['completed']) {
                throw new MigrationException('Changed or interrupted migration; manual recovery required: ' . $version);
            }
        }
        $latest = array_key_last($records);
        foreach (array_keys($files) as $version) {
            if ($latest !== null && !isset($records[$version]) && strcmp($version, (string) $latest) < 0) {
                throw new MigrationException('Migration ' . $version . ' is older than applied ' . $latest . '; add schema changes as a new migration.');
            }
        }
    }

    /** Precondition of 006: every repository, link and audit event already has its owner. On a new installation the tables are
     * empty; rows without an owner come from a single-owner release, whose data this release does not transfer.
     */
    private static function ownershipBlocker(PDO $pdo): ?string
    {
        $read = $pdo->query('SELECT
            (SELECT COUNT(*) FROM repositories WHERE user_id IS NULL) AS repositories,
            (SELECT COUNT(*) FROM share_links WHERE user_id IS NULL) AS links,
            (SELECT COUNT(*) FROM audit_events WHERE actor_kind IS NULL) AS events,
            (SELECT COUNT(*) FROM share_links l JOIN repositories r ON r.id = l.repository_id WHERE l.user_id <> r.user_id) AS mismatched');
        $row = $read === false ? false : $read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new MigrationException('Ownership state could not be read.');
        }
        if (array_sum(array_map('intval', $row)) === 0) {
            return null;
        }
        return sprintf('Data of a single-owner release was found: %d repositories, %d links and %d audit events have no owner; %d links do not match their repository owner. '
            . 'This release starts from an empty database and does not transfer it.', (int) $row['repositories'], (int) $row['links'], (int) $row['events'], (int) $row['mismatched']);
    }

    private static function tableExists(PDO $pdo): bool
    {
        $read = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'");
        return $read !== false && (int) $read->fetchColumn() === 1;
    }
}
