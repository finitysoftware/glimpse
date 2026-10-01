<?php

declare(strict_types=1);

namespace Glimpse\Http;

use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;

/**
 * The session cookies of the three areas. Each is host-only, HttpOnly and sent only to the API, so the SPA never
 * sees a token; the admin cookie is limited to the admin API and never sent from another site.
 */
final readonly class Cookies
{
    public const WORKSPACE = 'glimpse_workspace';
    public const ADMIN = 'glimpse_platform_admin';
    public const VISITOR = 'glimpse_visitor';

    /** Path and SameSite of each cookie. */
    private const SCOPES = [
        self::WORKSPACE => ['/api', 'Lax'],
        self::ADMIN => ['/api/admin', 'Strict'],
        self::VISITOR => ['/api', 'Lax'],
    ];

    public function __construct(private bool $secure) {}

    /** The session token of one area, or '' when its cookie is missing or malformed. */
    public static function token(ServerRequestInterface $request, string $name): string
    {
        $token = $request->getCookieParams()[$name] ?? '';
        return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1 ? $token : '';
    }

    /** The Set-Cookie value that keeps $token for $seconds. */
    public function set(string $name, #[SensitiveParameter] string $token, int $seconds): string
    {
        [$path, $sameSite] = self::SCOPES[$name];
        return $name . '=' . $token . '; Path=' . $path . '; Max-Age=' . $seconds . '; HttpOnly; SameSite=' . $sameSite
            . ($this->secure ? '; Secure' : '');
    }

    /** The Set-Cookie value that removes the cookie. */
    public function clear(string $name): string
    {
        return $this->set($name, '', 0);
    }
}
