<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Joins;

use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Profile;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Review;
use Sidus\DoctrineFilterBundle\Tests\Support\DoctrineTestCase;
use Sidus\FilterBundle\Definition\ListDefinition;

/**
 * Named joins (`options.joins`) and the opt-in shared collections, on every platform.
 */
abstract class AbstractNamedJoinTestCase extends DoctrineTestCase
{
    private const array JOINS = [
        // A profile is not associated with Author in the mapping: joined on profile.authorId = author.id.
        'profile' => ['entity' => Profile::class, 'on' => ['field' => 'authorId', 'equals' => 'author.id'], 'cardinality' => 'one'],
        // Reviews of the post, a collection without association: one EXISTS per criterion...
        'review' => ['entity' => Review::class, 'on' => ['field' => 'postId', 'equals' => 'id'], 'cardinality' => 'many'],
        // ... or one shared join.
        'shared_review' => ['entity' => Review::class, 'on' => ['field' => 'postId', 'equals' => 'id'], 'cardinality' => 'many', 'shared' => true],
        // A chain: the second join is on the first one (shared collections: joined, not correlated).
        'first' => ['entity' => Review::class, 'on' => ['field' => 'postId', 'equals' => 'id'], 'cardinality' => 'many', 'shared' => true],
        'second' => ['entity' => Review::class, 'on' => ['field' => 'postId', 'equals' => 'first.postId'], 'cardinality' => 'many', 'shared' => true],
    ];

    /**
     * @param array<string, array<string, mixed>> $specs
     * @param array<string, mixed>                $input
     */
    private function probe(array $specs, array $input, ?\Closure $customizer = null, bool $total = false): \Sidus\DoctrineFilterBundle\Tests\Support\Probe
    {
        return self::execute(self::list($specs, ['joins' => self::JOINS]), $input, $customizer, $total);
    }

    private static function condition(): string
    {
        return version_compare(\Composer\InstalledVersions::getVersion('doctrine/orm') ?? '0', '3.7.0', '>=') ? 'ON' : 'WITH';
    }

    public function testANamedJoinOfCardinalityOneIsAnOrdinarySharedLeftJoin(): void
    {
        $specs = ['bio' => ['type' => 'text', 'attributes' => ['profile.bio']], 'bio_exact' => ['type' => 'exact', 'attributes' => ['profile.bio']]];

        $probe = $this->probe($specs, ['filters' => ['bio' => 'bio', 'bio_exact' => 'Alice bio']]);

        self::assertSame([1, 3], $probe->ids());
        $dql = $probe->dql();
        self::assertSame(1, substr_count($dql, 'LEFT JOIN '.Profile::class.' j_profile '.self::condition().' j_profile.authorId = author_.id'), $dql);
        self::assertStringContainsString('LEFT JOIN root.author author_ LEFT JOIN '.Profile::class.' j_profile', $dql, 'the prefix of the condition is joined first');
    }

    public function testANamedJoinOnTheRootNeedsNoOtherJoin(): void
    {
        $specs = ['comment' => ['type' => 'text', 'attributes' => ['shared_review.comment']]];

        $probe = $this->probe($specs, ['filters' => ['comment' => 'great']]);

        self::assertStringContainsString('LEFT JOIN '.Review::class.' j_shared_review '.self::condition().' j_shared_review.postId = root.id', $probe->dql());
        self::assertSame([1, 4], $probe->ids());
    }

    public function testNullAndSortThroughANamedJoin(): void
    {
        $specs = [
            'no_profile' => ['type' => 'null', 'attributes' => ['profile']],
            'sort' => ['type' => 'sort', 'options' => ['columns' => ['bio' => 'profile.bio']]],
        ];

        self::assertSame([2, 4, 6], $this->probe($specs, ['filters' => ['no_profile' => '1']])->ids(), 'bob and the post without author');
        self::assertSame([2, 4, 6, 1, 3, 5], $this->probe($specs, ['sort' => 'bio'])->ids(), 'nulls first, then "Alice bio" (1, 3), "Émile bio" (5)');
        self::assertSame([5, 1, 3, 2, 4, 6], $this->probe($specs, ['sort' => '-bio'])->ids());
    }

    public function testANamedJoinOfCardinalityManyIsOneExistsPerCriterion(): void
    {
        $specs = ['a' => ['type' => 'exact', 'attributes' => ['review.score']], 'b' => ['type' => 'exact', 'attributes' => ['review.score']]];

        // Independent "any": a review scoring 5 AND a review scoring 3 (not necessarily the same one).
        $probe = $this->probe($specs, ['filters' => ['a' => '5', 'b' => '3']]);

        self::assertSame([1], $probe->ids());
        self::assertStringContainsString('EXISTS (SELECT 1 FROM '.Review::class.' x1_review WHERE x1_review.postId = root.id AND x1_review.score = :p1)', $probe->dql());
        self::assertStringContainsString('EXISTS (SELECT 1 FROM '.Review::class.' x2_review WHERE x2_review.postId = root.id AND x2_review.score = :p2)', $probe->dql());
        self::assertStringNotContainsString('JOIN', $probe->dql());
        self::assertFalse($probe->context->isDistinct());
    }

    public function testASharedNamedJoinMeansTheSameElementAndSwitchesToDistinct(): void
    {
        $specs = [
            'a' => ['type' => 'exact', 'attributes' => ['shared_review.score']],
            'b' => ['type' => 'text', 'attributes' => ['shared_review.comment']],
        ];

        // The same review must score 5 and have a comment containing "again": post 4 only.
        $probe = $this->probe($specs, ['filters' => ['a' => '5', 'b' => 'again']], null, true);
        self::assertSame([4], $probe->ids());
        self::assertSame(1, $probe->total);

        // Different reviews do not satisfy it: post 1 has a 5 ("great") and a 3 ("ok").
        self::assertSame([], $this->probe($specs, ['filters' => ['a' => '3', 'b' => 'great']])->ids());

        // Post 4 has two reviews scoring 5: one row each, yet one result and one count.
        $duplicates = $this->probe(['a' => $specs['a']], ['filters' => ['a' => '5']], null, true);
        self::assertSame([1, 4], $duplicates->ids());
        self::assertSame(2, $duplicates->total);
        self::assertTrue($duplicates->context->isDistinct());
    }

    public function testTheSharedOptionOfAFilterJoinsItsCollection(): void
    {
        $shared = ['doctrine' => ['join' => 'shared']];
        $specs = [
            'a' => ['type' => 'exact', 'attributes' => ['tags.label'], 'provider_options' => $shared],
            'b' => ['type' => 'text', 'attributes' => ['tags.label'], 'provider_options' => $shared],
        ];
        $list = self::list($specs);

        // One tag must be exactly "symfony" and contain "fony"; independently (EXISTS) post 5 would not match.
        $probe = self::execute($list, ['filters' => ['a' => 'symfony', 'b' => 'sym']], null, true);

        self::assertSame([1, 4, 6], $probe->ids());
        self::assertSame(3, $probe->total);
        self::assertStringContainsString('LEFT JOIN root.tags tags_', $probe->dql());
        self::assertStringNotContainsString('EXISTS', $probe->dql());
        self::assertTrue($probe->context->isDistinct());

        // Same element: no tag is both "php" and "symfony", where two EXISTS would find post 1.
        self::assertSame([], self::execute($list, ['filters' => ['a' => 'php', 'b' => 'symfony']])->ids());
    }

    public function testIsNullOnASharedCollectionPath(): void
    {
        $specs = ['tags_empty' => ['type' => 'text', 'attributes' => ['tags'], 'options' => ['operators' => ['is_empty', 'is_not_empty']]]];

        self::assertSame([3], self::execute(self::list($specs), ['filters' => ['tags_empty' => ['operator' => 'is_empty']]])->ids());
    }

    public function testTheCustomizerSeesNamedJoinsThroughTheRegistry(): void
    {
        $specs = ['bio' => ['type' => 'text', 'attributes' => ['profile.bio']]];

        $probe = $this->probe($specs, ['filters' => ['bio' => 'mile']], static function ($context): void {
            $context->queryBuilder()->addSelect('author_.name AS HIDDEN authorName');
            $context->joins()->aliasFor('author');
        });

        self::assertSame([5], $probe->ids());
        self::assertSame(1, substr_count($probe->dql(), 'LEFT JOIN root.author'));
    }

    public function testANamedJoinCanBeOnAnotherSharedOne(): void
    {
        $specs = [
            'a' => ['type' => 'exact', 'attributes' => ['first.score']],
            'b' => ['type' => 'exact', 'attributes' => ['second.score']],
        ];

        $probe = $this->probe($specs, ['filters' => ['a' => '5', 'b' => '3']], null, true);

        self::assertSame([1], $probe->ids(), 'a review scoring 5 and, on the same post, one scoring 3');
        self::assertSame(1, $probe->total);
        $on = self::condition();
        self::assertStringContainsString(
            'LEFT JOIN '.Review::class.' j_first '.$on.' j_first.postId = root.id LEFT JOIN '.Review::class.' j_second '.$on.' j_second.postId = j_first.postId',
            $probe->dql(),
            'the referenced join comes first',
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function computedSort(): array
    {
        return ['sort' => ['type' => 'sort', 'options' => ['columns' => [
            'views' => ['field' => 'views', 'provider_options' => ['doctrine' => ['expression' => 'COALESCE({root}.views, 0)']]],
            'nulls_last' => ['field' => 'views', 'provider_options' => ['doctrine' => ['expression' => [
                ['expression' => 'CASE WHEN {root}.views IS NULL THEN 1 ELSE 0 END', 'direction' => 'asc'],
                '{root}.views',
            ]]]],
        ]]]];
    }

    public function testAnExpressionSortOrdersByAComputedKey(): void
    {
        $specs = self::computedSort();

        $asc = $this->probe($specs, ['sort' => 'views']);
        self::assertSame([3, 5, 2, 1, 4, 6], $asc->ids(), 'a null view counts as 0');
        self::assertStringContainsString('(COALESCE(root.views, 0)) AS HIDDEN sort_1_1', $asc->dql());
        self::assertSame([6, 1, 4, 2, 5, 3], $this->probe($specs, ['sort' => '-views'])->ids());
    }

    public function testAnExpressionSortCanPinTheDirectionOfAKey(): void
    {
        $specs = self::computedSort();

        // The first key (null flag) is pinned ascending whatever the direction asked: nulls last both ways.
        self::assertSame([5, 2, 1, 4, 6, 3], $this->probe($specs, ['sort' => 'nulls_last'])->ids());
        self::assertSame([6, 1, 4, 2, 5, 3], $this->probe($specs, ['sort' => '-nulls_last'])->ids());
        self::assertSame(1, substr_count($this->probe($specs, ['sort' => 'nulls_last'])->dql(), 'ORDER BY'));
    }

    public function testToManyAggregateSortsOnEveryPlatform(): void
    {
        $columns = [
            'tags' => ['field' => 'tags', 'provider_options' => ['doctrine' => ['aggregate' => 'count']]],
            'first_tag' => ['field' => 'tags.label', 'provider_options' => ['doctrine' => ['aggregate' => 'min']]],
        ];
        $specs = ['sort' => ['type' => 'sort', 'options' => ['columns' => $columns]]];

        self::assertSame([3, 2, 6, 1, 4, 5], $this->probe($specs, ['sort' => 'tags'])->ids());
        self::assertSame([1, 4, 5, 2, 6, 3], $this->probe($specs, ['sort' => '-tags'])->ids());
        // Nulls (no tag) first ascending, then "100%", "php" (1, 2, 5), "symfony".
        self::assertSame([3, 4, 1, 2, 5, 6], self::execute(self::list($specs, ['binary_sort' => true]), ['sort' => 'first_tag'])->ids());
        self::assertSame([6, 1, 2, 5, 4, 3], self::execute(self::list($specs, ['binary_sort' => true]), ['sort' => '-first_tag'])->ids());
    }
}
