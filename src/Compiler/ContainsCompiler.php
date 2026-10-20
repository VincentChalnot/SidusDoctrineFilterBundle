<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\FilterBundle\Criterion\Contains;

final class ContainsCompiler extends AbstractTextCompiler
{
    public static function getCriterionClass(): string
    {
        return Contains::class;
    }

    protected function pattern(string $escaped): string
    {
        return '%'.$escaped.'%';
    }

    protected function exact(DoctrineQueryContext $context, string $column, string $text): string
    {
        return 'LOCATE('.$context->parameter($text).', '.$column.') > 0';
    }
}
