# Changelog

## Unreleased (1.0.0)

* Doctrine ORM provider `doctrine` for `sidus/filter-bundle` 7.0.
* Field paths resolved from the Doctrine metadata once per list (immutable `ResolvedField`, embeddables included).
* Shared `LEFT JOIN`s for to-one paths with deterministic aliases (`author_`, `author_company_`), one correlated
  `EXISTS` per to-many criterion, parameters `p1..pn`, no `uniqid()`.
* Compilers for `Equals`, `In`, `Contains`, `StartsWith`, `EndsWith`, `Range`, `IsNull`, `IsEmpty`, `Matches`, `Not`,
  `AnyOf`, `AllOf`, `Native` and `Sort`; strict capability refusals.
* Sort with identifier tie-breaker, portable null ordering and to-many aggregates (`count`, `min`, `max`).
* Lazy `COUNT` on a clone without `ORDER BY`; `withCustomizer()` per handler.
* Cache warmer and `lint:sidus-filter` validating every attribute path, criterion and sort column of every
  doctrine list against the Doctrine metadata.
* PostgreSQL, SQLite, MariaDB and MySQL dialects; the contract suite runs on SQLite, and on PostgreSQL and
  MariaDB 12.3 when `SIDUS_FILTER_PG_DSN` / `SIDUS_FILTER_MYSQL_DSN` are set.
* Named joins (`options.joins`: `{entity, on: {field, equals}, cardinality: one|many, shared}`) for entities
  without an association; opt-in shared collections (`provider_options.doctrine.join: shared`) with a DISTINCT mode.
* JSON paths (`Field` json path and cast) through `JsonPathCompilerInterface`: PostgreSQL (martin-georgiev),
  MariaDB / MySQL (`SIDUS_JSON`); refused on SQLite.
* Test suites: DQL determinism (same input, same DQL, one cached parse), worker mode through the registry, and the
  provider contract on PostgreSQL and MariaDB.
* `relation` filter type (`Equals` / `In` on the identifier of an association, foreign key without join for to-one
  paths) and its `EntityType` form mapper; validated at warm-up.
* Computed sorts (`provider_options.doctrine.expression`, several keys with pinned directions); named joins may be
  chained through shared joins (cycles refused); aggregate sorts and expressions order nulls first / last on PostgreSQL.
* README, documentation and GitHub Actions CI (PHP 8.4 / 8.5, lowest / highest, PostgreSQL 17 and MariaDB 12.3 services).
* Requires the core criteria carrying the filter's provider options (two filters on one field may differ) and mappers receiving the list: the `relation` form field derives its class from the association.
* The list cache warmer resets the entity managers it validated against once done: `cache:clear` in a non-debug kernel no longer fails with `DoctrineMetadataCacheWarmer must load metadata first` (the non-optional warmers run during the kernel boot, then DoctrineBundle's optional metadata warmer runs on the same container). The warmer stays non-optional and misconfigured lists still fail the warm-up.
