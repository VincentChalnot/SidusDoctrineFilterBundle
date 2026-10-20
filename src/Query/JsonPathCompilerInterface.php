<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Query;

use Doctrine\ORM\Configuration;
use Sidus\DoctrineFilterBundle\Platform\Dialect;

/**
 * Compiles `Field` JSON paths (`totals->regression::integer`) to a DQL expression on a platform. Autoconfigured
 * (tag `sidus_doctrine_filter.json_compiler`); the first one that supports the connection's dialect is used. A
 * platform without one (SQLite) refuses JSON paths at warm-up.
 */
interface JsonPathCompilerInterface
{
    public function supports(Dialect $dialect): bool;

    /**
     * Why this compiler cannot be used although it supports the dialect (a missing library), null when usable.
     */
    public function unavailable(): ?string;

    /**
     * Why a cast cannot be realised with the reference meaning on this platform, null when it can.
     */
    public function castRefusal(string $cast): ?string;

    /**
     * Registers the DQL functions the compiled expressions use (idempotent).
     */
    public function registerFunctions(Configuration $configuration): void;

    /**
     * The DQL expression of the value at `$path` inside the JSON `$column` (a DQL path such as `root.meta`), as
     * text or cast to `$cast` (`integer`, `float`, `string`, `boolean`, `date`). NULL when missing or JSON null.
     *
     * @param list<string> $path all-digit segments are array indexes
     */
    public function compile(string $column, array $path, ?string $cast): string;
}
