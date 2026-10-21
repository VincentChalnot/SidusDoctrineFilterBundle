<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Filter;

use Sidus\DoctrineFilterBundle\Tests\Support\DoctrineTestCase;

/**
 * A to-one association whose target has a string identifier (a slug): equality and IN compare the foreign key of the
 * row, which on MySQL and MariaDB must stay exact (case, accents) without wrapping an association path in a function.
 * Posts 1 and 2 are in "music", post 3 in "cinema", the others in none.
 */
abstract class AbstractStringForeignKeyTestCase extends DoctrineTestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string|list<string>, list<int>}>
     */
    public static function filters(): iterable
    {
        $relation = ['type' => 'relation'];
        $relationMultiple = ['type' => 'relation', 'options' => ['multiple' => true]];
        $choice = ['type' => 'choice', 'attributes' => ['category.id']];
        $choiceMultiple = ['type' => 'choice', 'attributes' => ['category.id'], 'options' => ['multiple' => true]];

        yield 'relation' => [$relation, 'music', [1, 2]];
        yield 'relation, other case' => [$relation, 'MUSIC', []];
        yield 'relation, unknown' => [$relation, 'jazz', []];
        yield 'relation multiple' => [$relationMultiple, ['music', 'cinema'], [1, 2, 3]];
        yield 'relation multiple, other case' => [$relationMultiple, ['MUSIC', 'cinema'], [3]];
        yield 'choice on the identifier' => [$choice, 'cinema', [3]];
        yield 'choice on the identifier, other case' => [$choice, 'Cinema', []];
        yield 'choice multiple on the identifier' => [$choiceMultiple, ['music', 'cinema'], [1, 2, 3]];
        yield 'choice multiple on the identifier, other case' => [$choiceMultiple, ['MUSIC', 'cinema'], [3]];
    }

    /**
     * @param array<string, mixed> $spec
     * @param string|list<string>  $value
     * @param list<int>            $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('filters')]
    public function testAStringForeignKeyIsComparedExactly(array $spec, string|array $value, array $expected): void
    {
        $spec += ['attributes' => ['category']];
        $probe = self::execute(self::list(['f' => $spec]), ['filters' => ['f' => $value]]);

        self::assertSame($expected, $probe->ids(), $probe->dql());
        self::assertStringNotContainsString('JOIN', $probe->dql());
    }

    public function testNullAndEmptyOnTheAssociation(): void
    {
        $null = self::list(['f' => ['type' => 'null', 'attributes' => ['category']]]);
        $empty = self::list(['f' => ['type' => 'text', 'attributes' => ['category'], 'options' => ['operators' => ['is_empty', 'is_not_empty']]]]);

        self::assertSame([4, 5, 6], self::execute($null, ['filters' => ['f' => '1']])->ids());
        self::assertSame([4, 5, 6], self::execute($empty, ['filters' => ['f' => ['operator' => 'is_empty']]])->ids());
        self::assertSame([1, 2, 3], self::execute($empty, ['filters' => ['f' => ['operator' => 'is_not_empty']]])->ids());
    }
}
