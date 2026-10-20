<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Json;

use Doctrine\ORM\Configuration;
use MartinGeorgiev\Doctrine\ORM\Query\AST\Functions\Cast;
use MartinGeorgiev\Doctrine\ORM\Query\AST\Functions\JsonGetField;
use MartinGeorgiev\Doctrine\ORM\Query\AST\Functions\JsonGetFieldAsInteger;
use MartinGeorgiev\Doctrine\ORM\Query\AST\Functions\JsonGetFieldAsText;
use Sidus\DoctrineFilterBundle\Platform\Dialect;
use Sidus\DoctrineFilterBundle\Query\JsonPathCompilerInterface;

/**
 * PostgreSQL, through `martin-georgiev/postgresql-for-doctrine`: `->` for the intermediate keys, `->>` for the last
 * one (works on `json` and `jsonb`), `JSON_GET_FIELD_AS_INTEGER` and `CAST(... AS float8 | boolean | timestamptz)`.
 * `->>` yields NULL for a missing key and for JSON null, like the reference.
 *
 * A value that cannot be cast (`"abc"` as integer) is a database error here, where the in-memory reference reads
 * null: keep the data of a cast path clean.
 */
final class PostgreSqlJsonPathCompiler implements JsonPathCompilerInterface
{
    private const array FUNCTIONS = [
        'JSON_GET_FIELD' => JsonGetField::class,
        'JSON_GET_FIELD_AS_TEXT' => JsonGetFieldAsText::class,
        'JSON_GET_FIELD_AS_INTEGER' => JsonGetFieldAsInteger::class,
        'CAST' => Cast::class,
    ];

    public function supports(Dialect $dialect): bool
    {
        return $dialect->isPostgreSql();
    }

    public function unavailable(): ?string
    {
        return class_exists(JsonGetFieldAsText::class)
            ? null
            : 'JSON paths on PostgreSQL need the martin-georgiev/postgresql-for-doctrine package (composer require martin-georgiev/postgresql-for-doctrine).';
    }

    public function castRefusal(string $cast): ?string
    {
        return null;
    }

    public function registerFunctions(Configuration $configuration): void
    {
        foreach (self::FUNCTIONS as $name => $class) {
            if (null === $configuration->getCustomStringFunction($name)) {
                $configuration->addCustomStringFunction($name, $class);
            }
        }
    }

    public function compile(string $column, array $path, ?string $cast): string
    {
        $last = array_pop($path);
        $document = $column;
        foreach ($path as $segment) {
            $document = 'JSON_GET_FIELD('.$document.', '.self::key($segment).')';
        }
        $text = 'JSON_GET_FIELD_AS_TEXT('.$document.', '.self::key($last).')';

        return match ($cast) {
            null, 'string' => $text,
            'integer' => 'JSON_GET_FIELD_AS_INTEGER('.$document.', '.self::key($last).')',
            'float' => 'CAST('.$text.' AS float8)',
            'boolean' => 'CAST('.$text.' AS boolean)',
            'date' => 'CAST('.$text.' AS timestamptz)',
            default => throw new \LogicException(\sprintf('Unknown JSON cast "%s".', $cast)),
        };
    }

    /** An array index (all digits) or a quoted object key. */
    private static function key(string $segment): string
    {
        return 1 === preg_match('/^[0-9]+$/', $segment) ? $segment : "'".str_replace("'", "''", $segment)."'";
    }
}
