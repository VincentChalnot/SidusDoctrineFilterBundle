<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Metadata;

use Sidus\DoctrineFilterBundle\Metadata\FieldResolver;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Author;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Company;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Post;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Tag;
use Sidus\DoctrineFilterBundle\Tests\Support\DoctrineTestCase;
use Sidus\FilterBundle\Exception\ConfigurationException;

final class FieldResolverTest extends DoctrineTestCase
{
    private function resolver(): FieldResolver
    {
        return new FieldResolver(self::$kernel->getContainer()->get('doctrine'), Post::class);
    }

    public function testAScalarField(): void
    {
        $field = $this->resolver()->resolve('title');

        self::assertSame([], $field->steps);
        self::assertSame('title', $field->leaf);
        self::assertSame('string', $field->leafType);
        self::assertFalse($field->leafNullable);
        self::assertFalse($field->isToMany());
        self::assertFalse($field->isNullable());
    }

    public function testANullableScalarField(): void
    {
        self::assertTrue($this->resolver()->resolve('views')->leafNullable);
        self::assertSame('datetime_immutable', $this->resolver()->resolve('publishedAt')->leafType);
    }

    public function testANestedToOnePath(): void
    {
        $field = $this->resolver()->resolve('author.company.name');

        self::assertCount(2, $field->steps);
        self::assertSame(['author', Post::class, Author::class, false, null, true, 'id'], $this->describe($field->steps[0]));
        self::assertSame(['company', Author::class, Company::class, false, null, true, 'id'], $this->describe($field->steps[1]));
        self::assertSame('name', $field->leaf);
        self::assertSame(Company::class, $field->leafEntity);
        self::assertNull($field->firstToMany());
        self::assertTrue($field->isNullable(), 'a to-one step may be missing');
    }

    public function testAToManyPath(): void
    {
        $field = $this->resolver()->resolve('tags.label');

        self::assertSame(['tags', Post::class, Tag::class, true, 'post', false, 'id'], $this->describe($field->steps[0]));
        self::assertSame(0, $field->firstToMany());
        self::assertTrue($field->isToMany());
        self::assertFalse($field->endsOnAssociation());
    }

    public function testAPathEndingOnAnAssociation(): void
    {
        $tags = $this->resolver()->resolve('tags');
        $author = $this->resolver()->resolve('author');

        self::assertTrue($tags->endsOnAssociation());
        self::assertTrue($tags->endsOnToMany());
        self::assertNull($tags->leaf);
        self::assertTrue($author->endsOnAssociation());
        self::assertFalse($author->endsOnToMany());
    }

    public function testEmbeddedFieldsAreResolvedThroughTheirPrefix(): void
    {
        $field = $this->resolver()->resolve('author.company.address.city');

        self::assertSame('address.city', $field->leaf);
        self::assertSame('string', $field->leafType);
        self::assertSame(Company::class, $field->leafEntity);
    }

    public function testResolutionIsMemoised(): void
    {
        $resolver = $this->resolver();

        self::assertSame($resolver->resolve('author.name'), $resolver->resolve('author.name'));
        self::assertSame($resolver->resolve('author.name'), $resolver->resolve(new \Sidus\FilterBundle\Definition\Field('author.name')));
    }

    public function testTheIdentifierIsTheSingleIdentifierField(): void
    {
        self::assertSame('id', $this->resolver()->identifier());
    }

    public function testAnUnknownSegmentNamesTheAvailableOnes(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Field path "author.nope": '.Author::class.' has no field or association "nope", available: id, name');

        $this->resolver()->resolve('author.nope');
    }

    public function testAScalarCannotBeTraversed(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"title" of '.Post::class.' is a scalar field, it cannot be traversed');

        $this->resolver()->resolve('title.length');
    }

    public function testAnEmbeddedObjectCannotBeTheTerminalField(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"address" is an embedded object');

        $this->resolver()->resolve('author.company.address');
    }

    public function testANonEntityRootIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('stdClass is not a Doctrine managed entity');

        new FieldResolver(self::$kernel->getContainer()->get('doctrine'), \stdClass::class)->resolve('id');
    }

    /**
     * @return list<mixed>
     */
    private function describe(\Sidus\DoctrineFilterBundle\Metadata\ResolvedStep $step): array
    {
        return [$step->name, $step->source, $step->target, $step->toMany, $step->mappedBy, $step->joinColumnOnRow, $step->targetIdentifier];
    }
}
