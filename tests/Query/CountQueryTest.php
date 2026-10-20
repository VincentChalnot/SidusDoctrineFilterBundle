<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Query;

use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\DoctrineFilterBundle\Tests\Support\DoctrineTestCase;
use Sidus\DoctrineFilterBundle\Tests\Support\RecordingLogger;

/**
 * The total is one extra query, lazy, built on a clone of the page query without ORDER BY.
 */
final class CountQueryTest extends DoctrineTestCase
{
    protected static function kernelOptions(): array
    {
        return ['record_queries' => true];
    }

    /**
     * @return list<string> the SELECT statements executed since the reset
     */
    private function selects(): array
    {
        return array_values(array_filter(RecordingLogger::statements(), static fn (string $sql): bool => str_starts_with($sql, 'SELECT')));
    }

    public function testTheTotalIsALazyCountWithoutOrderByNorLimit(): void
    {
        $list = self::list(['title', 'sort']);
        RecordingLogger::reset();

        $probe = self::execute($list, ['filters' => ['title' => 'hello'], 'sort' => '-views', 'per_page' => 1]);

        self::assertCount(1, $this->selects(), 'the page only, the total is lazy');
        self::assertSame(2, $probe->result->total());

        $selects = $this->selects();
        self::assertCount(2, $selects);
        self::assertMatchesRegularExpression('/^SELECT COUNT\(p0_\.id\) AS sclr_0 FROM post p0_ WHERE LOWER\(p0_\.title\) LIKE \? ESCAPE \'!\'$/', $selects[1]);
        self::assertStringContainsString('ORDER BY', $selects[0]);
        self::assertStringContainsString('LIMIT 1', $selects[0]);
    }

    public function testToManyCriteriaNeedNoDistinct(): void
    {
        RecordingLogger::reset();

        $probe = self::execute(self::list(['tag_a', 'tag_b']), ['filters' => ['tag_a' => 'php', 'tag_b' => 'symfony']]);

        self::assertSame(1, $probe->result->total());
        $count = $this->selects()[1];
        self::assertStringStartsWith('SELECT COUNT(p0_.id)', $count);
        self::assertStringNotContainsString('DISTINCT', $count);
        self::assertSame(2, substr_count($count, 'EXISTS'), $count);
    }

    public function testDistinctModeCountsDistinctIdentifiers(): void
    {
        RecordingLogger::reset();

        $probe = self::execute(
            self::list(['title']),
            [],
            static function (DoctrineQueryContext $context): void {
                $context->queryBuilder()->leftJoin('root.tags', 'fetched');
                $context->requireDistinct();
            },
        );

        self::assertSame(6, $probe->result->total());
        self::assertStringStartsWith('SELECT COUNT(DISTINCT p0_.id)', $this->selects()[array_key_last($this->selects())]);
    }

    public function testASuppliedTotalRunsNoCount(): void
    {
        RecordingLogger::reset();

        $result = self::factory()->createQueryHandler(self::list(['title']), \Sidus\FilterBundle\Registry\FilterTypeRegistry::builtIn(), null)
            ->handle(new \Sidus\FilterBundle\Input\ListInput()->withTotalCount(99));

        self::assertSame(99, $result->total());
        self::assertCount(1, $this->selects());
    }
}
