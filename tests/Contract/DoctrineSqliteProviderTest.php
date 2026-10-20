<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Contract;

/**
 * The contract on SQLite.
 */
final class DoctrineSqliteProviderTest extends AbstractDoctrineContractTestCase
{
    protected static function usePostgres(): bool
    {
        return false;
    }

    protected function knownDivergences(): array
    {
        $lower = 'SQLite LOWER() and LIKE fold ASCII letters only (no ICU), the reference folds Unicode';

        return [
            'contains_unicode_lowercase_fold_accented' => $lower,
            'contains_unicode_lowercase_fold_umlaut' => $lower,
        ];
    }
}
