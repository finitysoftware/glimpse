<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

use Glimpse\Failure;
use Glimpse\Repositories\BranchChoices;
use Glimpse\Repositories\Preview;
use Glimpse\Source\Provider;

/** What a link visitor may learn about the repository host: nothing. Responses and errors look the same for every platform. */
final class VisitorView
{
    private const MESSAGES = [
        'not_found' => 'The repository or file is unavailable.',
        'access_denied' => 'The repository host denied access to this repository.',
        'credentials_rejected' => 'The repository host rejected the access of this link\'s owner.',
        'rate_limited' => 'The repository host is temporarily limiting requests. Try again shortly.',
        'busy' => 'A repository check is already running. Try again shortly.',
        'timeout' => 'The repository host did not respond in time.',
        'empty_repository' => 'This repository has no available commit.',
        'response_too_large' => 'The repository host returned a response beyond the preview limit.',
    ];

    /** Only platform-independent repository fields and content source, with the link rules version it was read under. */
    public static function preview(Preview|BranchChoices $preview, int $policyVersion): Preview|BranchChoices
    {
        $source = $preview->contentSource === 'cache' ? 'cache' : 'upstream';
        if ($preview instanceof BranchChoices) {
            return $preview->with(contentSource: $source, policyVersion: $policyVersion);
        }
        $repository = $preview->repository;
        return $preview->with(contentSource: $source, policyVersion: $policyVersion)
            ->withRepository(['id' => $repository['id'], 'fullName' => $repository['fullName'], 'private' => $repository['private'], 'defaultBranch' => $repository['defaultBranch']]);
    }

    /** A platform error (`github_*`, `gitlab_*`) as the same neutral `upstream_*` error; other errors pass unchanged. */
    public static function error(Failure $error): Failure
    {
        foreach (Provider::cases() as $provider) {
            $prefix = $provider->value . '_';
            if (!str_starts_with($error->errorCode, $prefix)) {
                continue;
            }
            $suffix = substr($error->errorCode, strlen($prefix));
            $known = array_key_exists($suffix, self::MESSAGES);
            return new Failure(
                $error->kind,
                'upstream_' . ($known ? $suffix : 'unavailable'),
                $known ? self::MESSAGES[$suffix] : 'The repository host could not complete this request.',
                $error->retryAfter,
            );
        }
        return $error;
    }
}
