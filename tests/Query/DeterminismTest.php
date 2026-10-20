<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Query;

use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Sidus\DoctrineFilterBundle\Tests\Support\DoctrineTestCase;

/**
 * Same input shape, same DQL: no random alias or parameter name anywhere, so Doctrine's query cache hits.
 */
final class DeterminismTest extends DoctrineTestCase
{
    private const array SPECS = ['q', 'title', 'views', 'status', 'tag_a', 'tag_b', 'author_null', 'summary', 'sort'];

    /**
     * @return array<string, mixed>
     */
    private static function input(string $title = 'hello', string $tag = 'php'): array
    {
        return [
            'filters' => [
                'q' => 'ali',
                'title' => $title,
                'views' => ['operator' => 'gte', 'value' => 10],
                'status' => ['draft', 'published'],
                'tag_a' => $tag,
                'tag_b' => 'symfony',
                'summary' => ['operator' => 'not_contains', 'value' => 'x'],
            ],
            'sort' => '-views,author',
            'page' => 1,
            'per_page' => 5,
        ];
    }

    public function testTheSameInputGivesTheSameDqlAndParameterNames(): void
    {
        $list = self::list(self::SPECS);

        $first = self::execute($list, self::input());
        $second = self::execute($list, self::input());

        self::assertSame($first->dql(), $second->dql());
        self::assertSame($first->parameters(), $second->parameters());
        self::assertSame($first->context->queryBuilder()->getQuery()->getSQL(), $second->context->queryBuilder()->getQuery()->getSQL());
    }

    public function testOtherValuesGiveTheSameDqlAndOnlyOtherParameterValues(): void
    {
        $list = self::list(self::SPECS);

        $first = self::execute($list, self::input('hello', 'php'));
        $second = self::execute($list, self::input('zoë', 'symfony'));

        self::assertSame($first->dql(), $second->dql());
        self::assertSame(array_keys($first->parameters()), array_keys($second->parameters()));
        self::assertNotSame($first->parameters(), $second->parameters());
        self::assertSame(['p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7', 'p8'], array_keys($first->parameters()));
    }

    public function testTheOrderOfTheInputDoesNotMatter(): void
    {
        $list = self::list(self::SPECS);
        $input = self::input();
        $reversed = ['filters' => array_reverse($input['filters'], true)] + $input;

        self::assertSame(self::execute($list, $input)->dql(), self::execute($list, $reversed)->dql(), 'the definition order drives the compilation order');
    }

    public function testABlankFilterIsAsGoodAsAnAbsentOne(): void
    {
        $list = self::list(self::SPECS);
        $input = self::input();
        $withBlank = $input;
        $withBlank['filters']['author_null'] = '0';
        $withBlank['filters']['tag_b'] = '';
        unset($input['filters']['tag_b']);

        self::assertSame(self::execute($list, $input)->dql(), self::execute($list, $withBlank)->dql());
    }

    public function testTheDqlVariesOnlyWithTheActiveFilters(): void
    {
        $list = self::list(self::SPECS);

        $shapes = [];
        foreach ([['title' => 'a'], ['title' => 'b'], ['title' => 'a', 'tag_a' => 'x'], ['tag_a' => 'y', 'title' => 'c'], []] as $filters) {
            $shapes[self::execute($list, ['filters' => $filters])->dql()] = true;
        }

        self::assertCount(3, $shapes, 'none, title, title + tag: a finite set of shapes');
    }

    public function testNothingIsRandom(): void
    {
        $probe = self::execute(self::list(self::SPECS), self::input());

        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{13}/', $probe->dql(), 'no uniqid()');
        self::assertDoesNotMatchRegularExpression('/\d+\.\d{5,}/', $probe->dql());
        preg_match_all('/:([a-z_0-9]+)/', $probe->dql(), $names);
        self::assertSame(array_map(static fn (int $n): string => 'p'.$n, range(1, 8)), array_values(array_unique($names[1])));
    }

    public function testDifferentHandlersOfTheSameListShareOneCachedParse(): void
    {
        $cache = new ArrayAdapter();
        $configuration = self::entityManager()->getConfiguration();
        $previous = $configuration->getQueryCache();
        $configuration->setQueryCache($cache);

        try {
            $list = self::list(self::SPECS);
            foreach ([['hello', 'php'], ['zoë', 'symfony'], ['école', 'x']] as [$title, $tag]) {
                self::execute($list, self::input($title, $tag), readTotal: true);
            }

            // One entry for the page query, one for the count query, whatever the values.
            self::assertCount(2, $cache->getValues());
        } finally {
            $configuration->setQueryCache($previous);
        }
    }
}
