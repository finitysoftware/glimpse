<?php

declare(strict_types=1);

namespace Glimpse;

use Glimpse\GitHub\GitHubTransports;
use Glimpse\GitLab\GitLabTransports;
use Glimpse\Http\Actions\Admin\AdminLogin;
use Glimpse\Http\Actions\Admin\AdminLogout;
use Glimpse\Http\Actions\Admin\ChangeAdminPassword;
use Glimpse\Http\Actions\Admin\Shares\ListAllShares;
use Glimpse\Http\Actions\Admin\Shares\RevealAnyShare;
use Glimpse\Http\Actions\Admin\Shares\RevokeAnyShare;
use Glimpse\Http\Actions\Admin\ShowAdminSession;
use Glimpse\Http\Actions\Admin\ShowDashboard;
use Glimpse\Http\Actions\Admin\Users\CreateUser;
use Glimpse\Http\Actions\Admin\Users\DeleteUser;
use Glimpse\Http\Actions\Admin\Users\ListUsers;
use Glimpse\Http\Actions\Admin\Users\RemoveConnection;
use Glimpse\Http\Actions\Admin\Users\ReplaceGitHubToken;
use Glimpse\Http\Actions\Admin\Users\ReplaceGitLabConnection;
use Glimpse\Http\Actions\Admin\Users\RevealGitHubToken;
use Glimpse\Http\Actions\Admin\Users\RevealGitLabConnection;
use Glimpse\Http\Actions\Admin\Users\RevokeUserSessions;
use Glimpse\Http\Actions\Admin\Users\ShowUser;
use Glimpse\Http\Actions\Admin\Users\UpdateUser;
use Glimpse\Http\Actions\Auth\ChangePassword;
use Glimpse\Http\Actions\Auth\Login;
use Glimpse\Http\Actions\Auth\Logout;
use Glimpse\Http\Actions\Auth\ShowSession;
use Glimpse\Http\Actions\Connections\CheckConnection;
use Glimpse\Http\Actions\Connections\Disconnect;
use Glimpse\Http\Actions\Connections\SaveGitHubConnection;
use Glimpse\Http\Actions\Connections\SaveGitLabConnection;
use Glimpse\Http\Actions\Connections\ShowConnection;
use Glimpse\Http\Actions\Connections\ShowPreviewCache;
use Glimpse\Http\Actions\Health;
use Glimpse\Http\Actions\Repositories\BrowseRepository;
use Glimpse\Http\Actions\Repositories\ListRepositories;
use Glimpse\Http\Actions\Repositories\SaveVisibility;
use Glimpse\Http\Actions\Repositories\SetSharing;
use Glimpse\Http\Actions\Repositories\ShowVisibility;
use Glimpse\Http\Actions\Shares\ApplyShareVisibility;
use Glimpse\Http\Actions\Shares\CreateShare;
use Glimpse\Http\Actions\Shares\DeleteShare;
use Glimpse\Http\Actions\Shares\ListShares;
use Glimpse\Http\Actions\Shares\RevealShare;
use Glimpse\Http\Actions\Shares\RevokeShare;
use Glimpse\Http\Actions\Shares\UpdateSharePermissions;
use Glimpse\Http\Actions\Visitor\BrowseShare;
use Glimpse\Http\Actions\Visitor\EndVisit;
use Glimpse\Http\Actions\Visitor\OpenShare;
use Glimpse\Http\Actions\Visitor\ShowVisitorSession;
use Glimpse\Http\Cookies;
use Glimpse\Http\ErrorHandler;
use Glimpse\Http\Middleware\RequestPolicy;
use Glimpse\Http\Middleware\RequireAdmin;
use Glimpse\Http\Middleware\RequireUser;
use Glimpse\Http\Middleware\SecurityHeaders;
use Glimpse\Http\Responders\JsonResponder;
use Glimpse\Http\Responders\SessionResponder;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;

/**
 * The API under /api, in Action-Domain-Responder form: each route is one action (src/Http/Actions) that reads the
 * request, lets a service do the work and hands the result to a responder (src/Http/Responders).
 */
final class Application
{
    /**
     * Tests replace the transports; production reaches GitHub and GitLab over cURL.
     *
     * @return App<\Psr\Container\ContainerInterface|null>
     */
    public static function create(Settings $settings, ?GitHubTransports $github = null, ?GitLabTransports $gitlab = null): App
    {
        $services = new Services($settings, $github, $gitlab);
        [$auth, $admin, $connections, $gitlabConnections] = [$services->auth, $services->admin, $services->github, $services->gitlab];
        [$repositories, $shares, $sharing, $visitors, $visibility] = [$services->repositories, $services->shares, $services->sharing, $services->visitors, $services->visibility];
        [$users, $adminShares, $dashboard] = [$services->users, $services->adminShares, $services->dashboard];
        $responder = new JsonResponder();
        $cookies = new Cookies($settings->secureCookies());
        $session = new SessionResponder($responder, $connections, $gitlabConnections, $cookies);

        $app = AppFactory::create();
        $app->setBasePath('/api');
        $app->get('/health', new Health($responder));

        // Workspace: the signed-in owner's session, platform connections, repositories and access links.
        $app->post('/auth/login', new Login($auth, $session));
        $app->group('/auth', static function (RouteCollectorProxy $group) use ($auth, $session, $responder, $cookies): void {
            $group->get('/session', new ShowSession($session));
            $group->post('/password', new ChangePassword($auth, $session));
            $group->post('/logout', new Logout($auth, $responder, $cookies));
        })->add(new RequireUser($auth));
        $app->group('/workspace', static function (RouteCollectorProxy $group) use (
            $visibility,
            $connections,
            $gitlabConnections,
            $repositories,
            $shares,
            $sharing,
            $responder,
        ): void {
            $group->get('/connections/github', new ShowConnection($connections, $responder));
            $group->put('/connections/github', new SaveGitHubConnection($connections, $responder));
            $group->post('/connections/github/probe', new CheckConnection($connections, $responder));
            $group->post('/connections/github/disconnect', new Disconnect($connections, $responder));
            $group->get('/connections/gitlab', new ShowConnection($gitlabConnections, $responder));
            $group->put('/connections/gitlab', new SaveGitLabConnection($gitlabConnections, $responder));
            $group->post('/connections/gitlab/probe', new CheckConnection($gitlabConnections, $responder));
            $group->post('/connections/gitlab/disconnect', new Disconnect($gitlabConnections, $responder));
            $group->get('/preview-cache', new ShowPreviewCache($repositories, $responder));
            $group->get('/repositories', new ListRepositories($repositories, $responder));
            // The same repository routes for each platform: GitHub by owner and name, GitLab by its stable project ID.
            foreach (['/repositories/{owner}/{repository}', '/gitlab/projects/{id:[1-9][0-9]{0,17}}'] as $repository) {
                $group->get($repository . '/browse', new BrowseRepository($repositories, $responder));
                $group->get($repository . '/shares', new ListShares($shares, $responder));
                $group->post($repository . '/shares', new CreateShare($shares, $responder));
                $group->post($repository . '/availability', new SetSharing($sharing, $responder));
                $group->get($repository . '/visibility', new ShowVisibility($visibility, $responder));
                $group->put($repository . '/visibility', new SaveVisibility($visibility, $responder));
            }
            $group->post('/shares/{publicId}/reveal', new RevealShare($shares, $responder));
            $group->post('/shares/{publicId}/permissions', new UpdateSharePermissions($shares, $responder));
            $group->post('/shares/{publicId}/visibility', new ApplyShareVisibility($shares, $responder));
            $group->post('/shares/{publicId}/revoke', new RevokeShare($shares, $responder));
            $group->post('/shares/{publicId}/delete', new DeleteShare($shares, $responder));
        })->add(new RequireUser($auth));

        // Visitors of access links: no account, one visitor session per browser.
        $app->post('/shares/{publicId}/exchange', new OpenShare($visitors, $responder, $cookies));
        $app->get('/shares/{publicId}/session', new ShowVisitorSession($visitors, $responder));
        $app->get('/shares/{publicId}/browse', new BrowseShare($visitors, $responder));
        $app->post('/visitor/logout', new EndVisit($visitors, $responder, $cookies));

        // Admin panel: the same accounts, but a separate session audience, cookie and CSRF scope.
        $app->post('/admin/auth/login', new AdminLogin($admin, $responder, $cookies));
        $app->group('/admin', static function (RouteCollectorProxy $group) use (
            $admin,
            $users,
            $connections,
            $gitlabConnections,
            $adminShares,
            $dashboard,
            $responder,
            $cookies,
        ): void {
            $group->get('/dashboard', new ShowDashboard($dashboard, $responder));
            $group->get('/auth/session', new ShowAdminSession($responder));
            $group->post('/auth/password', new ChangeAdminPassword($admin, $responder, $cookies));
            $group->post('/auth/logout', new AdminLogout($admin, $responder, $cookies));
            $user = '/users/{id:[1-9][0-9]{0,17}}';
            $group->get('/users', new ListUsers($users, $responder));
            $group->post('/users', new CreateUser($users, $responder));
            $group->get($user, new ShowUser($users, $responder));
            $group->patch($user, new UpdateUser($users, $responder));
            $group->post($user . '/sessions/revoke', new RevokeUserSessions($users, $responder));
            $group->post($user . '/delete', new DeleteUser($users, $responder));
            // An account's GitHub token and GitLab connection: shown on request, replaced after verification, or removed.
            $group->post($user . '/github/token/reveal', new RevealGitHubToken($users, $connections, $responder));
            $group->put($user . '/github/token', new ReplaceGitHubToken($users, $connections, $responder));
            $group->post($user . '/github/token/remove', new RemoveConnection($users, $connections, $responder));
            $group->post($user . '/gitlab/token/reveal', new RevealGitLabConnection($users, $gitlabConnections, $responder));
            $group->put($user . '/gitlab', new ReplaceGitLabConnection($users, $gitlabConnections, $responder));
            $group->post($user . '/gitlab/remove', new RemoveConnection($users, $gitlabConnections, $responder));
            $link = '/shares/{publicId:[a-f0-9]{32}}';
            $group->get('/shares', new ListAllShares($adminShares, $responder));
            $group->post($link . '/reveal', new RevealAnyShare($adminShares, $responder));
            $group->post($link . '/revoke', new RevokeAnyShare($adminShares, $responder));
        })->add(new RequireAdmin($admin));

        $app->addRoutingMiddleware();
        $app->add(new RequestPolicy($settings));
        $app->addErrorMiddleware(false, false, false)->setDefaultErrorHandler(new ErrorHandler($responder));
        // Added last so headers cover successful responses and all error responses alike.
        $app->add(new SecurityHeaders());
        return $app;
    }
}
