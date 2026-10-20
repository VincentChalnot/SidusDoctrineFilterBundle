<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Factory\DoctrineQueryHandlerFactory;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\FilterBundle\Criterion\Criterion;
use Sidus\FilterBundle\Criterion\Native;

/**
 * The escape hatch: the payload of a `Native('doctrine', ...)` criterion is a `Closure(DoctrineQueryContext): string`
 * returning a DQL condition. It may bind values with `$context->parameter()` and reuse the shared joins.
 */
final class NativeCompiler extends AbstractDoctrineCompiler
{
    public static function getCriterionClass(): string
    {
        return Native::class;
    }

    public function compile(Criterion $criterion, DoctrineCompiler $root, DoctrineQueryContext $context): DqlExpression
    {
        \assert($criterion instanceof Native);
        if (DoctrineQueryHandlerFactory::PROVIDER !== $criterion->provider) {
            throw new \LogicException(\sprintf('A native criterion for provider "%s" cannot be applied by the doctrine provider.', $criterion->provider));
        }
        if (!$criterion->payload instanceof \Closure) {
            throw new \LogicException('The payload of a doctrine native criterion must be a Closure(DoctrineQueryContext): string returning a DQL condition.');
        }
        $dql = ($criterion->payload)($context);
        if (!\is_string($dql) || '' === trim($dql)) {
            throw new \LogicException('The closure of a doctrine native criterion must return a non-empty DQL condition.');
        }

        return DqlExpression::nullable('('.$dql.')');
    }
}
