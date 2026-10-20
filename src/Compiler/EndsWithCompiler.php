<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\FilterBundle\Criterion\EndsWith;

final class EndsWithCompiler extends AbstractTextCompiler
{
    public static function getCriterionClass(): string
    {
        return EndsWith::class;
    }

    protected function pattern(string $escaped): string
    {
        return '%'.$escaped;
    }

    protected function exact(DoctrineQueryContext $context, string $column, string $text): string
    {
        $placeholder = $context->parameter($text);

        return 'SUBSTRING('.$column.', LENGTH('.$column.') - LENGTH('.$placeholder.') + 1) = '.$placeholder;
    }
}
