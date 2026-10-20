<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Json;

final class MysqlJsonPathTest extends AbstractJsonPathTestCase
{
    protected static function platform(): string
    {
        return 'mysql';
    }

    protected static function supportsJson(): bool
    {
        return true;
    }

    protected static function supportsJsonDates(): bool
    {
        return false;
    }

    protected static function expectedScoreFragment(): string
    {
        return "SIDUS_JSON(root.meta, '$.\"stats\".\"score\"', 'integer')";
    }

    public function testTheValidatorRefusesDateCasts(): void
    {
        $validator = self::$kernel->getContainer()->get('test.service_container')->get(\Sidus\DoctrineFilterBundle\Validation\ListValidator::class);
        $errors = $validator->validate(self::list(['d' => ['type' => 'date_range', 'attributes' => [self::json(['stats', 'at'], 'date')]]]));

        self::assertCount(1, $errors);
        self::assertStringContainsString('the JSON path "meta->stats->at::date" cannot be compiled: a "date" cast of a JSON value is not available on MariaDB / MySQL', $errors[0]);
    }

    public function testTheValidatorAcceptsTheOtherCasts(): void
    {
        $validator = self::$kernel->getContainer()->get('test.service_container')->get(\Sidus\DoctrineFilterBundle\Validation\ListValidator::class);

        self::assertSame([], $validator->validate(self::list(['s' => ['type' => 'number', 'attributes' => [self::json(['stats', 'score'], 'integer')]]])));
    }
}
