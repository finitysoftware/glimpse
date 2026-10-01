<?php

declare(strict_types=1);

namespace Glimpse\Source;

use Glimpse\Database\Transaction;
use Glimpse\Failure;
use PDO;

/** Upstream reservations: four slots protect the PHP host, and one user (including visitors of that user's links) may hold
 * at most two, counted over both platforms.
 */
final readonly class Lease
{
    public const GLOBAL_SLOTS = 4;
    public const USER_SLOTS = 2;

    public function __construct(private PDO $pdo, private int $userId, private int $credentialVersion, private Provider $provider = Provider::GitHub) {}

    /** @template T
     *  @param callable(): T $operation
     *  @return T
     */
    public function run(string $key, callable $operation): mixed
    {
        $token = random_bytes(32);
        // Identical requests are de-duplicated per tenant, platform and credential version, never across users.
        $requestKey = hash('sha256', json_encode(['lease-v3', $this->provider->value, $this->userId, $this->credentialVersion, $key], JSON_THROW_ON_ERROR), true);
        $this->pdo->exec('DELETE FROM api_leases WHERE expires_at <= UTC_TIMESTAMP(6)');
        $slot = $this->reserve($requestKey, $token);
        if ($slot === null) {
            throw Failure::unavailable($this->provider->code('busy'), 'A ' . $this->provider->label() . ' check is already running. Try again shortly.', 2);
        }
        try {
            return $operation();
        } finally {
            $this->pdo->prepare('DELETE FROM api_leases WHERE slot = ? AND owner_token = ?')->execute([$slot, $token]);
        }
    }

    /** A short transaction on the user's cache state rows (both platforms, in key order) makes counting and taking a slot atomic per user. */
    private function reserve(string $requestKey, string $token): ?int
    {
        $this->pdo->prepare('INSERT IGNORE INTO api_cache_state (user_id, provider, credential_version) VALUES (?, ?, ?)')
            ->execute([$this->userId, $this->provider->value, $this->credentialVersion]);
        return Transaction::run($this->pdo, function () use ($requestKey, $token): ?int {
            $lock = $this->pdo->prepare('SELECT provider FROM api_cache_state WHERE user_id = ? ORDER BY provider FOR UPDATE');
            $lock->execute([$this->userId]);
            $lock->fetchAll();
            $held = $this->pdo->prepare('SELECT COUNT(*) FROM api_leases WHERE user_id = ? AND expires_at > UTC_TIMESTAMP(6)');
            $held->execute([$this->userId]);
            $slot = null;
            if ((int) $held->fetchColumn() < self::USER_SLOTS) {
                $insert = $this->pdo->prepare('INSERT IGNORE INTO api_leases (slot, user_id, credential_version, request_key, owner_token, expires_at)
                    VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 20 SECOND))');
                for ($candidate = 1; $candidate <= self::GLOBAL_SLOTS && $slot === null; $candidate++) {
                    $insert->execute([$candidate, $this->userId, $this->credentialVersion, $requestKey, $token]);
                    if ($insert->rowCount() === 1) {
                        $slot = $candidate;
                    }
                }
            }
            return $slot;
        });
    }
}
