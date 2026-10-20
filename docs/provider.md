# The `doctrine` provider

`provider: doctrine` realises every criterion of `sidus/filter-bundle` as DQL on a Doctrine ORM entity, with the
reference meaning of the in-memory provider. Strict mode: what it cannot realise exactly is refused with a reason,
never approximated.

```yaml
sidus_filter:
    lists:
        posts:
            provider: doctrine
            options: { entity: App\Entity\Post }
            filters:
                title:  { type: text, operators: [contains, starts_with, equals] }
                author: { type: text, attributes: [author.name, author.company.name] }
                tag:    { type: exact, attributes: [tags.label] }
                sort:
                    type: sort
                    options:
                        columns: { title: title, author: author.name, tags: { field: tags, provider_options: { doctrine: { aggregate: count } } } }
                        default: [ { column: title, direction: asc } ]
```

## Options

List `options`:

| Option | Default | Meaning |
|---|---|---|
| `entity` | required | The root entity (must be managed by a Doctrine ORM entity manager). |
| `identifier` | the single identifier of the entity | Final sort tie-breaker and `COUNT` field. Required for a composite identifier. |
| `binary_sort` | `false` | Compare strings byte-wise when sorting, as the reference does (PostgreSQL: `COLLATE "C"`). Off by default: the database collation is usually what users expect and keeps indexes usable. |

Filter `provider_options.doctrine`:

| Option | Default | Meaning |
|---|---|---|
| `case_insensitive` | `lower` | How case-insensitive `contains` / `starts_with` / `ends_with` fold: `lower` (`LOWER(column) LIKE`, the needle is folded by PHP) or `ilike` (PostgreSQL `ILIKE`, can use a trigram index). |

A criterion carries a field, not the filter that emitted it, so two filters of one list that name the same attribute
must declare the same `provider_options.doctrine` (otherwise the handler refuses to be created).

Sort column `provider_options.doctrine`: `aggregate: count|min|max` (see below).

## Field resolution

Every field path is resolved once per list from the Doctrine metadata into an immutable `ResolvedField`: the
associations traversed (typed to-one / to-many) and the terminal mapped field, embeddables included
(`company.address.city`). Unknown segments and scalars in the middle of a path are configuration errors. Paths come
from the list configuration only: no request input is ever resolved as a path.

## Joins: shared to-one, `EXISTS` for to-many

* **To-one prefixes are shared.** One `LEFT JOIN` per distinct prefix, aliased from the path: `author` becomes
  `author_`, `author.company` becomes `author_company_` (numeric suffix on collision). A to-one join never changes the
  cardinality of the result, so sharing is safe, and `LEFT` keeps rows whose relation is null (the sort included).
* **Every to-many criterion is its own correlated `EXISTS` subquery**, aliased `x1_tags`, `x2_tags`, ... in
  compilation order. Two criteria on the same collection are therefore independent ("some tag is `php` and some tag
  is `symfony`"), no row is multiplied, and pages are a plain `LIMIT/OFFSET`:

  ```sql
  WHERE EXISTS (SELECT 1 FROM Tag x1_tags WHERE x1_tags.post = root AND x1_tags.label = :p1)
    AND EXISTS (SELECT 1 FROM Tag x2_tags WHERE x2_tags.post = root AND x2_tags.label = :p2)
  ```

  A collection mapped by a foreign key is correlated through it; any other (many-to-many) is correlated by
  re-selecting the owner: `FROM Post x1_r JOIN x1_r.tags x1_tags WHERE x1_r = root`.
* **Names are deterministic**: aliases come from the path, parameters are `p1`, `p2`, ... in the definition order of
  the active filters. The DQL only varies with *which* filters are active (a finite set of shapes), so Doctrine's
  query cache hits. No `uniqid()` anywhere.

## Criteria

| Criterion | DQL |
|---|---|
| `Equals`, `In` | `col = :p`, `col IN (:p)`; values are typed after the mapped field (numeric strings become numbers, dates are converted to the PHP default timezone, as DBAL stores them). |
| `Contains`, `StartsWith`, `EndsWith` | case-insensitive: `LOWER(col) LIKE :p ESCAPE '!'` with `%`, `_` and `!` escaped (or `ILIKE`); case-sensitive: `LOCATE` / `SUBSTRING`, exact on every platform (SQLite's `LIKE` ignores case). String fields only. |
| `Range` | `col >= :p1 AND col <= :p2` (bounds inclusive or not). Numbers and dates only. |
| `IsNull` | `col IS NULL`; to-one association: its foreign key (no join), or the target identifier for an inverse side. A collection is never null (refused). |
| `IsEmpty` | string: `col IS NULL OR col = ''`; other scalars: `IS NULL`; collection: `NOT EXISTS (...)`. |
| `Matches` | `SIDUS_REGEXP(col, :p) = 1` / `SIDUS_IREGEXP(...)`: PostgreSQL `~` / `~*`, SQLite `REGEXP` (see platforms). |
| `Not`, `AnyOf`, `AllOf` | `NOT`, `OR`, `AND`. `Not` of a condition that SQL can make NULL is `CASE WHEN (...) THEN 0 ELSE 1 END = 1`, so that "null never matches, hence `Not(...)` matches null" holds as in the reference. |
| `Native('doctrine', closure)` | `closure(DoctrineQueryContext): string` returns a DQL condition; bind values with `$context->parameter()`. |

Through a collection, `IsNull` / `IsEmpty` also match "no element at all" (the reference reads it as null / empty).

Field-dependent refusals (text criteria on a number, equality on a JSON column, `IsNull` on a collection, ...) are
`FieldCapabilities::refusal()`; the compilers apply them as a last guard.

## Sorting

One `ORDER BY` per request: the sort columns, then the identifier ascending as final tie-breaker (stable pages).
Without a sort filter the page is still ordered by the identifier.

* **Nulls first ascending, last descending on every platform**, as the reference: a nullable column is preceded by a
  `CASE WHEN col IS NULL THEN ... END` key (DQL has no `NULLS FIRST`). A non-nullable column on the root has none.
* **A to-many column is a configuration error unless an aggregate is declared**:
  `{ field: tags, provider_options: { doctrine: { aggregate: count } } }` (`min` / `max` need a scalar field),
  compiled as a correlated scalar subquery in a hidden select (`... AS HIDDEN sort_1`).

## Count

`count: auto` is one lazy `SELECT COUNT(root.id)` on a clone of the page query without `ORDER BY`, limit or hidden
selects. It becomes `COUNT(DISTINCT root.id)`, and the page is fetched through the ORM paginator, only when the query
can return a root several times, which the provider's own compilers never cause: a customizer that joins a collection
declares it with `$context->requireDistinct()`.

## Customizers

```php
$handler = $registry->createQueryHandler('posts');
$result = $handler
    ->withCustomizer(static function (DoctrineQueryContext $q): void {
        $author = $q->joins()->aliasFor('author');       // the shared LEFT JOIN, created if needed
        $q->queryBuilder()->addSelect($author.'.name AS HIDDEN authorName');
    })
    ->handle($input);
```

`withCustomizer()` returns a new handler; customizers run on the freshly created query before any criterion is
applied. They get the `QueryBuilder`, the root alias and the `JoinRegistry` (an alias that a customizer already
used is never reused for a shared join). The builder is never returned outside a customizer.

## Validation at warm-up: `lint:sidus-filter`

Field-dependent checks need the Doctrine metadata, which is a runtime service, so they cannot run at container
compile time. A non-optional cache warmer (`cache:warmup`, hence deploys and CI, and the first boot of a dev cache)
and the `bin/console lint:sidus-filter [code]` command validate every `doctrine` list and report **all** problems,
each naming the list, the filter and the attribute:

* every filter attribute and sort column resolves (unknown segment, scalar in the middle, embeddable as terminal);
* every criterion a filter can emit is supported on the field's type (text criteria on strings, ranges on numbers
  and dates, no equality on JSON, `IsNull` on a collection, ...);
* every sort column is sortable: no to-many path without an `aggregate`, no unknown `provider_options.doctrine` key;
* the `identifier` option is a scalar field; the entity is managed; filters do not declare conflicting options;
* the platform can do what the lists need: `Matches` needs PostgreSQL or SQLite with the REGEXP function
  registered (probed with `SELECT 'a' REGEXP 'a'`), `ilike` needs PostgreSQL.

Platform checks are skipped when the database cannot be reached, so that `cache:warmup` works in a build without
a database.

## Platforms

PostgreSQL, SQLite and MariaDB are tested by the shared contract suite (`knownDivergences` only for SQLite's
ASCII-only `LOWER()`); MySQL 8 shares the MariaDB code path except `REGEXP`, and is not tested. Other platforms are
refused when a handler is created.

| | PostgreSQL | SQLite | MariaDB / MySQL |
|---|---|---|---|
| string `=`, `IN` | exact | exact | `(BINARY col) = :p`: the default collation (`utf8mb4_uca1400_ai_ci`, `utf8mb4_general_ci`) is case- and accent-insensitive and pads with spaces |
| `contains` etc., case-sensitive | `LOCATE` / `SUBSTRING` | same | same, on `BINARY col` (bytes: `LENGTH` counts bytes on both sides) |
| `contains` etc., case-insensitive | `LOWER(col) LIKE` or `ILIKE` | `LOWER(col) LIKE` (ASCII folding only) | `(BINARY LOWER(col)) LIKE` (Unicode `LOWER`, exact comparison: `ecole` does not match `ÉCOLE`) |
| `Matches` | `~` / `~*` | `REGEXP` user function (below) | MariaDB: PCRE `REGEXP` with `(?-i)` / `(?i)`; MySQL: `REGEXP_LIKE(.., 'c' / 'i')` |
| is empty (string) | `LENGTH(col) = 0` | same | same (`col = ''` would match `' '`) |
| nulls ordering | `CASE WHEN col IS NULL` key | same | none needed: NULL already sorts first ascending, last descending |
| `binary_sort: true` | `COLLATE "C"` | default collation is binary | `(BINARY col)` |

* **SQLite** has no `REGEXP`: register `Sidus\DoctrineFilterBundle\Platform\SqliteRegexp::register(\Pdo\Sqlite $pdo)`
  on the connection (a pattern starting with `(?i)` means case-insensitive). `LOWER()` and `LIKE` fold ASCII letters
  only: `contains` of `école` does not find `ÉCOLE`.
* **PostgreSQL**: `~` is POSIX ERE; `LOWER()` and `ORDER BY` follow the database locale (`binary_sort` for the
  reference's byte-wise order).
* **MariaDB / MySQL**: `ilike` is refused. Sorting by the default collation is usually what users want, `binary_sort`
  gives the byte-wise order of the reference. Declare `serverVersion` in the DBAL connection (`mariadb-12.3.0`) to
  avoid a connection at platform detection.

The provider registers its DQL functions (`SIDUS_REGEXP`, `SIDUS_IREGEXP`, `SIDUS_ILIKE`, `SIDUS_BINARY`) on the
entity manager configuration when a handler is created; there is nothing to declare.

## Running the contract suite on a database

```bash
docker network create sidus-filter-net
docker run -d --name pg --network sidus-filter-net -e POSTGRES_PASSWORD=sidus -e POSTGRES_DB=sidus postgres:16
docker run -d --name maria --network sidus-filter-net -e MARIADB_ROOT_PASSWORD=sidus -e MARIADB_DATABASE=sidus mariadb:12.3
export SIDUS_FILTER_PG_DSN='pdo-pgsql://postgres:sidus@pg:5432/sidus?serverVersion=16.0'
export SIDUS_FILTER_MYSQL_DSN='pdo-mysql://root:sidus@maria:3306/sidus?charset=utf8mb4&serverVersion=mariadb-12.3.0'
vendor/bin/phpunit tests/Contract
```

The tests drop and recreate the fixture tables of the database they are given. Without the variables, the PostgreSQL
and MySQL suites are skipped.
