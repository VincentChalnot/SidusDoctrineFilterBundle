<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Sidus\DoctrineFilterBundle\Form\RelationMapper;
use Sidus\DoctrineFilterBundle\Tests\App\TestKernel;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Author;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Post;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Tag;
use Sidus\DoctrineFilterBundle\Tests\Support\FixtureLoader;
use Sidus\FilterBundle\Form\ListFormFactory;
use Sidus\FilterBundle\Registry\QueryHandlerRegistry;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

/**
 * The form adapter of the core with the `relation` mapper: an EntityType on the declared class; the submitted ids are
 * the raw input of the filter, defaults become entity references.
 */
final class RelationFormTest extends TestCase
{
    private TestKernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = TestKernel::create(['posts' => [
            'provider' => 'doctrine',
            'options' => ['entity' => Post::class],
            'pagination' => ['per_page' => 10, 'max_per_page' => 10],
            'filters' => [
                'author' => ['type' => 'relation', 'options' => ['class' => Author::class], 'form' => ['label' => 'Written by', 'options' => ['choice_label' => 'name']]],
                'tags' => ['type' => 'relation', 'options' => ['multiple' => true, 'class' => Tag::class], 'form' => ['options' => ['choice_label' => 'label']]],
                'sort' => ['type' => 'sort', 'options' => ['columns' => ['id' => 'id'], 'default' => [['column' => 'id', 'direction' => 'asc']]]],
            ],
        ]]);
        $this->kernel->boot();
        FixtureLoader::load($this->kernel->getContainer()->get('doctrine.orm.entity_manager'));
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    private function container(): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        return $this->kernel->getContainer()->get('test.service_container');
    }

    public function testTheMapperIsRegisteredForTheRelationType(): void
    {
        self::assertSame('relation', $this->container()->get(RelationMapper::class)->getFilterType());
    }

    public function testTheFieldIsAnEntityTypeWithTheChoicesOfTheClass(): void
    {
        $factory = $this->container()->get(ListFormFactory::class);
        $registry = $this->container()->get(QueryHandlerRegistry::class);

        $form = $factory->create($registry->getDefinition('posts'));

        $author = $form->get('filters')->get('author');
        self::assertInstanceOf(EntityType::class, $author->getConfig()->getType()->getInnerType());
        self::assertSame(Author::class, $author->getConfig()->getOption('class'));
        self::assertFalse($author->getConfig()->getOption('multiple'));
        self::assertSame('Written by', $author->getConfig()->getOption('label'));
        self::assertSame(['Alice', 'bob', 'Émile'], array_values(array_map(static fn ($choice): string => $choice->label, $author->createView()->vars['choices'])));
        self::assertTrue($form->get('filters')->get('tags')->getConfig()->getOption('multiple'));
        self::assertCount(8, $form->get('filters')->get('tags')->createView()->vars['choices']);
    }

    public function testTheSubmittedIdsAreTheRawInputAndFilterTheList(): void
    {
        $factory = $this->container()->get(ListFormFactory::class);
        $registry = $this->container()->get(QueryHandlerRegistry::class);
        $definition = $registry->getDefinition('posts');

        $form = $factory->create($definition);
        $input = $factory->submit($form, ['filters' => ['author' => '2', 'tags' => ['3']]]);
        $result = $registry->createQueryHandler('posts')->handle($input);
        $factory->addViolations($form, $result);

        self::assertSame(['2'], [$input->filters['author']]);
        self::assertSame([2], array_map(static fn (Post $post): int => $post->getId(), $result->items()), "bob's posts (2, 6), with tag 3 (post 2)");
        self::assertSame([], $result->violations()->toArray());
    }

    public function testASubmittedIdOfAnotherTypeIsAViolationOnTheField(): void
    {
        $factory = $this->container()->get(ListFormFactory::class);
        $registry = $this->container()->get(QueryHandlerRegistry::class);

        $form = $factory->create($registry->getDefinition('posts'));
        $input = $factory->submit($form, ['filters' => ['author' => ['1', '2']]]);
        $result = $registry->createQueryHandler('posts')->handle($input);
        $factory->addViolations($form, $result);

        self::assertSame(['filters.author'], array_map(static fn (array $v): string => $v['path'], $result->violations()->toArray()));
        self::assertCount(6, $result->items(), 'the invalid filter is inactive');
        self::assertContains('Only one value is allowed.', array_map(static fn ($error): string => $error->getMessage(), iterator_to_array($form->get('filters')->get('author')->getErrors())));
    }

    public function testADefaultBecomesAnEntityReferenceOfTheField(): void
    {
        $kernel = TestKernel::create(['posts' => [
            'provider' => 'doctrine',
            'options' => ['entity' => Post::class],
            'filters' => [
                'author' => ['type' => 'relation', 'default' => '3', 'options' => ['class' => Author::class]],
                'tags' => ['type' => 'relation', 'default' => ['1', '2'], 'options' => ['multiple' => true, 'class' => Tag::class]],
            ],
        ]]);
        $kernel->boot();

        try {
            FixtureLoader::load($kernel->getContainer()->get('doctrine.orm.entity_manager'));
            $factory = $kernel->getContainer()->get('test.service_container')->get(ListFormFactory::class);
            $registry = $kernel->getContainer()->get(QueryHandlerRegistry::class);
            $form = $factory->create($registry->getDefinition('posts'));

            self::assertSame(3, $form->get('filters')->get('author')->getData()->getId());
            self::assertSame([1, 2], array_map(static fn (Tag $tag): int => $tag->getId(), $form->get('filters')->get('tags')->getData()));
            // Nothing submitted: the raw input is the default again, as identifiers.
            $input = $factory->submit($form, []);
            self::assertEquals(['author' => '3', 'tags' => ['1', '2']], $input->filters);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testTheFormFieldNeedsTheClass(): void
    {
        $kernel = TestKernel::create(['posts' => [
            'provider' => 'doctrine',
            'options' => ['entity' => Post::class],
            'filters' => ['author' => ['type' => 'relation']],
        ]]);
        $kernel->boot();

        try {
            $factory = $kernel->getContainer()->get('test.service_container')->get(ListFormFactory::class);
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('Relation filter "author": the form field needs the related entity, set the filter option "class".');

            $factory->create($kernel->getContainer()->get(QueryHandlerRegistry::class)->getDefinition('posts'));
        } finally {
            $kernel->shutdown();
        }
    }
}
