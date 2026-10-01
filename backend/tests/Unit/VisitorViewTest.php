<?php

declare(strict_types=1);

namespace Glimpse\Tests\Unit;

use Glimpse\Failure;
use Glimpse\FailureKind;
use Glimpse\Repositories\BranchChoices;
use Glimpse\Repositories\Preview;
use Glimpse\Sharing\VisitorView;
use PHPUnit\Framework\TestCase;

final class VisitorViewTest extends TestCase
{
    public function testPlatformErrorsBecomeTheSameNeutralError(): void
    {
        foreach (['github', 'gitlab'] as $platform) {
            foreach ([['not_found', FailureKind::Upstream, 'upstream_not_found'], ['rate_limited', FailureKind::RateLimited, 'upstream_rate_limited'],
                ['busy', FailureKind::Unavailable, 'upstream_busy'], ['invalid_response', FailureKind::Upstream, 'upstream_unavailable'],
                ['token_unreadable', FailureKind::Unavailable, 'upstream_unavailable'], ['url_not_allowed', FailureKind::Rejected, 'upstream_unavailable']] as [$suffix, $kind, $code]) {
                $neutral = VisitorView::error(new Failure($kind, $platform . '_' . $suffix, 'GitHub or GitLab said something', 30));
                self::assertSame([$kind, $code, 30], [$neutral->kind, $neutral->errorCode, $neutral->retryAfter]);
                self::assertStringNotContainsStringIgnoringCase('git', $neutral->getMessage());
            }
        }
        $other = Failure::notFound('share_unavailable', 'This link is unavailable.');
        self::assertSame($other, VisitorView::error($other));
    }

    public function testPreviewKeepsOnlyPlatformIndependentFields(): void
    {
        $preview = new Preview(['provider' => 'gitlab', 'id' => 42, 'fullName' => 'group/app', 'owner' => 'group', 'name' => 'app', 'private' => true,
            'visibility' => 'internal', 'defaultBranch' => 'main'], str_repeat('a', 40), '', 'directory', 'main', true, false, '2026-09-26T10:00:00Z', 'gitlab');
        $visitor = VisitorView::preview($preview->withContent(listing: ['entries' => [], 'limited' => false]), 3);
        self::assertInstanceOf(Preview::class, $visitor);
        self::assertSame(['id' => 42, 'fullName' => 'group/app', 'private' => true, 'defaultBranch' => 'main'], $visitor->repository);
        self::assertSame(['upstream', 3], [$visitor->contentSource, $visitor->policyVersion]);
        self::assertStringNotContainsStringIgnoringCase('gitlab', json_encode($visitor, JSON_THROW_ON_ERROR));
        self::assertSame('cache', VisitorView::preview($preview->with(contentSource: 'cache'), 3)->contentSource);

        $branches = VisitorView::preview(new BranchChoices(['main'], null, 'main', false, '', 'github'), 5);
        self::assertSame(['items' => ['main'], 'nextPage' => null, 'defaultBranch' => 'main', 'allowHistory' => false, 'checkedAt' => '',
            'contentSource' => 'upstream', 'policyVersion' => 5], json_decode(json_encode($branches, JSON_THROW_ON_ERROR), true));
    }

    public function testPreviewJsonCarriesOnlyTheContentOfItsKind(): void
    {
        $preview = new Preview(
            ['provider' => 'github', 'id' => 7, 'fullName' => 'owner/app', 'private' => true, 'defaultBranch' => 'main'],
            str_repeat('a', 40),
            'README.md',
            'file',
            'main',
            true,
            false,
            '2026-09-26T10:00:00Z',
            'cache',
        );
        $file = $preview->withContent(file: ['path' => 'README.md', 'commitSha' => str_repeat('a', 40), 'bytes' => 2, 'content' => 'hi']);
        self::assertSame(
            ['repository', 'commitSha', 'path', 'kind', 'branch', 'allowHistory', 'historical', 'checkedAt', 'contentSource', 'file'],
            array_keys((array) json_decode(json_encode($file, JSON_THROW_ON_ERROR), true)),
        );
        self::assertSame(['main'], (array) json_decode(json_encode(new BranchChoices(['main'], null, 'main', true), JSON_THROW_ON_ERROR), true)['items']);
        self::assertArrayNotHasKey('policyVersion', (array) json_decode(json_encode(new BranchChoices(['main'], null, 'main', true), JSON_THROW_ON_ERROR), true));
    }
}
