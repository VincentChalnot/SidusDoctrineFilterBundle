<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Factory\DoctrineQueryHandlerFactory;
use Sidus\DoctrineFilterBundle\Metadata\FieldCapabilities;
use Sidus\DoctrineFilterBundle\Metadata\ResolvedField;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\FilterBundle\Criterion\FieldCriterion;

abstract class AbstractDoctrineCompiler implements DoctrineCriterionCompilerInterface
{
    public static function getProvider(): string
    {
        return DoctrineQueryHandlerFactory::PROVIDER;
    }

    /**
     * Resolves the field of the criterion and refuses what the provider cannot realise with the reference meaning
     * (the cache warmer reports the same refusals at deploy time, this is the last guard).
     */
    protected function resolve(FieldCriterion $criterion, DoctrineQueryContext $context): ResolvedField
    {
        $field = $context->mapping->fields->resolve($criterion->field);
        if (null !== ($reason = FieldCapabilities::refusal($criterion::class, $field))) {
            throw new \LogicException(\sprintf('The doctrine provider cannot compile %s on "%s": %s.', $criterion::class, $criterion->field, $reason));
        }

        return $field;
    }
}
