<?php

declare(strict_types=1);

namespace Glimpse\Auth;

use Glimpse\Accounts\Passwords;
use Glimpse\Accounts\UserProvisioningService;
use Glimpse\Database\Connection;
use Glimpse\Database\Transaction;
use Glimpse\Failure;
use PDO;
use SensitiveParameter;

/** A signed-in account replaces its own password: every session ends and the current browser receives a new session. */
final readonly class PasswordChange
{
    public function __construct(private Connection $connection, private UserProvisioningService $accounts, private Sessions $sessions) {}

    /** @return string Token of the new session in $audience. */
    public function apply(
        int $userId,
        #[SensitiveParameter]
        string $current,
        #[SensitiveParameter]
        string $new,
        string $audience,
        int $ttlSeconds,
    ): string {
        $pdo = $this->connection->get();
        Passwords::validateNew($new);
        $read = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? AND status = 'active'");
        $read->execute([$userId]);
        $saved = $read->fetchColumn();
        if (!is_string($saved) || !Passwords::verify($current, $saved)) {
            throw Failure::rejected('current_password_invalid', 'The current password is incorrect.');
        }
        if (Passwords::verify($new, $saved)) {
            throw Failure::rejected('password_unchanged', 'Choose a password different from the current one.');
        }
        $hash = Passwords::hash($new);
        return Transaction::run($pdo, function () use ($pdo, $userId, $saved, $hash, $audience, $ttlSeconds): string {
            $this->accounts->lock($userId);
            // An administrator may have set another password while this one was being hashed.
            $check = $pdo->prepare('SELECT password_hash, session_version FROM users WHERE id = ?');
            $check->execute([$userId]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !hash_equals($saved, (string) $row['password_hash'])) {
                throw Failure::conflict('password_changed', 'The password was changed in the meantime. Sign in again.');
            }
            $this->accounts->setPassword($userId, $hash, false);
            $token = $this->sessions->issue($userId, (int) $row['session_version'] + 1, $audience, $ttlSeconds);
            return $token;
        });
    }
}
