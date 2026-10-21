<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\CacheWarmer;

use Doctrine\Persistence\ManagerRegistry;
use Sidus\DoctrineFilterBundle\Factory\DoctrineQueryHandlerFactory;
use Sidus\DoctrineFilterBundle\Validation\ListValidator;
use Sidus\FilterBundle\Exception\ConfigurationException;
use Sidus\FilterBundle\Registry\ListDefinitionRegistry;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Fails `cache:warmup` (so deploys and CI) when a `doctrine` list does not match the Doctrine metadata: an unknown
 * attribute path, a criterion the field's type cannot honour, a to-many sort without aggregate. Not optional: these
 * would otherwise be exceptions on the first request that uses them.
 *
 * The validation loads the Doctrine metadata, but DoctrineBundle's optional metadata warmer (priority 1000, run last by
 * `cache:clear` on the container this warmer already ran on) refuses to run on an entity manager whose metadata is
 * loaded. So the managers this warmer used are reset once validated: it leaves them as it found them.
 */
final readonly class ListWarmer implements CacheWarmerInterface
{
    public function __construct(
        private ListDefinitionRegistry $definitions,
        private ListValidator $validator,
        private ManagerRegistry $registry,
    ) {
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $errors = [];
        $managers = [];
        try {
            foreach ($this->definitions->codes() as $code) {
                $list = $this->definitions->get($code);
                if (DoctrineQueryHandlerFactory::PROVIDER === $list->provider) {
                    $managers[] = $this->registry->getManagerForClass($list->options['entity'] ?? '');
                    $errors = [...$errors, ...$this->validator->validate($list)];
                }
            }
        } finally {
            $this->resetManagers($managers);
        }
        if ([] !== $errors) {
            throw new ConfigurationException(\sprintf("The doctrine lists do not match the Doctrine metadata:\n - %s", implode("\n - ", $errors)));
        }

        return [];
    }

    /**
     * @param list<object|null> $managers the managers the validation loaded the metadata into
     */
    private function resetManagers(array $managers): void
    {
        foreach (array_keys($this->registry->getManagerNames()) as $name) {
            if (\in_array($this->registry->getManager($name), $managers, true)) {
                $this->registry->resetManager($name);
            }
        }
    }
}
