<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Query;

use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\DoctrineFilterBundle\Tests\Support\DoctrineTestCase;
use Sidus\FilterBundle\Criterion\Native;
use Sidus\FilterBundle\Definition\FilterDefinition;
use Sidus\FilterBundle\Filter\NativeCriterionBuilderInterface;
use Sidus\FilterBundle\Registry\FilterTypeRegistry;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Behaviour beyond the shared contract: nested paths, aggregates, distinct mode, the native escape hatch, refusals.
 */
final class SemanticsTest extends DoctrineTestCase
{
    /**
     * @param array<string, mixed> $filters
     *
     * @return list<int>
     */
    private function ids(array $specs, array $filters, string $sort = ''): array
    {
        return self::execute(self::list($specs), ['filters' => $filters] + ('' === $sort ? [] : ['sort' => $sort]))->ids();
    }

    public function testNestedToOneIsNullTreatsAMissingMiddleAsNull(): void
    {
        $spec = ['company_null' => ['type' => 'null', 'attributes' => ['author.company']], 'company_set' => ['type' => 'not_null', 'attributes' => ['author.company']]];

        // bob has no company (posts 2, 6), post 4 has no author at all.
        self::assertSame([2, 4, 6], $this->ids($spec, ['company_null' => '1']));
        self::assertSame([1, 3, 5], $this->ids($spec, ['company_set' => '1']));
    }

    public function testNestedNotContainsMatchesAMissingMiddle(): void
    {
        $spec = ['company' => ['type' => 'text', 'attributes' => ['author.company.name'], 'options' => ['operators' => ['contains', 'not_contains']]]];

        self::assertSame([1, 3], $this->ids($spec, ['company' => 'acm']));
        self::assertSame([2, 4, 5, 6], $this->ids($spec, ['company' => ['operator' => 'not_contains', 'value' => 'acm']]));
    }

    public function testEmbeddedFields(): void
    {
        $spec = ['city' => ['type' => 'text', 'attributes' => ['author.company.address.city']]];

        self::assertSame([1, 3], $this->ids($spec, ['city' => 'lyo']));
        self::assertSame([5], $this->ids($spec, ['city' => 'PARIS']));
    }

    public function testAnyOfOverAToManyAndAToOnePath(): void
    {
        $spec = ['q' => ['type' => 'text', 'attributes' => ['author.name', 'tags.label']]];

        self::assertSame([2, 6], $this->ids($spec, ['q' => 'bob']), 'the author only');
        self::assertSame([1, 2, 5], $this->ids($spec, ['q' => 'php']), 'a tag only');
        // bob (2, 6) by name; "symfony" and "sym_fony" tags (1, 4, 5, 6).
        self::assertSame([1, 2, 4, 5, 6], $this->ids($spec, ['q' => 'o']), 'either');
    }

    public function testToManyAggregateSorts(): void
    {
        $columns = [
            'tags' => ['field' => 'tags', 'provider_options' => ['doctrine' => ['aggregate' => 'count']]],
            'first_tag' => ['field' => 'tags.label', 'provider_options' => ['doctrine' => ['aggregate' => 'min']]],
            'last_tag' => ['field' => 'tags.label', 'provider_options' => ['doctrine' => ['aggregate' => 'max']]],
        ];
        $list = self::list(['sort' => ['type' => 'sort', 'options' => ['columns' => $columns]]]);

        $count = self::execute($list, ['sort' => 'tags']);
        self::assertSame([3, 2, 6, 1, 4, 5], $count->ids());
        self::assertStringContainsString('(SELECT COUNT(x1_tags) FROM ', $count->dql());
        self::assertStringContainsString('AS HIDDEN sort_1', $count->dql());
        self::assertSame([1, 4, 5, 2, 6, 3], self::execute($list, ['sort' => '-tags'])->ids());
        // nulls (no tag) first ascending, then "100%", "php" x3, "symfony".
        self::assertSame([3, 4, 1, 2, 5, 6], self::execute($list, ['sort' => 'first_tag'])->ids());
        self::assertSame([3, 2, 5, 1, 4, 6], self::execute($list, ['sort' => 'last_tag'])->ids(), 'max: none (3), php (2), sym_fony (5), symfony (1, 4, 6)');
    }

    public function testSortingOnAToManyPathWithoutAnAggregateIsRefused(): void
    {
        $list = self::list(['sort' => ['type' => 'sort', 'options' => ['columns' => ['tag' => 'tags.label']]]]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"tags.label" goes through a to-many association: a sort needs an aggregate');

        self::execute($list, ['sort' => 'tag']);
    }

    public function testTextCriteriaOnANumberAreRefused(): void
    {
        $list = self::list(['v' => ['type' => 'text', 'attributes' => ['views']]]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('text criteria need a string field, "views" is of type "integer"');

        self::execute($list, ['filters' => ['v' => '10']]);
    }

    public function testCustomizerThatJoinsACollectionSwitchesToDistinctMode(): void
    {
        $list = self::list(['title']);
        $join = static function (DoctrineQueryContext $context): void {
            $context->queryBuilder()->leftJoin('root.tags', 'fetched')->addSelect('fetched');
            $context->requireDistinct();
        };

        $probe = self::execute($list, ['page' => 2, 'per_page' => 4], $join, true);

        self::assertSame([5, 6], $probe->ids(), 'one row per post, not per tag');
        self::assertSame(6, $probe->total, 'COUNT(DISTINCT id)');
    }

    public function testTheNativeEscapeHatchBuildsDql(): void
    {
        $builder = new class() implements NativeCriterionBuilderInterface {
            public function __invoke(FilterDefinition $filter, mixed $value): Native
            {
                return new Native('doctrine', static fn (DoctrineQueryContext $context): string => $context->rootAlias().'.views > '.$context->parameter((int) $value));
            }
        };
        $types = FilterTypeRegistry::builtIn(new ServiceLocator(['app.views' => static fn () => $builder]));
        $list = self::list(['popular' => ['type' => 'native', 'options' => ['builder' => 'app.views']]], types: $types);

        $probe = self::execute($list, ['filters' => ['popular' => '99']], types: $types);

        self::assertSame([1, 4, 6], $probe->ids());
        self::assertStringContainsString('(root.views > :p1)', $probe->dql());
    }

    public function testANativeCriterionForAnotherProviderIsRefused(): void
    {
        $builder = new class() implements NativeCriterionBuilderInterface {
            public function __invoke(FilterDefinition $filter, mixed $value): Native
            {
                return new Native('elasticsearch', ['term' => $value]);
            }
        };
        $types = FilterTypeRegistry::builtIn(new ServiceLocator(['app.es' => static fn () => $builder]));
        $list = self::list(['es' => ['type' => 'native', 'options' => ['builder' => 'app.es']]], types: $types);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A native criterion for provider "elasticsearch" cannot be applied by the doctrine provider');

        self::execute($list, ['filters' => ['es' => 'x']], types: $types);
    }

    public function testILikeIsRefusedOutsidePostgreSql(): void
    {
        $list = self::list(['title' => ['type' => 'text', 'provider_options' => ['doctrine' => ['case_insensitive' => 'ilike']]]]);

        $this->expectException(\Sidus\FilterBundle\Exception\ConfigurationException::class);
        $this->expectExceptionMessage('case_insensitive "ilike" is available on PostgreSQL only, the connection is SQLite');

        self::factory()->createQueryHandler($list, FilterTypeRegistry::builtIn(), null);
    }

    public function testTwoFiltersOnOneFieldMayDeclareDifferentOptions(): void
    {
        // On SQLite `ilike` is refused at creation, so compare `lower` with the default: the option travels on the criterion.
        $list = self::list([
            'a' => ['type' => 'text', 'attributes' => ['tags.label'], 'provider_options' => ['doctrine' => ['join' => 'shared']]],
            'b' => ['type' => 'text', 'attributes' => ['tags.label']],
        ]);

        $probe = self::execute($list, ['filters' => ['a' => 'php', 'b' => 'symfony']]);

        self::assertSame(1, substr_count($probe->dql(), 'LEFT JOIN root.tags tags_'), 'a is joined');
        self::assertSame(1, substr_count($probe->dql(), 'EXISTS ('), 'b is its own EXISTS: '.$probe->dql());
        self::assertSame([1], $probe->ids(), 'a tag is php (joined), some tag is symfony (EXISTS)');
    }

    public function testTheEntityMustBeManaged(): void
    {
        $this->expectException(\Sidus\FilterBundle\Exception\ConfigurationException::class);
        $this->expectExceptionMessage('stdClass is not managed by a Doctrine ORM entity manager');

        self::factory()->createQueryHandler(self::list([], ['entity' => \stdClass::class]), FilterTypeRegistry::builtIn(), null);
    }
}
