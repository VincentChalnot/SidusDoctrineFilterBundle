<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Query;

/**
 * What a connection offers for JSON paths: a compiler, or the reason there is none.
 */
final readonly class JsonSupport
{
    public function __construct(public ?JsonPathCompilerInterface $compiler, public ?string $refusal = null)
    {
    }

    public static function none(string $reason): self
    {
        return new self(null, $reason);
    }

    /** Why the cast cannot be compiled, null when it can (or when there is nothing to compile with: see $refusal). */
    public function castRefusal(?string $cast): ?string
    {
        return null === $cast ? null : $this->compiler?->castRefusal($cast);
    }
}
