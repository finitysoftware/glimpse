<?php

declare(strict_types=1);

namespace Glimpse\Cache;

use Glimpse\Database\Connection;
use Glimpse\Failure;
use Glimpse\Source\CachePolicy;
use Glimpse\Source\ConnectionState;
use Glimpse\Source\DerivedCache;
use Glimpse\Source\HttpResult;
use Glimpse\Source\Lease;
use Glimpse\Source\RateLimit;
use Glimpse\Source\SourceAccess;
use Glimpse\Source\Transport;

final class CachedTransport implements Transport, DerivedCache
{
    private readonly PreviewCache $cache;
    private readonly CachePolicy $policy;
    private readonly float $deadline;
    private bool $retried = false;
    private ?int $externalId = null;
    private ?int $repositoryId = null;
    public string $checkedAt = '';
    /** 'cache', or the platform that served content in this request. */
    public string $contentSource;
    /** Upstream calls actually sent by this request (cache hits excluded); bounds incremental history scans. */
    public int $fetched = 0;

    private readonly Transport $upstream;

    public function __construct(
        private readonly Connection $connection,
        private readonly SourceAccess $access,
        private readonly PreviewCaches $caches,
        private readonly string $scope,
        private readonly bool $refresh = false,
        int $globalBudget = PreviewCache::GLOBAL_MAX_BYTES,
    ) {
        $this->upstream = $access->transport;
        $this->cache = new PreviewCache($connection, $access, $caches, $globalBudget);
        $this->policy = $access->cachePolicy;
        $this->contentSource = $access->provider->value;
        $this->deadline = microtime(true) + 15;
    }

    /** Ties later entries to the owner's local row of this repository, so they are removed with it. */
    public function bindRepository(int $externalId): void
    {
        $this->externalId = $externalId;
        $read = $this->connection->get()->prepare('SELECT id FROM repositories WHERE user_id = ? AND provider = ? AND instance_url = ? AND external_id = ?');
        $read->execute([$this->access->userId, $this->access->provider->value, $this->access->instance, $externalId]);
        $id = $read->fetchColumn();
        $this->repositoryId = $id === false ? null : (int) $id;
    }

    public function get(string $path, int $timeoutMilliseconds, ?string $etag = null): HttpResult
    {
        $rule = $this->policy->rule($path);
        $force = $this->refresh && !$rule->immutable;
        $key = $this->cache->key($path, $rule->metadata ? null : $this->externalId);
        $cached = $rule->store ? $this->cache->get($key) : null;
        if (!$force && $cached !== null && $cached['fresh']) {
            $this->cache->count('hits');
            if ($rule->metadata) {
                $this->checkedAt = $cached['checkedAt'];
            }
            if ($rule->content) {
                $this->contentSource = 'cache';
            }
            return $cached['response'];
        }
        $this->cache->count('misses');
        $lease = new Lease($this->connection->get(), $this->access->userId, $this->access->credentialVersion, $this->access->provider);
        return $lease->run(bin2hex($key), function () use ($key, $path, $rule, $force, $timeoutMilliseconds): HttpResult {
            // A preceding writer may have completed between the first read and the reservation.
            $cached = $rule->store ? $this->cache->get($key) : null;
            if (!$force && $cached !== null && $cached['fresh']) {
                if ($rule->metadata) {
                    $this->checkedAt = $cached['checkedAt'];
                }
                if ($rule->content) {
                    $this->contentSource = 'cache';
                }
                return $cached['response'];
            }
            $pdo = $this->connection->get();
            $provider = $this->access->provider;
            try {
                $this->cache->checkCooldown();
                $etag = $cached['response']->headers['etag'] ?? null;
                if ($etag !== null && (strlen($etag) > 255 || preg_match('/[\x00-\x1f\x7f]/', $etag))) {
                    $etag = null;
                }
                $result = $this->fetch($path, $timeoutMilliseconds, $etag);
                if ($result->status === 304 && $cached !== null && $etag !== null) {
                    $this->cache->count('revalidations');
                    $result = new HttpResult(200, array_merge($cached['response']->headers, $result->headers), $cached['response']->body);
                }
                if ($result->status === 200) {
                    // Schema validation is done by the platform client; malformed data is invalidated by RepositoryService.
                    if ($rule->store) {
                        $this->cache->put($key, $this->scope, $this->repositoryId, $result, $rule->immutable ? 3600 : 30);
                    }
                    if ($rule->metadata) {
                        $this->checkedAt = gmdate('Y-m-d\TH:i:s\Z');
                    }
                } elseif ($result->status === 401) {
                    // Only this user's current credential version on this platform is rejected; other users and platforms keep their cache.
                    ConnectionState::markRejected($pdo, $provider, $this->access->userId, $this->access->credentialVersion);
                    $this->caches->invalidateUser($this->access->userId, $provider);
                } else {
                    $this->caches->invalidate($this->access->userId, $provider, $this->scope);
                }
                return $result;
            } catch (Failure $error) {
                $this->caches->invalidate($this->access->userId, $provider, $this->scope);
                throw $error;
            }
        });
    }

    /** A result the client assembled from several upstream pages, cached as one entry of this repository (never across users or tokens). */
    public function derived(string $name): ?string
    {
        $cached = $this->cache->get($this->cache->key('derived:' . $name, $this->externalId));
        if ($cached === null || !$cached['fresh']) {
            return null;
        }
        $this->cache->count('hits');
        $this->contentSource = 'cache';
        return $cached['response']->body;
    }

    public function storeDerived(string $name, string $body, int $ttl): void
    {
        $this->cache->put($this->cache->key('derived:' . $name, $this->externalId), $this->scope, $this->repositoryId, new HttpResult(200, [], $body), $ttl);
    }

    private function fetch(string $path, int $timeoutMilliseconds, ?string $etag): HttpResult
    {
        $deadline = min($this->deadline, microtime(true) + $timeoutMilliseconds / 1000);
        $provider = $this->access->provider;
        while (true) {
            $remaining = (int) floor(($deadline - microtime(true)) * 1000);
            if ($remaining <= 0) {
                throw Failure::upstream($provider->code('timeout'), $provider->label() . ' did not respond in time.');
            }
            $this->cache->count('upstream_calls');
            $this->fetched++;
            try {
                $result = $this->upstream->get($path, $remaining, $etag);
                $cooldown = RateLimit::delay($result);
                $exhausted = RateLimit::exhausted($result);
                if ($exhausted !== null) {
                    $cooldown = max($cooldown ?? 1, $exhausted);
                }
                $this->cache->observe($result, $cooldown);
                if ($cooldown !== null || $this->retried || !in_array($result->status, [500, 502, 503, 504], true)
                    || isset($result->headers['retry-after']) || $deadline - microtime(true) < .25) {
                    return $result;
                }
            } catch (Failure $error) {
                if ($this->retried || !in_array($error->errorCode, [$provider->code('timeout'), $provider->code('unavailable')], true)
                    || $deadline - microtime(true) < .25) {
                    throw $error;
                }
            }
            $this->retried = true;
            usleep(100000);
        }
    }
}
