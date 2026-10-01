<?php

declare(strict_types=1);

use Glimpse\Accounts\Passwords;
use Glimpse\Cli\SecretInput;
use Glimpse\Failure;
use Glimpse\Services;
use Glimpse\Settings;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/vendor/autoload.php';

$usage = <<<'TEXT'
Usage:
  php bin/users.php create <username> [--platform-admin] < protected-password-input
  php bin/users.php reset-password <username> < protected-password-input
  php bin/users.php disable <username>
  php bin/users.php enable <username>
  php bin/users.php grant-admin <username>
  php bin/users.php revoke-admin <username>
  php bin/users.php list
Passwords are read only from redirected standard input (8-128 characters), never from arguments.

TEXT;
$arguments = array_slice($argv ?? [], 1);
$command = array_shift($arguments) ?? '';
$platformAdmin = $command === 'create' && ($arguments[1] ?? null) === '--platform-admin';
$expected = match ($command) {
    'list' => 0,
    'create' => $platformAdmin ? 2 : 1,
    'reset-password', 'disable', 'enable', 'grant-admin', 'revoke-admin' => 1,
    default => -1,
};
if ($expected < 0 || count($arguments) !== $expected || str_starts_with($arguments[0] ?? '', '-')) {
    fwrite(STDERR, $usage);
    exit(64);
}
$username = $arguments[0] ?? '';

try {
    $users = (new Services(Settings::load(dirname(__DIR__) . '/config')))->accounts;
    switch ($command) {
        case 'create':
            $password = SecretInput::read(STDIN, Passwords::MAX_BYTES);
            $users->create($username, $password, $platformAdmin);
            $password = null;
            echo "Account created.\n";
            break;
        case 'reset-password':
            $password = SecretInput::read(STDIN, Passwords::MAX_BYTES);
            $users->resetPassword($username, $password);
            $password = null;
            echo "Password replaced; existing sessions were signed out.\n";
            break;
        case 'disable':
            echo $users->disable($username) ? "Account disabled; existing sessions were signed out.\n" : "Account was already disabled.\n";
            break;
        case 'enable':
            echo $users->enable($username) ? "Account enabled.\n" : "Account was already active.\n";
            break;
        case 'grant-admin':
            echo $users->grantPlatformAdmin($username) ? "platform_admin role granted.\n" : "Account already had the platform_admin role.\n";
            break;
        case 'revoke-admin':
            echo $users->revokePlatformAdmin($username) ? "platform_admin role revoked; existing sessions were signed out.\n" : "Account did not have the platform_admin role.\n";
            break;
        default:
            foreach ($users->list() as $user) {
                echo str_pad($user['username'], 34) . str_pad($user['status'], 10) . str_pad(implode(',', $user['roles']), 24)
                    . 'created ' . $user['createdAt'] . ($user['lastLoginAt'] === null ? '' : ', last sign-in ' . $user['lastLoginAt']) . "\n";
            }
    }
} catch (PDOException) {
    fwrite(STDERR, "Account operation failed. Check private database configuration, privileges and applied migrations.\n");
    exit(1);
} catch (Failure | RuntimeException $error) {
    // These messages are written for operators and never include passwords.
    fwrite(STDERR, 'Account operation stopped: ' . $error->getMessage() . "\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Account operation failed.\n");
    exit(1);
}
