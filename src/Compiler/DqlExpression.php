<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

/**
 * A compiled DQL condition. `$nullable` tells whether SQL three-valued logic can make it NULL (a comparison with a
 * NULL column): such a condition is wrapped by `Not` so that "null never matches, hence Not(...) matches null", as in
 * the reference.
 */
final readonly class DqlExpression
{
    private function __construct(public string $dql, public bool $nullable)
    {
    }

    public static function safe(string $dql): self
    {
        return new self($dql, false);
    }

    public static function nullable(string $dql): self
    {
        return new self($dql, true);
    }

    public static function never(): self
    {
        return new self('1 = 0', false);
    }
}
