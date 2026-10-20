<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Dql\DqlFunctions;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\DoctrineFilterBundle\Query\FieldAccess;
use Sidus\FilterBundle\Criterion\Contains;
use Sidus\FilterBundle\Criterion\Criterion;
use Sidus\FilterBundle\Criterion\EndsWith;
use Sidus\FilterBundle\Criterion\StartsWith;

/**
 * Contains, StartsWith, EndsWith: the text is literal (`%`, `_` and the escape character are escaped).
 *
 *  - case-sensitive: no LIKE (SQLite's LIKE ignores case): `LOCATE` / `SUBSTRING`, exact on every platform;
 *  - case-insensitive, `provider_options.doctrine.case_insensitive: lower` (default): `LOWER(column) LIKE` with the
 *    needle folded to lower case by PHP (Unicode), so the column side folds as the database does;
 *  - `ilike` (PostgreSQL): `ILIKE`, which can use a trigram index.
 */
abstract class AbstractTextCompiler extends AbstractDoctrineCompiler
{
    /** The LIKE pattern for an escaped text. */
    abstract protected function pattern(string $escaped): string;

    /** The case-sensitive test. */
    abstract protected function exact(DoctrineQueryContext $context, string $column, string $text): string;

    public function compile(Criterion $criterion, DoctrineCompiler $root, DoctrineQueryContext $context): DqlExpression
    {
        \assert($criterion instanceof Contains || $criterion instanceof StartsWith || $criterion instanceof EndsWith);
        $field = $this->resolve($criterion, $context);

        return FieldAccess::matching($context, $field, function (string $column) use ($criterion, $context): DqlExpression {
            if ($criterion->caseSensitive) {
                return DqlExpression::nullable($this->exact($context, $column, $criterion->text));
            }
            if ('ilike' === $context->fieldOption($criterion->field, 'case_insensitive', 'lower')) {
                $pattern = $context->parameter($this->pattern(self::escape($criterion->text)));

                return DqlExpression::safe(DqlFunctions::ILIKE.'('.$column.', '.$pattern.') = 1');
            }
            $pattern = $context->parameter($this->pattern(self::escape(mb_strtolower($criterion->text))));

            return DqlExpression::nullable('LOWER('.$column.') LIKE '.$pattern." ESCAPE '!'");
        });
    }

    public static function escape(string $text): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);
    }
}
