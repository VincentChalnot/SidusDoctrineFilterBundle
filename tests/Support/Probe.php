<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Support;

use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\FilterBundle\Result\ListResult;

/**
 * What one request produced: the DQL, the parameters, the page and the total.
 */
final readonly class Probe
{
    public function __construct(public DoctrineQueryContext $context, public ListResult $result, public ?int $total = null)
    {
    }

    public function dql(): string
    {
        return $this->context->queryBuilder()->getDQL();
    }

    /**
     * @return array<string, mixed> name => value
     */
    public function parameters(): array
    {
        $parameters = [];
        foreach ($this->context->queryBuilder()->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter->getValue();
        }

        return $parameters;
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        return array_map(static fn (object $post): int => $post->getId(), $this->result->items());
    }
}
