<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Joins;

use PHPUnit\Framework\Attributes\DataProvider;
use Sidus\DoctrineFilterBundle\Factory\DoctrineQueryHandlerFactory;
use Sidus\DoctrineFilterBundle\Metadata\NamedJoin;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Profile;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Review;
use Sidus\DoctrineFilterBundle\Tests\Support\DoctrineTestCase;
use Sidus\FilterBundle\Exception\ConfigurationException;
use Sidus\FilterBundle\Registry\FilterTypeRegistry;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class NamedJoinConfigTest extends DoctrineTestCase
{
    public function testTheOptionIsNormalisedWithItsDefaults(): void
    {
        $resolver = new OptionsResolver();
        DoctrineQueryHandlerFactory::configureListOptions($resolver);

        $options = $resolver->resolve(['entity' => 'App\Post', 'joins' => ['profile' => ['entity' => Profile::class, 'on' => ['field' => 'authorId', 'equals' => 'author.id']]]]);

        self::assertSame(['profile' => ['entity' => Profile::class, 'on' => ['field' => 'authorId', 'equals' => 'author.id'], 'cardinality' => 'one', 'shared' => false]], $options['joins']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidOptions(): iterable
    {
        $join = ['entity' => Profile::class, 'on' => ['field' => 'authorId', 'equals' => 'author.id']];

        yield 'bad alias' => [['bad alias' => $join], 'Invalid join alias "bad alias"'];
        yield 'not a map' => [['p' => 'x'], 'joins.p must be a map'];
        yield 'unknown key' => [['p' => $join + ['where' => 'x']], 'unknown key(s) where'];
        yield 'no entity' => [['p' => ['on' => $join['on']]], 'joins.p.entity is required'];
        yield 'no on' => [['p' => ['entity' => Profile::class]], 'joins.p.on must be {field'];
        yield 'on without equals' => [['p' => ['entity' => Profile::class, 'on' => ['field' => 'x']]], 'joins.p.on must be {field'];
        yield 'bad cardinality' => [['p' => $join + ['cardinality' => 'some']], 'cardinality must be "one" or "many"'];
        yield 'shared one' => [['p' => $join + ['shared' => true]], 'shared only applies to cardinality "many"'];
        yield 'shared not a bool' => [['p' => $join + ['cardinality' => 'many', 'shared' => 'yes']], 'shared must be a boolean'];
    }

    /**
     * @param array<string, mixed> $joins
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRejected(array $joins, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        NamedJoin::normalize($joins);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidJoins(): iterable
    {
        $review = ['entity' => Review::class, 'on' => ['field' => 'postId', 'equals' => 'id']];

        yield 'alias shadows a field' => [['title' => $review], 'the alias shadows the field or association "title"'];
        yield 'alias shadows an association' => [['tags' => $review], 'the alias shadows the field or association "tags"'];
        yield 'unknown on.field' => [['r' => ['entity' => Review::class, 'on' => ['field' => 'nope', 'equals' => 'id']]], 'has no field or association "nope" (on.field)'];
        yield 'unknown equals' => [['r' => ['entity' => Review::class, 'on' => ['field' => 'postId', 'equals' => 'nope']]], 'on.equals: Field path "nope"'];
        yield 'equals through a collection' => [['r' => ['entity' => Review::class, 'on' => ['field' => 'postId', 'equals' => 'tags.id']]], 'must be a to-one path'];
        yield 'equals through a named join' => [['a' => $review, 'b' => ['entity' => Review::class, 'on' => ['field' => 'postId', 'equals' => 'a.id']]], 'starts with the named join "a"'];
        yield 'unmanaged entity' => [['r' => ['entity' => \stdClass::class, 'on' => ['field' => 'postId', 'equals' => 'id']]], 'stdClass is not a Doctrine managed entity'];
    }

    /**
     * @param array<string, mixed> $joins
     */
    #[DataProvider('invalidJoins')]
    public function testJoinsAreCheckedAgainstTheMetadata(array $joins, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);

        self::factory()->createQueryHandler(self::list(['title'], ['joins' => NamedJoin::normalize($joins)]), FilterTypeRegistry::builtIn(), null);
    }

    public function testTheValidatorReportsAnInvalidJoin(): void
    {
        $validator = self::$kernel->getContainer()->get('test.service_container')->get(\Sidus\DoctrineFilterBundle\Validation\ListValidator::class);

        $errors = $validator->validate(self::list(['title'], ['joins' => NamedJoin::normalize(['title' => ['entity' => Review::class, 'on' => ['field' => 'postId', 'equals' => 'id']]])]));

        self::assertCount(1, $errors);
        self::assertStringContainsString('List "probe": Join "title": the alias shadows', $errors[0]);
    }

    public function testSharedOnAPathWithoutACollectionIsReported(): void
    {
        $validator = self::$kernel->getContainer()->get('test.service_container')->get(\Sidus\DoctrineFilterBundle\Validation\ListValidator::class);

        $errors = $validator->validate(self::list(['t' => ['type' => 'text', 'attributes' => ['title', 'tags.label'], 'provider_options' => ['doctrine' => ['join' => 'shared']]]]));

        self::assertCount(1, $errors);
        self::assertStringContainsString('attribute "title": provider_options.doctrine.join "shared" has no effect on a path without a collection', $errors[0]);
    }
}
