<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Json;

use Sidus\DoctrineFilterBundle\Tests\Support\DoctrineTestCase;
use Sidus\FilterBundle\Exception\ConfigurationException;

/**
 * SQLite refuses JSON paths, with the reason, at runtime and at warm-up.
 */
final class SqliteJsonPathTest extends AbstractJsonPathTestCase
{
    protected static function platform(): string
    {
        return 'sqlite';
    }

    protected static function supportsJson(): bool
    {
        return false;
    }

    protected static function expectedScoreFragment(): string
    {
        return '';
    }

    public function testTheValidatorReportsIt(): void
    {
        $validator = self::$kernel->getContainer()->get('test.service_container')->get(\Sidus\DoctrineFilterBundle\Validation\ListValidator::class);
        $errors = $validator->validate(self::list(['s' => ['type' => 'number', 'attributes' => [self::json(['stats', 'score'], 'integer')]]]));

        self::assertCount(1, $errors);
        self::assertStringContainsString('the JSON path "meta->stats->score::integer" cannot be compiled: JSON paths are not available on SQLite: no JSON path compiler supports it.', $errors[0]);
    }
}
