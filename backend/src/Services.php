<?php

declare(strict_types=1);

namespace Glimpse;

use Glimpse\Accounts\UserProvisioningService;
use Glimpse\Admin\AccountPurge;
use Glimpse\Admin\AdminDashboardService;
use Glimpse\Admin\AdminShareService;
use Glimpse\Admin\AdminUserService;
use Glimpse\Audit\AuditLog;
use Glimpse\Audit\DailyMetrics;
use Glimpse\Auth\AdminAuth;
use Glimpse\Auth\PasswordChange;
use Glimpse\Auth\Sessions;
use Glimpse\Auth\Throttle;
use Glimpse\Auth\UserAuth;
use Glimpse\Cache\PreviewCaches;
use Glimpse\Database\Connection;
use Glimpse\Database\Migrator;
use Glimpse\GitHub\CurlTransports;
use Glimpse\GitHub\GitHubConnectionService;
use Glimpse\GitHub\GitHubTransports;
use Glimpse\GitLab\CurlGitLabTransports;
use Glimpse\GitLab\GitLabConnectionService;
use Glimpse\GitLab\GitLabNetworkPolicy;
use Glimpse\GitLab\GitLabTransports;
use Glimpse\Repositories\GitHubPlatform;
use Glimpse\Repositories\GitLabPlatform;
use Glimpse\Repositories\RepositoryRegistry;
use Glimpse\Repositories\RepositoryServices;
use Glimpse\Sharing\LinkPolicies;
use Glimpse\Sharing\RepositorySharing;
use Glimpse\Sharing\ShareLinks;
use Glimpse\Sharing\ShareUrls;
use Glimpse\Sharing\VisitorAccess;
use Glimpse\Visibility\RepositoryVisibilityService;

/**
 * Every service of Glimpse, built once from the settings: the one place that decides what each service gets.
 * The API builds it per request; tests build the same graph with their own transports or database connection.
 */
final readonly class Services
{
    public Connection $connection;
    public AuditLog $audit;
    public DailyMetrics $metrics;
    public Sessions $sessions;
    public Throttle $throttle;
    public PreviewCaches $caches;
    public UserProvisioningService $accounts;
    public UserAuth $auth;
    public AdminAuth $admin;
    public GitHubConnectionService $github;
    public GitLabConnectionService $gitlab;
    public RepositoryServices $repositories;
    public ShareUrls $urls;
    public ShareLinks $shares;
    public RepositorySharing $sharing;
    public VisitorAccess $visitors;
    public RepositoryVisibilityService $visibility;
    public AdminUserService $users;
    public AdminShareService $adminShares;
    public AdminDashboardService $dashboard;

    /** Without transports, GitHub and GitLab are reached over cURL. */
    public function __construct(public Settings $settings, ?GitHubTransports $github = null, ?GitLabTransports $gitlab = null, ?Connection $connection = null)
    {
        $this->connection = $connection ??= new Connection($settings);
        $this->audit = new AuditLog($connection);
        $this->metrics = new DailyMetrics($connection);
        $this->sessions = new Sessions($connection);
        $this->throttle = new Throttle($connection);
        $this->caches = new PreviewCaches($connection);
        $registry = new RepositoryRegistry($connection);
        $this->accounts = new UserProvisioningService($connection, $this->audit);
        $passwords = new PasswordChange($connection, $this->accounts, $this->sessions);
        $this->auth = new UserAuth($connection, $this->audit, $this->metrics, $this->sessions, $passwords, $this->throttle);
        $this->admin = new AdminAuth($connection, $this->metrics, $this->sessions, $passwords, $this->throttle);
        $this->github = new GitHubConnectionService($connection, $settings, $github ?? new CurlTransports(), $this->audit, $this->throttle, $this->caches);
        $policy = new GitLabNetworkPolicy($settings->gitlabAllowedHosts, $settings->gitlabAllowPrivateAddresses);
        $this->gitlab = new GitLabConnectionService($connection, $settings, $gitlab ?? new CurlGitLabTransports($policy), $this->audit, $this->throttle, $this->caches);
        $this->repositories = new RepositoryServices($connection, [new GitHubPlatform($this->github), new GitLabPlatform($this->gitlab)], $this->caches, $settings->cacheBudgetBytes);
        $this->urls = ShareUrls::fromSettings($settings);
        $policies = new LinkPolicies($connection);
        $this->shares = new ShareLinks($connection, $this->urls, $this->repositories, $policies, $this->audit, $this->metrics, $registry);
        $this->sharing = new RepositorySharing($connection, $this->repositories, $registry, $this->audit, $this->caches);
        $this->visitors = new VisitorAccess($connection, $this->repositories, $policies, $this->metrics, $this->throttle);
        $this->visibility = new RepositoryVisibilityService($connection, $this->repositories, $this->audit, $registry);
        $this->users = new AdminUserService($connection, $this->github, $this->gitlab, $this->accounts, new AccountPurge($connection), $this->caches);
        $this->adminShares = new AdminShareService($connection, $this->urls, $policies);
        $this->dashboard = new AdminDashboardService($connection, $settings, new Migrator($connection, dirname(__DIR__) . '/migrations'), $this->urls);
    }
}
