<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Filter;

use Sidus\DoctrineFilterBundle\Filter\RelationFilterType;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Author;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Tag;
use Sidus\DoctrineFilterBundle\Tests\Support\DoctrineTestCase;
use Sidus\DoctrineFilterBundle\Validation\ListValidator;
use Sidus\FilterBundle\Criterion\AnyOf;
use Sidus\FilterBundle\Criterion\Equals;
use Sidus\FilterBundle\Criterion\In;
use Sidus\FilterBundle\Definition\FilterDefinition;
use Sidus\FilterBundle\Registry\FilterTypeRegistry;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class RelationFilterTest extends DoctrineTestCase
{
    private static function filter(array $options = [], array $attributes = ['author']): FilterDefinition
    {
        $resolver = new OptionsResolver();
        RelationFilterType::configureOptions($resolver);

        return new FilterDefinition('rel', 'relation', $attributes, options: $resolver->resolve($options));
    }

    public function testItIsNamedRelationAndEmitsEqualsOnTheIdentifier(): void
    {
        $type = new RelationFilterType();
        $filter = self::filter();

        self::assertSame('relation', RelationFilterType::getName());
        self::assertSame([Equals::class], RelationFilterType::getRequiredOperations($filter));
        $criterion = $type->buildCriterion($filter, $type->normalize($filter, '2')->value);
        self::assertInstanceOf(Equals::class, $criterion);
        self::assertSame('author.id', $criterion->field->path);
        self::assertSame('2', $criterion->value);
    }

    public function testMultipleEmitsInOverEveryAttributeWithACustomIdentifier(): void
    {
        $type = new RelationFilterType();
        $filter = self::filter(['multiple' => true, 'identifier' => 'uuid'], ['author', 'tags']);

        self::assertSame([In::class, AnyOf::class], RelationFilterType::getRequiredOperations($filter));
        $criterion = $type->buildCriterion($filter, $type->normalize($filter, ['a', 'b'])->value);
        self::assertInstanceOf(AnyOf::class, $criterion);
        self::assertSame(['author.uuid', 'tags.uuid'], array_map(static fn (In $in): string => $in->field->path, $criterion->criteria));
    }

    public function testNormalisation(): void
    {
        $type = new RelationFilterType();
        $single = self::filter();
        $multiple = self::filter(['multiple' => true]);

        self::assertTrue($type->normalize($single, '')->isEmpty());
        self::assertTrue($type->normalize($single, [])->isEmpty());
        self::assertSame(5, $type->normalize($single, 5)->value);
        self::assertSame([1, '2'], $type->normalize($multiple, [1, '2'])->value);
        self::assertSame([7], $type->normalize($multiple, 7)->value);

        $list = $type->normalize($single, [1, 2]);
        self::assertFalse($list->isValid());
        self::assertSame('Only one value is allowed.', $list->violations[0]->message);

        $invalid = $type->normalize($multiple, [1, ['x'], true, '']);
        self::assertSame(['1', '2', '3'], array_map(static fn ($violation): string => $violation->path, $invalid->violations));
    }

    public function testOptionsAreValidated(): void
    {
        $this->expectException(\Symfony\Component\OptionsResolver\Exception\InvalidOptionsException::class);

        self::filter(['identifier' => 'not an identifier']);
    }

    public function testTheValidatorChecksTheClassAndTheShape(): void
    {
        $validator = self::$kernel->getContainer()->get('test.service_container')->get(ListValidator::class);
        $errors = static fn (array $specs): array => $validator->validate(self::list($specs));

        self::assertSame([], $errors(['a' => ['type' => 'relation', 'attributes' => ['author'], 'options' => ['class' => Author::class]]]));
        self::assertSame([], $errors(['a' => ['type' => 'relation', 'attributes' => ['tags'], 'options' => ['multiple' => true, 'class' => Tag::class]]]));

        $wrongClass = $errors(['a' => ['type' => 'relation', 'attributes' => ['author'], 'options' => ['class' => Tag::class]]]);
        self::assertCount(1, $wrongClass);
        self::assertStringContainsString('the option class is '.Tag::class.' but the association "author" leads to '.Author::class, $wrongClass[0]);

        $scalar = $errors(['a' => ['type' => 'relation', 'attributes' => ['title']]]);
        self::assertCount(1, $scalar);
        self::assertStringContainsString('Field path "title.id": ', $scalar[0]);

        $unknown = $errors(['a' => ['type' => 'relation', 'attributes' => ['nope']]]);
        self::assertStringContainsString('Field path "nope.id"', $unknown[0]);

        $notAnIdentifier = $errors(['a' => ['type' => 'relation', 'attributes' => ['author'], 'options' => ['identifier' => 'name']]]);
        self::assertCount(1, $notAnIdentifier);
        self::assertStringContainsString('is not one (option identifier: "name")', $notAnIdentifier[0]);
    }

    public function testTheTypeIsRegisteredInTheContainer(): void
    {
        $types = self::$kernel->getContainer()->get('test.service_container')->get(FilterTypeRegistry::class);

        self::assertInstanceOf(RelationFilterType::class, $types->get('relation'));
    }
}
