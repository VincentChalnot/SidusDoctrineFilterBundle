<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Metadata;

use PHPUnit\Framework\Attributes\DataProvider;
use Sidus\DoctrineFilterBundle\Metadata\FieldCapabilities;
use Sidus\DoctrineFilterBundle\Metadata\FieldResolver;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Post;
use Sidus\DoctrineFilterBundle\Tests\Support\DoctrineTestCase;
use Sidus\FilterBundle\Criterion\Contains;
use Sidus\FilterBundle\Criterion\Equals;
use Sidus\FilterBundle\Criterion\In;
use Sidus\FilterBundle\Criterion\IsEmpty;
use Sidus\FilterBundle\Criterion\IsNull;
use Sidus\FilterBundle\Criterion\Matches;
use Sidus\FilterBundle\Criterion\Range;

final class FieldCapabilitiesTest extends DoctrineTestCase
{
    /**
     * @return iterable<string, array{0: class-string, 1: string, 2: string|null}>
     */
    public static function refusals(): iterable
    {
        yield 'text on a string' => [Contains::class, 'title', null];
        yield 'text on a number' => [Contains::class, 'views', 'text criteria need a string field, "views" is of type "integer"'];
        yield 'text on a date' => [Matches::class, 'publishedAt', 'text criteria need a string field'];
        yield 'text through to-one' => [Contains::class, 'author.name', null];
        yield 'range on a number' => [Range::class, 'views', null];
        yield 'range on a date' => [Range::class, 'publishedAt', null];
        yield 'range on a string' => [Range::class, 'title', 'a range needs a number or date field, "title" is of type "string"'];
        yield 'equals on json' => [Equals::class, 'meta', 'the type "json" cannot be compared for equality'];
        yield 'in on json' => [In::class, 'meta', 'cannot be compared for equality'];
        yield 'is null on json' => [IsNull::class, 'meta', 'cannot be tested for emptiness'];
        yield 'equals on an association' => [Equals::class, 'author', '"author" ends on an association: compare one of its fields (e.g. "author.id")'];
        yield 'is null on a to-one' => [IsNull::class, 'author', null];
        yield 'is null on a collection' => [IsNull::class, 'tags', '"tags" is a collection: it is never null, use is_empty'];
        yield 'is empty on a collection' => [IsEmpty::class, 'tags', null];
        yield 'is empty on a to-one' => [IsEmpty::class, 'author', null];
        yield 'contains on an association' => [Contains::class, 'author', 'ends on an association'];
    }

    #[DataProvider('refusals')]
    public function testRefusals(string $criterion, string $path, ?string $reason): void
    {
        $field = new FieldResolver(self::$kernel->getContainer()->get('doctrine'), Post::class)->resolve($path);

        $refusal = FieldCapabilities::refusal($criterion, $field);

        if (null === $reason) {
            self::assertNull($refusal);
        } else {
            self::assertNotNull($refusal);
            self::assertStringContainsString($reason, $refusal);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null, 2: string|null}>
     */
    public static function sortRefusals(): iterable
    {
        yield 'a scalar' => ['title', null, null];
        yield 'a to-one scalar' => ['author.name', null, null];
        yield 'json' => ['meta', null, 'the type "json" cannot be ordered'];
        yield 'a to-many without aggregate' => ['tags.label', null, 'a sort needs an aggregate'];
        yield 'count of a collection' => ['tags', 'count', null];
        yield 'min of a to-many field' => ['tags.label', 'min', null];
        yield 'min of a collection' => ['tags', 'min', 'min needs a scalar field'];
        yield 'an aggregate on a scalar' => ['title', 'count', 'is not a to-many path'];
        yield 'an unknown aggregate' => ['tags.label', 'sum', 'unknown aggregate "sum"'];
        yield 'an association' => ['author', null, 'ends on an association, sort on one of its fields'];
    }

    #[DataProvider('sortRefusals')]
    public function testSortRefusals(string $path, ?string $aggregate, ?string $reason): void
    {
        $field = new FieldResolver(self::$kernel->getContainer()->get('doctrine'), Post::class)->resolve($path);

        $refusal = FieldCapabilities::sortRefusal($field, $aggregate);

        if (null === $reason) {
            self::assertNull($refusal);
        } else {
            self::assertNotNull($refusal);
            self::assertStringContainsString($reason, $refusal);
        }
    }
}
