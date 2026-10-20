# Testing

```bash
composer install
vendor/bin/phpunit                       # SQLite only; the PostgreSQL and MariaDB suites are skipped
```

Everything also runs in Docker (the local PHP may lack `pdo_sqlite`): see the command in the README.

| Suite | What it proves |
|---|---|
| `tests/Contract` | `Sidus\FilterBundle\Test\Contract\AbstractProviderContractTestCase`: ~90 cases defined once in the core (fixture, filters, expected ids, totals, violations) on SQLite, PostgreSQL and MariaDB. `knownDivergences()` lists SQLite's ASCII-only `LOWER()` only; PostgreSQL and MariaDB have none. Plus string-exactness cases (case, accents, trailing spaces). |
| `tests/Query` | DQL shape (one shared `LEFT JOIN` per to-one prefix, one `EXISTS` per to-many criterion, one `ORDER BY`), deterministic names and query-cache hits, count query shape, aggregates, customizers, native criterion, refusals. |
| `tests/Joins`, `tests/Json` | Named joins and shared collections, JSON paths: on every platform, JSON cases are also run on the in-memory reference and must give the same ids. |
| `tests/Functional` | Worker mode (one booted kernel, several requests through `QueryHandlerRegistry` with the services reset in between: isolated results, a single `ORDER BY`, identical DQL for identical input), cache warmer and `lint:sidus-filter`. |
| `tests/Metadata`, `tests/Platform` | Field resolution and capability refusals; the SQLite `REGEXP` function. |

## Databases

`SIDUS_FILTER_PG_DSN` and `SIDUS_FILTER_MYSQL_DSN` (MySQL or MariaDB) enable the database suites. **The tests drop and
recreate the fixture tables of that database.** The MariaDB suite is meant for a default collation
(`utf8mb4_uca1400_ai_ci`, case- and accent-insensitive): that is the case the dialect has to defeat.

```bash
docker network create sidus-filter-net
docker run -d --name pg --network sidus-filter-net -e POSTGRES_PASSWORD=sidus -e POSTGRES_DB=sidus postgres:16
docker run -d --name maria --network sidus-filter-net -e MARIADB_ROOT_PASSWORD=sidus -e MARIADB_DATABASE=sidus mariadb:12.3
SIDUS_FILTER_PG_DSN='pdo-pgsql://postgres:sidus@pg:5432/sidus?serverVersion=16.0' \
SIDUS_FILTER_MYSQL_DSN='pdo-mysql://root:sidus@maria:3306/sidus?charset=utf8mb4&serverVersion=mariadb-12.3.0' \
vendor/bin/phpunit
```
