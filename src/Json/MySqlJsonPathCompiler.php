<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Json;

use Doctrine\ORM\Configuration;
use Sidus\DoctrineFilterBundle\Dql\DqlFunctions;
use Sidus\DoctrineFilterBundle\Dql\JsonValueFunction;
use Sidus\DoctrineFilterBundle\Platform\Dialect;
use Sidus\DoctrineFilterBundle\Query\JsonPathCompilerInterface;

/**
 * MariaDB and MySQL, through the `SIDUS_JSON(column, '$.a.b', 'cast')` DQL function of the bundle: MariaDB
 * `JSON_VALUE`, MySQL `JSON_UNQUOTE(JSON_EXTRACT(...))`. Missing key and JSON null read as NULL.
 *
 * `date` casts are refused: `CAST(... AS DATETIME)` drops the UTC offset of an ISO 8601 string, which would make
 * instants compare wrongly. As on PostgreSQL, a value that cannot be cast reads as 0 with a warning (strict mode:
 * an error) where the reference reads null.
 */
final class MySqlJsonPathCompiler implements JsonPathCompilerInterface
{
    public function supports(Dialect $dialect): bool
    {
        return $dialect->isMySqlFamily();
    }

    public function unavailable(): ?string
    {
        return null;
    }

    public function castRefusal(string $cast): ?string
    {
        return 'date' === $cast ? 'a "date" cast of a JSON value is not available on MariaDB / MySQL (the UTC offset of the ISO 8601 string would be dropped); use a cast to string and compare formatted values, or a date column' : null;
    }

    public function registerFunctions(Configuration $configuration): void
    {
        if (null === $configuration->getCustomStringFunction(DqlFunctions::JSON)) {
            $configuration->addCustomStringFunction(DqlFunctions::JSON, JsonValueFunction::class);
        }
    }

    public function compile(string $column, array $path, ?string $cast): string
    {
        $jsonPath = '$';
        foreach ($path as $segment) {
            $jsonPath .= 1 === preg_match('/^[0-9]+$/', $segment) ? '['.$segment.']' : '."'.addcslashes($segment, '"\\').'"';
        }

        return DqlFunctions::JSON.'('.$column.", '".str_replace("'", "''", $jsonPath)."', '".($cast ?? 'string')."')";
    }
}
