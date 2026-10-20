<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Dql\DqlFunctions;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\DoctrineFilterBundle\Query\FieldAccess;
use Sidus\FilterBundle\Criterion\Criterion;
use Sidus\FilterBundle\Criterion\Matches;

/**
 * Unanchored POSIX ERE match through the `SIDUS_REGEXP` / `SIDUS_IREGEXP` DQL functions: PostgreSQL `~` / `~*`,
 * SQLite `REGEXP` (needs the user function, see {@see \Sidus\DoctrineFilterBundle\Platform\SqliteRegexp}).
 * Null never matches.
 */
final class MatchesCompiler extends AbstractDoctrineCompiler
{
    public static function getCriterionClass(): string
    {
        return Matches::class;
    }

    public function compile(Criterion $criterion, DoctrineCompiler $root, DoctrineQueryContext $context): DqlExpression
    {
        \assert($criterion instanceof Matches);
        $field = $this->resolve($criterion, $context);
        $function = $criterion->caseSensitive ? DqlFunctions::REGEXP : DqlFunctions::IREGEXP;

        return FieldAccess::matching($context, $field, static fn (string $column): DqlExpression => DqlExpression::safe(
            $function.'('.$column.', '.$context->parameter($criterion->pattern).') = 1',
        ));
    }
}
