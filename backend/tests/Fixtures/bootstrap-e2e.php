<?php

declare(strict_types=1);

use Glimpse\Application;
use Glimpse\Settings;
use Glimpse\Tests\Fixtures\FixedGitLabTransports;
use Glimpse\Tests\Fixtures\FixedTransports;
use Glimpse\Tests\Fixtures\GitLabRepositoryTransport;
use Glimpse\Tests\Fixtures\RepositoryTransport;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
if (getenv('GLIMPSE_ENV') !== 'test' || getenv('GLIMPSE_TEST_DB_RESET') !== '1'
    || !preg_match('/dbname=glimpse_test(?:;|$)/', getenv('GLIMPSE_DB_DSN') ?: '')) {
    throw new RuntimeException('Fixture bootstrap requires the isolated test database.');
}
return Application::create(
    Settings::load(dirname(__DIR__, 2) . '/config'),
    new FixedTransports(new RepositoryTransport()),
    new FixedGitLabTransports(new GitLabRepositoryTransport()),
);
