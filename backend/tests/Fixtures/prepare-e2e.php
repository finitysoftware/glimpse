<?php

declare(strict_types=1);

use Glimpse\Auth\UserContext;
use Glimpse\Database\Connection;
use Glimpse\Services;
use Glimpse\Settings;
use Glimpse\Tests\Fixtures\FixedGitLabTransports;
use Glimpse\Tests\Fixtures\FixedTransports;
use Glimpse\Tests\Fixtures\GitLabRepositoryTransport;
use Glimpse\Tests\Fixtures\RepositoryTransport;
use Glimpse\Tests\Fixtures\Workspace;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
if (PHP_SAPI !== 'cli' || getenv('GLIMPSE_TEST_DB_RESET') !== '1'
    || !preg_match('/dbname=glimpse_test(?:;|$)/', getenv('GLIMPSE_DB_DSN') ?: '')) {
    exit(1);
}
$settings = Settings::load(dirname(__DIR__, 2) . '/config');
$connection = new Connection($settings);
$pdo = $connection->get();
Workspace::migrate($settings);
Workspace::reset($pdo);
// Synthetic tokens are verified against deterministic transports, like a real Save & verify.
$services = new Services($settings, new FixedTransports(new RepositoryTransport()), new FixedGitLabTransports(new GitLabRepositoryTransport()), $connection);
// Browser tests sign in with this synthetic account; it exists only in the isolated test database.
$ownerId = $services->accounts->create('e2e.owner', 'local-e2e-password-only');
// Separate synthetic administrator for the admin panel tests.
$services->accounts->create('e2e.admin', 'local-e2e-admin-password', true);
$services->github->save(new UserContext($ownerId, 'e2e.owner', 0, 1, [UserContext::WORKSPACE], ''), Workspace::token('e2e.owner'), 0, 'fixture');
// A second synthetic account with both platforms, for the GitLab browser tests.
$gitlabId = $services->accounts->create('e2e.gitlab', 'local-e2e-gitlab-password');
$gitlabUser = new UserContext($gitlabId, 'e2e.gitlab', 0, 1, [UserContext::WORKSPACE], '');
$services->github->save($gitlabUser, Workspace::token('e2e.gitlab'), 0, 'fixture');
$services->gitlab->save($gitlabUser, Workspace::GITLAB_URL, Workspace::gitlabToken('e2e.gitlab'), 0, 'fixture');
