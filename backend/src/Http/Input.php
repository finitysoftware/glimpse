<?php

declare(strict_types=1);

namespace Glimpse\Http;

use Glimpse\Auth\AdminContext;
use Glimpse\Auth\UserContext;
use Glimpse\Failure;
use Glimpse\Repositories\RepositoryRef;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/** Reads what a request sends and carries: its JSON body, query and route values, the client address and who is signed in. */
final class Input
{
    /** Request attributes set by Middleware\RequireUser and Middleware\RequireAdmin. */
    public const USER = 'glimpse.user';
    public const ADMIN = 'glimpse.admin';

    /** The signed-in workspace user; private routes require it and there is no fallback to another account. */
    public static function user(ServerRequestInterface $request): UserContext
    {
        $context = $request->getAttribute(self::USER);
        if (!$context instanceof UserContext) {
            throw new LogicException('Route is missing RequireUser.');
        }
        return $context;
    }

    /** The signed-in administrator of an admin panel request. */
    public static function admin(ServerRequestInterface $request): AdminContext
    {
        $context = $request->getAttribute(self::ADMIN);
        if (!$context instanceof AdminContext) {
            throw new LogicException('Route is missing RequireAdmin.');
        }
        return $context;
    }

    /** @return array<string, mixed> */
    public static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw Failure::invalid('invalid_json', 'Request body must be a JSON object.');
        }
        /** @var array<string, mixed> $body */
        return $body;
    }

    /** A placeholder of the route, e.g. {publicId}; '' when the route has none. */
    public static function argument(ServerRequestInterface $request, string $key): string
    {
        return RouteContext::fromRequest($request)->getRoute()?->getArgument($key) ?? '';
    }

    /** The ?page= number; anything but a whole number is 0, which every paged service rejects. */
    public static function page(ServerRequestInterface $request): int
    {
        return (int) filter_var($request->getQueryParams()['page'] ?? 1, FILTER_VALIDATE_INT);
    }

    /** The client address, for throttling and the audit log. */
    public static function address(ServerRequestInterface $request): string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        return is_string($address) ? $address : 'unknown';
    }

    /** The account an admin route names (/users/{id}); the route pattern allows only positive numbers. */
    public static function userId(ServerRequestInterface $request): int
    {
        return (int) self::argument($request, 'id');
    }

    /** The repository a workspace route names: GitHub by owner and name, GitLab by its stable project ID. */
    public static function repository(ServerRequestInterface $request): RepositoryRef
    {
        $projectId = self::argument($request, 'id');
        if ($projectId !== '') {
            return RepositoryRef::gitlab((int) $projectId);
        }
        return RepositoryRef::github(self::argument($request, 'owner'), self::argument($request, 'repository'));
    }

    /**
     * Username and password of a sign-in.
     *
     * @return array{string, string}
     */
    public static function credentials(ServerRequestInterface $request): array
    {
        $data = self::body($request);
        $username = $data['username'] ?? null;
        $password = $data['password'] ?? null;
        if (!is_string($username) || !is_string($password) || strlen($username) > 256 || $password === '' || strlen($password) > 1024) {
            throw Failure::invalid('invalid_input', 'Enter your username and password.');
        }
        return [$username, $password];
    }

    /**
     * Current and new password of an own password change.
     *
     * @return array{string, string}
     */
    public static function passwordChange(ServerRequestInterface $request): array
    {
        $data = self::body($request);
        $current = $data['currentPassword'] ?? null;
        $new = $data['newPassword'] ?? null;
        if (!is_string($current) || !is_string($new) || $current === '' || strlen($current) > 1024 || strlen($new) > 4096) {
            throw Failure::invalid('invalid_input', 'Enter your current password and a new password.');
        }
        return [$current, $new];
    }
}
