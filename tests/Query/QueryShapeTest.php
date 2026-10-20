<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Query;

use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Tag;
use Sidus\DoctrineFilterBundle\Tests\Support\DoctrineTestCase;

/**
 * The shape of the DQL: shared to-one joins, one EXISTS per to-many criterion, deterministic names, one ORDER BY.
 */
final class QueryShapeTest extends DoctrineTestCase
{
    public function testToOnePrefixesShareOneLeftJoin(): void
    {
        $list = self::list(['q', 'author_name', 'sort']);

        $probe = self::execute($list, ['filters' => ['q' => 'bob', 'author_name' => 'li'], 'sort' => 'author']);

        self::assertSame(1, substr_count($probe->dql(), 'LEFT JOIN root.author author_'), $probe->dql());
        self::assertSame(1, substr_count($probe->dql(), 'JOIN'), 'no other join: '.$probe->dql());
        self::assertStringContainsString('LOWER(author_.name) LIKE', $probe->dql());
        self::assertStringContainsString('ORDER BY CASE WHEN author_.name IS NULL THEN 0 ELSE 1 END ASC, author_.name ASC, root.id ASC', $probe->dql());
    }

    public function testNestedToOnePathsGetDeterministicAliases(): void
    {
        $list = self::list(['company' => ['type' => 'text', 'attributes' => ['author.company.name']], 'author_name']);

        $probe = self::execute($list, ['filters' => ['company' => 'acm', 'author_name' => 'li']]);

        self::assertStringContainsString('LEFT JOIN root.author author_ LEFT JOIN author_.company author_company_ WHERE', $probe->dql());
        self::assertStringContainsString('LOWER(author_company_.name) LIKE :p1', $probe->dql());
        self::assertStringContainsString('LOWER(author_.name) LIKE :p2', $probe->dql());
        self::assertSame([1, 3], $probe->ids());
    }

    public function testEachToManyCriterionIsItsOwnExistsSubquery(): void
    {
        $list = self::list(['tag_a', 'tag_b']);

        $probe = self::execute($list, ['filters' => ['tag_a' => 'php', 'tag_b' => 'symfony']]);

        $tag = Tag::class;
        self::assertStringContainsString("EXISTS (SELECT 1 FROM $tag x1_tags WHERE x1_tags.post = root AND x1_tags.label = :p1)", $probe->dql());
        self::assertStringContainsString("EXISTS (SELECT 1 FROM $tag x2_tags WHERE x2_tags.post = root AND x2_tags.label = :p2)", $probe->dql());
        self::assertStringNotContainsString('JOIN', $probe->dql(), 'no row-multiplying join');
        self::assertSame([1], $probe->ids());
    }

    public function testParametersAreNamedInCompilationOrder(): void
    {
        $list = self::list(['title', 'views', 'status']);

        $probe = self::execute($list, ['filters' => [
            'title' => 'hel',
            'views' => ['operator' => 'gte', 'value' => 20],
            'status' => ['draft', 'published'],
        ]]);

        self::assertSame(['p1', 'p2', 'p3'], array_keys($probe->parameters()));
        self::assertSame('%hel%', $probe->parameters()['p1']);
        self::assertSame(20, $probe->parameters()['p2']);
        self::assertSame(['draft', 'published'], $probe->parameters()['p3']);
        self::assertStringContainsString('root.status IN (:p3)', $probe->dql());
    }

    public function testThereIsOneOrderByEndingWithTheIdentifier(): void
    {
        $list = self::list(['sort']);

        $probe = self::execute($list, ['sort' => '-views,title']);

        self::assertSame(1, substr_count($probe->dql(), 'ORDER BY'));
        self::assertStringContainsString(
            'ORDER BY CASE WHEN root.views IS NULL THEN 1 ELSE 0 END ASC, root.views DESC, root.title ASC, root.id ASC',
            $probe->dql(),
            'a non-nullable column has no null key; the tie-breaker is always ascending',
        );
    }

    public function testWithoutASortFilterPagesStillAreOrderedByTheIdentifier(): void
    {
        $probe = self::execute(self::list(['title']), ['filters' => ['title' => 'hello']]);

        self::assertStringContainsString('ORDER BY root.id ASC', $probe->dql());
        self::assertSame([1, 2], $probe->ids());
    }

    public function testACriterionCanBeNegatedWithoutLosingNulls(): void
    {
        $list = self::list(['summary']);

        $probe = self::execute($list, ['filters' => ['summary' => ['operator' => 'not_contains', 'value' => 'first']]]);

        self::assertStringContainsString('CASE WHEN (LOWER(root.summary) LIKE :p1 ESCAPE \'!\') THEN 0 ELSE 1 END = 1', $probe->dql());
        self::assertSame([2, 3, 4, 5, 6], $probe->ids(), 'NULL and empty summaries are not "first"');
    }

    public function testLikeWildcardsAreEscaped(): void
    {
        $probe = self::execute(self::list(['summary']), ['filters' => ['summary' => '100%_!']]);

        self::assertSame('%100!%!_!!%', $probe->parameters()['p1']);
    }

    public function testACustomizerRunsFirstAndCanReuseTheSharedToOneAlias(): void
    {
        $list = self::list(['author_name']);

        $probe = self::execute(
            $list,
            ['filters' => ['author_name' => 'ali']],
            static function (DoctrineQueryContext $context): void {
                $alias = $context->joins()->aliasFor('author');
                $context->queryBuilder()->addSelect($alias.'.name AS HIDDEN authorName');
            },
        );

        self::assertSame(1, substr_count($probe->dql(), 'JOIN'), $probe->dql());
        self::assertStringStartsWith('SELECT root, author_.name AS HIDDEN authorName FROM', $probe->dql());
        self::assertSame([1, 3], $probe->ids());
    }

    public function testACustomizerAliasIsNeverReusedByTheRegistry(): void
    {
        $list = self::list(['author_name']);

        $probe = self::execute(
            $list,
            ['filters' => ['author_name' => 'ali']],
            static fn (DoctrineQueryContext $context) => $context->queryBuilder()->leftJoin('root.tags', 'author_'),
        );

        self::assertStringContainsString('LEFT JOIN root.author author_2_', $probe->dql());
    }
}
