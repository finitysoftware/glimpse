<?php

declare(strict_types=1);

namespace Glimpse\Admin;

use Glimpse\Database\Connection;
use LogicException;

/** Permanently removes an account and everything that belongs to it, inside the caller's transaction. */
final readonly class AccountPurge
{
    public function __construct(private Connection $connection) {}

    public function delete(int $userId): void
    {
        $pdo = $this->connection->get();
        if (!$pdo->inTransaction()) {
            throw new LogicException('Deleting an account requires an active transaction.');
        }
        // Order follows the foreign keys: links (with their grants and rules), repositories (with visibility profiles and cache),
        // then the platform connections, sessions, roles and the account row (its cache state, reservations and counters cascade).
        $pdo->prepare('DELETE FROM audit_events WHERE actor_user_id = ? OR subject_user_id = ?')->execute([$userId, $userId]);
        foreach (['share_links', 'repositories', 'github_connections', 'gitlab_connections', 'sessions', 'user_roles'] as $table) {
            $pdo->prepare('DELETE FROM ' . $table . ' WHERE user_id = ?')->execute([$userId]);
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
}
