<?php

declare(strict_types=1);

namespace Glimpse\Tests\Unit;

use Glimpse\Failure;
use Glimpse\Http\ErrorHandler;
use Glimpse\Http\Responders\JsonResponder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Throwable;

final class ErrorHandlerTest extends TestCase
{
    /**
     * The response to $error, and what the handler wrote to the error log meanwhile. PHPUnit captures the log of each
     * test itself, so the log is redirected only while the handler runs.
     *
     * @return array{ResponseInterface, string}
     */
    private static function handle(Throwable $error): array
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'glimpse-log');
        $previous = ini_set('error_log', $log);
        try {
            $response = (new ErrorHandler(new JsonResponder()))((new ServerRequestFactory())->createServerRequest('GET', '/api/shares'), $error);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
        $written = (string) file_get_contents($log);
        @unlink($log);
        return [$response, $written];
    }

    public function testUnexpectedErrorsLogTheirTypeAndThrowSiteButNeverTheirMessage(): void
    {
        $error = (static fn(): RuntimeException => new RuntimeException('token ghp_secret_value at https://user:password@example.test/private'))();
        $line = __LINE__ - 1;
        [$response, $log] = self::handle($error);

        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $requestId = $body['error']['requestId'] ?? null;
        self::assertIsString($requestId);
        self::assertStringContainsString('Glimpse request=' . $requestId . ' failure=RuntimeException at=tests/Unit/ErrorHandlerTest.php:' . $line, $log);
        foreach (['ghp_secret_value', 'password', 'example.test', '/api/shares'] as $secret) {
            self::assertStringNotContainsString($secret, $log . (string) $response->getBody());
        }
    }

    public function testDomainFailuresAreAnsweredWithoutAnyLog(): void
    {
        [$response, $log] = self::handle(Failure::notFound('share_unavailable', 'This link is unavailable.'));
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', $log);
    }
}
