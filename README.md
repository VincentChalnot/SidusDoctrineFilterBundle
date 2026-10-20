# Sidus/DoctrineFilterBundle

The Doctrine ORM provider (`provider: doctrine`) of [`sidus/filter-bundle`](https://github.com/VincentChalnot/SidusFilterBundle)
7.0: every criterion of a list is compiled to deterministic DQL, with the same meaning as on the other providers.

PHP 8.4+, Symfony 8.1+, Doctrine ORM 3.3+ / DBAL 4, DoctrineBundle 3. PostgreSQL, SQLite and MariaDB / MySQL.

- **No row multiplication.** To-one paths share one `LEFT JOIN` per prefix with deterministic aliases (`author_`,
  `author_company_`); every to-many criterion is its own correlated `EXISTS`, so two criteria on a collection are
  independent ("some tag is `php` and some tag is `symfony`"), pages are a plain `LIMIT/OFFSET` and the total is a
  plain `COUNT`.
- **Deterministic queries.** Aliases come from the paths, parameters are `p1..pn` in definition order, no `uniqid()`:
  the same input shape is the same DQL, so Doctrine's query cache hits.
- **Strict.** What the platform or the field's type cannot do with the reference meaning is refused, with a reason, at
  `cache:warmup` and in `lint:sidus-filter`: never approximated, never an exception on a visitor's request.
- **Worker-mode safe.** The container holds only immutable objects (the factory, the compilers, memoised field
  metadata); the query, its joins and its parameters live in a per-request context.
- **Platform-exact.** String equality, `LIKE`, sorting and regexps behave the same on PostgreSQL, SQLite and MariaDB
  (whose default collations are case- and accent-insensitive), proved by the core's shared contract suite.

## Installation

```bash
composer require sidus/doctrine-filter-bundle
```

Register the bundles (after `DoctrineBundle` and `SidusFilterBundle`):

```php
// config/bundles.php
Sidus\FilterBundle\SidusFilterBundle::class => ['all' => true],
Sidus\DoctrineFilterBundle\SidusDoctrineFilterBundle::class => ['all' => true],
```

Optional: `martin-georgiev/postgresql-for-doctrine` (JSON paths on PostgreSQL), `symfony/form` +
`symfony/doctrine-bridge` (form field of the `relation` filter), `symfony/console` (`lint:sidus-filter`).

## Quick start

```yaml
# config/packages/sidus_filter.yaml
sidus_filter:
    lists:
        posts:
            provider: doctrine
            options: { entity: App\Entity\Post }
            pagination: { per_page: 15, max_per_page: 100 }
            filters:
                title:  { type: text, options: { operators: [contains, starts_with, equals] } }
                author: { type: text, attributes: [author.name, author.company.name] }
                tag:    { type: exact, attributes: [tags.label] }
                forum:  { type: relation, attributes: [topic.forum], options: { class: App\Entity\Forum } }
                status: { type: choice, options: { choices: [draft, published] }, default: published, locked: true }
                sort:
                    type: sort
                    options:
                        columns: { title: title, author: author.name, comments: { field: comments, provider_options: { doctrine: { aggregate: count } } } }
                        default: [{ column: title, direction: asc }]
```

```php
$result = $registry->createQueryHandler('posts')            // a new, single-use handler
    ->withCustomizer(static function (DoctrineQueryContext $q): void {
        $author = $q->joins()->aliasFor('author');          // the shared LEFT JOIN
        $q->queryBuilder()->addSelect($author.'.name AS HIDDEN authorName');
    })
    ->handle(ListInput::fromArray($request->query->all()));
```

```bash
bin/console lint:sidus-filter        # every attribute path, criterion and sort column against the metadata
```

## Documentation

- [docs/provider.md](docs/provider.md): options, field resolution, join strategy, every criterion's DQL, sorting, count,
  customizers, named joins, JSON paths, the `relation` filter, computed sorts, platforms and their differences.
- [docs/testing.md](docs/testing.md): the test suites, running them on PostgreSQL and MariaDB.
- [CHANGELOG.md](CHANGELOG.md)

## Tests

```bash
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v $PWD/..:/bundles -w /bundles/SidusDoctrineFilterBundle \
    --entrypoint php <image with pdo_sqlite> vendor/bin/phpunit
```

Until `sidus/filter-bundle` 7.0 is tagged, `composer.json` consumes it from the sibling directory
`../SidusFilterBundle` through a Composer path repository: drop the `repositories` entry once it is.
