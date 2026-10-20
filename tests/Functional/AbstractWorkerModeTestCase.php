<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryHandler;
use Sidus\DoctrineFilterBundle\Tests\App\TestKernel;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Post;
use Sidus\DoctrineFilterBundle\Tests\Support\FixtureLoader;
use Sidus\FilterBundle\Input\ListInput;
use Sidus\FilterBundle\Registry\QueryHandlerRegistry;

/**
 * One booted kernel serving several requests, as FrankenPHP worker mode does: the registry creates a handler per
 * request, the services are reset in between, and nothing may leak from one request to the next. (The v6 bug:
 * filters, sorts and join aliases accumulated in a handler cached by the registry.)
 */
abstract class AbstractWorkerModeTestCase extends TestCase
{
    private static ?TestKernel $kernel = null;

    /** @return 'sqlite'|'pgsql'|'mysql' */
    abstract protected static function platform(): string;

    public static function setUpBeforeClass(): void
    {
        $variable = ['pgsql' => 'SIDUS_FILTER_PG_DSN', 'mysql' => 'SIDUS_FILTER_MYSQL_DSN'][static::platform()] ?? null;
        if (null !== $variable && false === getenv($variable)) {
            return;
        }
        self::$kernel = TestKernel::create(
            ['posts' => [
                'provider' => 'doctrine',
                'options' => ['entity' => Post::class, 'binary_sort' => true],
                'pagination' => ['per_page' => 2, 'max_per_page' => 10],
                'filters' => [
                    'title' => ['type' => 'text'],
                    'author' => ['type' => 'text', 'attributes' => ['author.name']],
                    'tag' => ['type' => 'exact', 'attributes' => ['tags.label']],
                    'status' => ['type' => 'choice', 'options' => ['choices' => ['draft', 'published', 'archived']], 'default' => 'published', 'locked' => true],
                    'sort' => ['type' => 'sort', 'options' => ['columns' => ['id' => 'id', 'title' => 'title', 'author' => 'author.name'], 'default' => [['column' => 'id', 'direction' => 'asc']]]],
                ],
            ]],
            null,
            ['platform' => static::platform()],
        );
        self::$kernel->boot();
        $em = self::$kernel->getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        FixtureLoader::load($em);
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel?->shutdown();
        self::$kernel = null;
    }

    protected function setUp(): void
    {
        if (null === self::$kernel) {
            self::markTestSkipped('The database of the platform "'.static::platform().'" is not configured.');
        }
    }

    private function registry(): QueryHandlerRegistry
    {
        return self::$kernel->getContainer()->get(QueryHandlerRegistry::class);
    }

    /**
     * Simulates one request: a fresh handler from the registry, then the reset of the services a worker does between requests.
     *
     * @param array<string, mixed> $query
     *
     * @return array{ids: list<int>, page: int, total: int|null, dql: string, managed: int}
     */
    private function request(array $query): array
    {
        $handler = $this->registry()->createQueryHandler('posts');
        self::assertInstanceOf(DoctrineQueryHandler::class, $handler);
        $context = null;
        $managedAtStart = null;
        $result = $handler
            ->withCustomizer(static function (DoctrineQueryContext $captured) use (&$context, &$managedAtStart): void {
                $context = $captured;
                $managedAtStart = $captured->entityManager()->getUnitOfWork()->size();
            })
            ->handle(ListInput::fromArray($query));
        $state = [
            'ids' => array_map(static fn (Post $post): int => $post->getId(), $result->items()),
            'page' => $result->page(),
            'total' => $result->total(),
            'dql' => $context->queryBuilder()->getDQL(),
            'managed' => $managedAtStart,
        ];
        self::$kernel->getContainer()->get('services_resetter')->reset();

        return $state;
    }

    public function testConsecutiveRequestsAreIsolated(): void
    {
        // The locked filter keeps drafts and archived posts out: published posts are 1, 3, 4.
        $all = $this->request([]);
        $second = $this->request(['page' => 2]);
        $filtered = $this->request(['filters' => ['title' => 'über', 'tag' => 'nope']]);
        $sorted = $this->request(['sort' => '-title', 'filters' => ['author' => 'ali']]);
        $again = $this->request([]);

        self::assertSame([1, 3], $all['ids']);
        self::assertSame(3, $all['total']);
        self::assertSame([4], $second['ids']);
        self::assertSame(2, $second['page']);
        self::assertSame([], $filtered['ids'], 'the filters of this request only');
        self::assertSame(0, $filtered['total']);
        self::assertSame([3, 1], $sorted['ids'], 'Über cool before Hello World, descending by title');
        self::assertSame(2, $sorted['total']);

        // The request after the filtered and sorted ones is the first one again.
        self::assertSame($all['ids'], $again['ids']);
        self::assertSame($all['total'], $again['total']);
        self::assertSame($all['dql'], $again['dql'], 'identical input, identical DQL: nothing accumulated');
    }

    public function testEveryQueryHasASingleOrderByAndNoLeakedJoin(): void
    {
        $requests = [
            $this->request(['sort' => 'author']),
            $this->request(['sort' => '-title', 'filters' => ['author' => 'a']]),
            $this->request(['sort' => 'author']),
            $this->request([]),
        ];

        foreach ($requests as $request) {
            self::assertSame(1, substr_count($request['dql'], 'ORDER BY'), $request['dql']);
        }
        self::assertSame(1, substr_count($requests[0]['dql'], 'LEFT JOIN root.author author_'));
        self::assertSame(1, substr_count($requests[1]['dql'], 'LEFT JOIN root.author author_'));
        self::assertSame($requests[0]['dql'], $requests[2]['dql']);
        self::assertStringNotContainsString('JOIN', $requests[3]['dql'], 'the join of the previous requests did not stay');
        self::assertStringNotContainsString('author_', $requests[3]['dql']);
    }

    public function testThreeRequestsWithDifferentInputsGiveIsolatedResultsAndStableDql(): void
    {
        $inputs = [
            ['filters' => ['title' => 'hello'], 'sort' => '-id'],
            ['filters' => ['tag' => 'symfony'], 'sort' => 'title'],
            ['filters' => ['author' => 'alice', 'title' => 'cool']],
        ];

        $first = array_map($this->request(...), $inputs);
        $second = array_map($this->request(...), $inputs);

        self::assertSame([[1], [1, 4], [3]], array_column($first, 'ids'));
        self::assertSame(array_column($first, 'ids'), array_column($second, 'ids'));
        self::assertSame(array_column($first, 'dql'), array_column($second, 'dql'));
        self::assertCount(3, array_unique(array_column($first, 'dql')), 'three different shapes');
    }

    public function testNoEntityIsCarriedOverFromARequestToTheNext(): void
    {
        $first = $this->request(['filters' => ['author' => 'a']]);
        $second = $this->request(['filters' => ['author' => 'a']]);

        self::assertSame(0, $second['managed'], 'the identity map was reset with the services');
        self::assertSame($first['ids'], $second['ids'], 'and the memoised field resolution (which holds no entity manager) still works');
    }

    public function testTwoHandlersOfTheSameListInOneRequestShareNoState(): void
    {
        $registry = $this->registry();
        $filtered = $registry->createQueryHandler('posts');
        $plain = $registry->createQueryHandler('posts');

        $one = $filtered->handle(ListInput::fromArray(['filters' => ['title' => 'zoë'], 'per_page' => 1]));
        $two = $plain->handle(new ListInput());

        self::assertNotSame($filtered, $plain);
        self::assertSame([4], array_map(static fn (Post $post): int => $post->getId(), $one->items()));
        self::assertSame(1, $one->perPage());
        self::assertSame([1, 3], array_map(static fn (Post $post): int => $post->getId(), $two->items()));
        self::assertSame(2, $two->perPage());
        self::assertSame(3, $two->total());
    }
}
