<?php

declare(strict_types=1);

namespace Glimpse\Database;

use PDO;
use Throwable;

/** All-or-nothing database work. */
final class Transaction
{
    /**
     * Runs $work in a transaction: committed when it returns, rolled back when it throws, and the error passed on.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function run(PDO $pdo, callable $work): mixed
    {
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}
