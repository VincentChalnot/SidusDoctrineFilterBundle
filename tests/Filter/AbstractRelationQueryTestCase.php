<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Filter;

use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Tag;
use Sidus\DoctrineFilterBundle\Tests\Support\DoctrineTestCase;

/**
 * `relation` filters through the database, on every platform.
 */
abstract class AbstractRelationQueryTestCase extends DoctrineTestCase
{
    public function testAToOneRelationComparesTheForeignKeyWithoutJoining(): void
    {
        $list = self::list(['author' => ['type' => 'relation', 'options' => ['multiple' => true]]]);

        $probe = self::execute($list, ['filters' => ['author' => ['2', '3']]]);

        self::assertSame([2, 5, 6], $probe->ids());
        self::assertStringContainsString('root.author IN (:p1)', $probe->dql());
        self::assertStringNotContainsString('JOIN', $probe->dql());
        self::assertSame([2, 3], $probe->parameters()['p1']);
    }

    public function testASingleIdentifierIsAnEquality(): void
    {
        $probe = self::execute(self::list(['author' => ['type' => 'relation']]), ['filters' => ['author' => '1']]);

        self::assertSame([1, 3], $probe->ids());
        self::assertStringContainsString('root.author = :p1', $probe->dql());
    }

    public function testNestedToOneRelationJoinsThePrefixOnly(): void
    {
        $list = self::list(['company' => ['type' => 'relation', 'attributes' => ['author.company']]]);

        $probe = self::execute($list, ['filters' => ['company' => '2']]);

        self::assertSame([5], $probe->ids(), 'Émile works for Globex (2)');
        self::assertStringContainsString('author_.company = :p1', $probe->dql());
        self::assertSame(1, substr_count($probe->dql(), 'JOIN'));
    }

    public function testAToManyRelationIsAnExistsOnTheIdentifier(): void
    {
        $list = self::list(['tags' => ['type' => 'relation', 'options' => ['multiple' => true]]]);

        $probe = self::execute($list, ['filters' => ['tags' => ['1', '6']]]);

        self::assertSame([1, 5], $probe->ids(), 'tag 1 is on post 1, tag 6 on post 5');
        self::assertStringContainsString('EXISTS (SELECT 1 FROM '.Tag::class.' x1_tags WHERE x1_tags.post = root AND x1_tags.id IN (:p1))', $probe->dql());
    }
}
