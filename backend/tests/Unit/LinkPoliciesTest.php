<?php

declare(strict_types=1);

namespace Glimpse\Tests\Unit;

use Glimpse\Failure;
use Glimpse\FailureKind;
use Glimpse\Sharing\LinkPolicies;
use Glimpse\Sharing\VisibilityChoice;
use PHPUnit\Framework\TestCase;

/** How the file rules of a link are chosen per branch, checked without a database. */
final class LinkPoliciesTest extends TestCase
{
    /** @param list<array<string, mixed>> $choices
     *  @param list<string> $branches
     *  @param list<string> $existing
     */
    private static function rejected(?array $choices, array $branches, array $existing, string $code): Failure
    {
        try {
            LinkPolicies::choose($choices === null ? null : VisibilityChoice::fromList($choices), $branches, $existing);
        } catch (Failure $error) {
            self::assertSame([FailureKind::Invalid, $code], [$error->kind, $error->errorCode]);
            return $error;
        }
        self::fail('The choices were accepted.');
    }

    public function testEveryBranchGetsExactlyOneExplicitRule(): void
    {
        $choices = VisibilityChoice::fromList([['branch' => 'dev', 'allFiles' => true], ['branch' => 'main', 'profileVersion' => 4], ['branch' => 'docs', 'keep' => true]]);
        self::assertSame([
            ['branch' => 'dev', 'kind' => 'all', 'version' => 0],
            ['branch' => 'main', 'kind' => 'profile', 'version' => 4],
            ['branch' => 'docs', 'kind' => 'keep', 'version' => 0],
        ], LinkPolicies::choose($choices, ['main', 'dev', 'docs'], ['docs']));

        // A saved profile version is the most specific choice.
        $both = VisibilityChoice::fromList([['branch' => 'main', 'profileVersion' => 2, 'allFiles' => true, 'keep' => true]]);
        self::assertSame([['branch' => 'main', 'kind' => 'profile', 'version' => 2]], LinkPolicies::choose($both, ['main'], ['main']));
        self::assertSame([['branch' => 'main', 'kind' => 'keep', 'version' => 0]], LinkPolicies::choose([VisibilityChoice::keep('main')], ['main'], ['main']));
    }

    public function testMissingExtraOrAmbiguousChoicesAreRejected(): void
    {
        self::rejected(null, ['main'], [], 'visibility_required');
        self::rejected([], ['main'], [], 'visibility_required');
        self::rejected([['branch' => 'main', 'allFiles' => true]], ['main', 'dev'], [], 'visibility_required');
        self::rejected([['branch' => 'secret', 'allFiles' => true]], ['main'], [], 'invalid_visibility');
        self::rejected([['allFiles' => true]], ['main'], [], 'invalid_visibility');
        self::rejected([['branch' => 'main', 'allFiles' => true], ['branch' => 'main', 'profileVersion' => 1]], ['main'], [], 'invalid_visibility');
        // A version must be a positive number, and nothing chosen is not a choice.
        self::rejected([['branch' => 'main', 'profileVersion' => 0]], ['main'], [], 'invalid_visibility');
        self::rejected([['branch' => 'main', 'profileVersion' => '3']], ['main'], [], 'invalid_visibility');
        self::rejected([['branch' => 'main', 'allFiles' => 'true']], ['main'], [], 'invalid_visibility');
    }

    public function testOnlyABranchWithRulesCanKeepThem(): void
    {
        // New links and branches added on an edit have no rules to keep.
        $error = self::rejected([['branch' => 'main', 'keep' => true]], ['main'], [], 'invalid_visibility');
        self::assertStringContainsString('main', $error->getMessage());
        self::rejected([['branch' => 'main', 'keep' => true], ['branch' => 'dev', 'keep' => true]], ['main', 'dev'], ['main'], 'invalid_visibility');
    }
}
