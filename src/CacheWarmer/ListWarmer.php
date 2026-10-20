<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\CacheWarmer;

use Sidus\DoctrineFilterBundle\Factory\DoctrineQueryHandlerFactory;
use Sidus\DoctrineFilterBundle\Validation\ListValidator;
use Sidus\FilterBundle\Exception\ConfigurationException;
use Sidus\FilterBundle\Registry\ListDefinitionRegistry;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Fails `cache:warmup` (so deploys and CI) when a `doctrine` list does not match the Doctrine metadata: an unknown
 * attribute path, a criterion the field's type cannot honour, a to-many sort without aggregate. Not optional: these
 * would otherwise be exceptions on the first request that uses them.
 */
final readonly class ListWarmer implements CacheWarmerInterface
{
    public function __construct(
        private ListDefinitionRegistry $definitions,
        private ListValidator $validator,
    ) {
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $errors = [];
        foreach ($this->definitions->codes() as $code) {
            $list = $this->definitions->get($code);
            if (DoctrineQueryHandlerFactory::PROVIDER === $list->provider) {
                $errors = [...$errors, ...$this->validator->validate($list)];
            }
        }
        if ([] !== $errors) {
            throw new ConfigurationException(\sprintf("The doctrine lists do not match the Doctrine metadata:\n - %s", implode("\n - ", $errors)));
        }

        return [];
    }
}
