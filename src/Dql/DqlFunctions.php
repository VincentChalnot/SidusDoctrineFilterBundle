<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Dql;

use Doctrine\ORM\Configuration;

/**
 * The DQL functions the provider's compilers emit. Registered on the entity manager configuration when a query
 * handler is created (idempotent), so the application has nothing to declare.
 */
final class DqlFunctions
{
    public const string REGEXP = 'SIDUS_REGEXP';
    public const string IREGEXP = 'SIDUS_IREGEXP';
    public const string ILIKE = 'SIDUS_ILIKE';
    public const string BINARY = 'SIDUS_BINARY';

    public static function register(Configuration $configuration): void
    {
        foreach ([self::REGEXP => RegexpFunction::class, self::IREGEXP => IregexpFunction::class, self::ILIKE => IlikeFunction::class] as $name => $class) {
            if (null === $configuration->getCustomNumericFunction($name)) {
                $configuration->addCustomNumericFunction($name, $class);
            }
        }
        if (null === $configuration->getCustomStringFunction(self::BINARY)) {
            $configuration->addCustomStringFunction(self::BINARY, BinaryFunction::class);
        }
    }
}
