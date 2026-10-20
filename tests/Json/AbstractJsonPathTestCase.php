<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Json;

use PHPUnit\Framework\Attributes\DataProvider;
use Sidus\DoctrineFilterBundle\Tests\Support\DoctrineTestCase;
use Sidus\FilterBundle\Definition\Field;
use Sidus\FilterBundle\Definition\ListDefinition;
use Sidus\FilterBundle\Input\ListInput;
use Sidus\FilterBundle\InMemory\ArraySource;
use Sidus\FilterBundle\InMemory\InMemoryQueryHandlerFactory;
use Sidus\FilterBundle\Registry\FilterTypeRegistry;

/**
 * JSON paths on the `meta` column: every case runs on the database and on the in-memory reference, over the same
 * documents, and both must give the expected ids.
 */
abstract class AbstractJsonPathTestCase extends DoctrineTestCase
{
    /** @var array<int, array<string, mixed>|null> */
    private const array DOCUMENTS = [
        1 => ['stats' => ['score' => 10, 'ratio' => 0.5, 'featured' => true, 'at' => '2026-01-10T12:00:00+00:00'], 'label' => 'Alpha', 'list' => ['x', 'y']],
        2 => ['stats' => ['score' => 3, 'ratio' => 1.5, 'featured' => false, 'at' => '2026-02-01T09:00:00+00:00'], 'label' => 'beta'],
        3 => null,
        4 => ['stats' => ['score' => null], 'label' => '', 'note' => null],
        5 => ['stats' => ['score' => 7, 'ratio' => 2, 'featured' => true, 'at' => '2025-12-31T23:30:00+00:00'], 'label' => 'Alpha-2'],
        6 => ['label' => 'Gamma', 'list' => ['z']],
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (null === self::$kernel) {
            return;
        }
        $connection = self::entityManager()->getConnection();
        foreach (self::DOCUMENTS as $id => $document) {
            $connection->executeStatement('UPDATE post SET meta = ? WHERE id = ?', [null === $document ? null : json_encode($document, \JSON_THROW_ON_ERROR), $id]);
        }
    }

    /** Whether the platform compiles JSON paths. */
    abstract protected static function supportsJson(): bool;

    /** Whether it also compiles `date` casts. */
    protected static function supportsJsonDates(): bool
    {
        return static::supportsJson();
    }

    /**
     * @param list<string> $json
     */
    protected static function json(array $json, ?string $cast = null): Field
    {
        return new Field('meta', $json, $cast);
    }

    /**
     * @param array<string, array<string, mixed>> $specs
     * @param array<string, mixed>                $input
     *
     * @return array{0: list<int>, 1: list<int>} the ids of the database, then of the in-memory reference
     */
    private function bothIds(array $specs, array $input): array
    {
        $list = self::list($specs, ['binary_sort' => true]); // byte-wise, like the reference
        $database = self::execute($list, $input)->ids();

        $source = new ArraySource(array_map(static fn (int $id): array => ['id' => $id, 'meta' => self::DOCUMENTS[$id]], array_keys(self::DOCUMENTS)));
        $reference = new ListDefinition('reference', 'in_memory', ['source' => 'posts', 'identifier' => 'id'], $list->perPage, $list->maxPerPage, 'auto', $list->filters);
        $result = InMemoryQueryHandlerFactory::standalone(['posts' => $source])
            ->createQueryHandler($reference, FilterTypeRegistry::builtIn(), null)
            ->handle(ListInput::fromArray($input));

        return [$database, array_column($result->items(), 'id')];
    }

    /**
     * @return iterable<string, array{0: array<string, array<string, mixed>>, 1: array<string, mixed>, 2: list<int>}>
     */
    public static function cases(): iterable
    {
        $score = static fn (?string $cast = 'integer'): Field => self::json(['stats', 'score'], $cast);

        yield 'integer greater than' => [['s' => ['type' => 'number', 'attributes' => [$score()], 'options' => ['operators' => ['gt']]]], ['filters' => ['s' => '5']], [1, 5]];
        yield 'integer equals' => [['s' => ['type' => 'number', 'attributes' => [$score()]]], ['filters' => ['s' => '3']], [2]];
        yield 'integer range with null never matching' => [['s' => ['type' => 'number', 'attributes' => [$score()], 'options' => ['operators' => ['lt']]]], ['filters' => ['s' => ['operator' => 'lt', 'value' => 8]]], [2, 5]];
        yield 'float less or equal' => [['r' => ['type' => 'number', 'attributes' => [self::json(['stats', 'ratio'], 'float')], 'options' => ['operators' => ['lte']]]], ['filters' => ['r' => '1.5']], [1, 2]];
        yield 'boolean' => [['f' => ['type' => 'choice', 'attributes' => [self::json(['stats', 'featured'], 'boolean')], 'options' => ['choices' => [true]]]], ['filters' => ['f' => '1']], [1, 5]];
        yield 'text contains is case-insensitive' => [['l' => ['type' => 'text', 'attributes' => [self::json(['label'])]]], ['filters' => ['l' => 'ALPHA']], [1, 5]];
        yield 'text equals is exact' => [['l' => ['type' => 'text', 'attributes' => [self::json(['label'])], 'options' => ['operators' => ['equals']]]], ['filters' => ['l' => 'Alpha']], [1]];
        yield 'text not contains matches a missing document' => [['l' => ['type' => 'text', 'attributes' => [self::json(['label'])], 'options' => ['operators' => ['not_contains']]]], ['filters' => ['l' => ['operator' => 'not_contains', 'value' => 'alpha']]], [2, 3, 4, 6]];
        yield 'empty string or missing' => [['l' => ['type' => 'text', 'attributes' => [self::json(['label'])], 'options' => ['operators' => ['is_empty']]]], ['filters' => ['l' => ['operator' => 'is_empty']]], [3, 4]];
        yield 'json null and missing key are null' => [['n' => ['type' => 'null', 'attributes' => [self::json(['note'])]]], ['filters' => ['n' => '1']], [1, 2, 3, 4, 5, 6]];
        yield 'null score' => [['n' => ['type' => 'null', 'attributes' => [$score()]]], ['filters' => ['n' => '1']], [3, 4, 6]];
        yield 'array index' => [['i' => ['type' => 'text', 'attributes' => [self::json(['list', '0'])]]], ['filters' => ['i' => 'x']], [1]];
        yield 'regexp' => [['p' => ['type' => 'regexp', 'attributes' => [self::json(['label'])]]], ['filters' => ['p' => ['operator' => 'imatches', 'value' => '^a']]], [1, 5]];
        yield 'sort ascending, nulls first' => [['sort' => ['type' => 'sort', 'options' => ['columns' => ['score' => ['path' => 'meta', 'json' => ['stats', 'score'], 'cast' => 'integer']]]]], ['sort' => 'score'], [3, 4, 6, 2, 5, 1]];
        yield 'sort descending, nulls last' => [['sort' => ['type' => 'sort', 'options' => ['columns' => ['score' => ['path' => 'meta', 'json' => ['stats', 'score'], 'cast' => 'integer']]]]], ['sort' => '-score'], [1, 5, 2, 3, 4, 6]];
        yield 'sort on text' => [['sort' => ['type' => 'sort', 'options' => ['columns' => ['label' => ['path' => 'meta', 'json' => ['label']]]]]], ['sort' => 'label'], [3, 4, 1, 5, 6, 2]];
    }

    /**
     * @param array<string, array<string, mixed>> $specs
     * @param array<string, mixed>                $input
     * @param list<int>                           $expected
     */
    #[DataProvider('cases')]
    public function testItMatchesTheReference(array $specs, array $input, array $expected): void
    {
        if (!static::supportsJson()) {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('JSON paths are not available on SQLite');
            self::execute(self::list($specs), $input);

            return;
        }

        [$database, $reference] = $this->bothIds($specs, $input);

        self::assertSame($expected, $reference, 'the reference');
        self::assertSame($expected, $database, 'the database');
    }

    public function testDateCasts(): void
    {
        $specs = ['d' => ['type' => 'date_range', 'attributes' => [self::json(['stats', 'at'], 'date')]]];
        $input = ['filters' => ['d' => ['from' => '2026-01-01', 'to' => '2026-02-01']]];

        if (!static::supportsJsonDates()) {
            $this->expectException(\LogicException::class);
            self::execute(self::list($specs), $input);

            return;
        }

        [$database, $reference] = $this->bothIds($specs, $input);
        self::assertSame([1, 2], $reference);
        self::assertSame([1, 2], $database);
    }

    public function testDateCastsCompareInstantsNotWallClocks(): void
    {
        if (!static::supportsJsonDates()) {
            self::markTestSkipped('No date casts on this platform.');
        }
        $specs = ['d' => ['type' => 'date_range', 'attributes' => [self::json(['stats', 'at'], 'date')]]];
        // 2026-01-10T13:00:00+01:00 is the instant of post 1 (12:00 UTC).
        $input = ['filters' => ['d' => ['from' => '2026-01-10T13:00:00+01:00', 'to' => '2026-01-10T13:00:00+01:00']]];

        [$database, $reference] = $this->bothIds($specs, $input);

        self::assertSame([1], $reference);
        self::assertSame([1], $database);
    }

    public function testTheCompiledDql(): void
    {
        if (!static::supportsJson()) {
            self::markTestSkipped('No JSON paths on this platform.');
        }
        $specs = ['s' => ['type' => 'number', 'attributes' => [self::json(['stats', 'score'], 'integer')], 'options' => ['operators' => ['gt']]]];

        $dql = self::execute(self::list($specs), ['filters' => ['s' => ['operator' => 'gt', 'value' => 5]]])->dql();

        self::assertStringContainsString(static::expectedScoreFragment().' > :p1', $dql);
    }

    abstract protected static function expectedScoreFragment(): string;
}
