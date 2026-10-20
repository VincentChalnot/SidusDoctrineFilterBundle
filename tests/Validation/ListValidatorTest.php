<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Validation;

use Sidus\DoctrineFilterBundle\Tests\Support\DoctrineTestCase;
use Sidus\DoctrineFilterBundle\Validation\ListValidator;
use Sidus\FilterBundle\Definition\ListDefinition;

final class ListValidatorTest extends DoctrineTestCase
{
    private function validator(): ListValidator
    {
        $validator = self::$kernel->getContainer()->get('test.service_container')->get(ListValidator::class);
        \assert($validator instanceof ListValidator);

        return $validator;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private function errors(array $specs, array $options = []): array
    {
        return $this->validator()->validate(self::list($specs, $options));
    }

    public function testTheContractListIsValid(): void
    {
        self::assertSame([], $this->validator()->validate(self::list(array_keys(\Sidus\FilterBundle\Test\Contract\FixtureDataset::filterSpecs()))));
    }

    public function testAnUnknownAttributeIsReportedWithItsFilter(): void
    {
        $errors = $this->errors(['t' => ['type' => 'text', 'attributes' => ['title', 'author.nam']]]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('List "probe", filter "t" (type "text"), attribute "author.nam": Field path "author.nam"', $errors[0]);
        self::assertStringContainsString('has no field or association "nam", available: id, name, company', $errors[0]);
    }

    public function testEveryProblemOfAListIsReportedNotOnlyTheFirst(): void
    {
        $errors = $this->errors([
            'a' => ['type' => 'text', 'attributes' => ['nope']],
            'b' => ['type' => 'text', 'attributes' => ['views']],
            'c' => ['type' => 'null', 'attributes' => ['tags']],
        ]);

        self::assertCount(3, $errors);
        self::assertStringContainsString('filter "a"', $errors[0]);
        self::assertStringContainsString('filter "b" (type "text"), attribute "views": Contains cannot be compiled: text criteria need a string field, "views" is of type "integer"', $errors[1]);
        self::assertStringContainsString('filter "c" (type "null"), attribute "tags": IsNull cannot be compiled: "tags" is a collection', $errors[2]);
    }

    public function testRangeOnAStringIsRefused(): void
    {
        $errors = $this->errors(['n' => ['type' => 'number', 'attributes' => ['title'], 'options' => ['operators' => ['gt']]]]);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('a range needs a number or date field', implode("\n", $errors));
    }

    public function testSortColumnsAreChecked(): void
    {
        $errors = $this->errors(['sort' => ['type' => 'sort', 'options' => ['columns' => [
            'ok' => 'title',
            'unknown' => 'nope',
            'tag' => 'tags.label',
            'count' => ['field' => 'tags', 'provider_options' => ['doctrine' => ['aggregate' => 'count']]],
            'typo' => ['field' => 'tags', 'provider_options' => ['doctrine' => ['agregate' => 'count']]],
            'bad_aggregate' => ['field' => 'tags.label', 'provider_options' => ['doctrine' => ['aggregate' => 'sum']]],
            'meta' => 'meta',
        ]]]]);

        self::assertCount(6, $errors, implode("\n", $errors));
        self::assertStringContainsString('sort column "unknown": Field path "nope"', $errors[0]);
        self::assertStringContainsString('sort column "tag": cannot sort on "tags.label": "tags.label" goes through a to-many association: a sort needs an aggregate', $errors[1]);
        self::assertStringContainsString('sort column "typo": unknown provider_options.doctrine key "agregate" (allowed: aggregate, expression)', $errors[2]);
        self::assertStringContainsString('sort column "typo": cannot sort on "tags": "tags" goes through a to-many association', $errors[3]);
        self::assertStringContainsString('sort column "bad_aggregate": cannot sort on "tags.label": unknown aggregate "sum"', $errors[4]);
        self::assertStringContainsString('sort column "meta": cannot sort on "meta": the type "json" cannot be ordered', $errors[5]);
    }

    public function testTheEntityIdentifierOptionIsChecked(): void
    {
        $errors = $this->errors(['title'], ['identifier' => 'author']);

        self::assertCount(1, $errors);
        self::assertStringContainsString('option identifier: "author" must be a scalar field of', $errors[0]);
    }

    public function testAnUnmanagedEntityIsReported(): void
    {
        $errors = $this->errors(['title'], ['entity' => \stdClass::class]);

        self::assertSame(['List "probe": stdClass is not managed by a Doctrine ORM entity manager.'], $errors);
    }

    public function testConflictingProviderOptionsOnOneFieldAreReported(): void
    {
        $errors = $this->errors([
            'a' => ['type' => 'text', 'attributes' => ['title'], 'provider_options' => ['doctrine' => ['case_insensitive' => 'ilike']]],
            'b' => ['type' => 'text', 'attributes' => ['title'], 'provider_options' => ['doctrine' => ['case_insensitive' => 'lower']]],
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('several filters declare different provider_options.doctrine for the field "title"', $errors[0]);
    }

    public function testILikeIsRefusedOnSqlite(): void
    {
        $errors = $this->errors(['t' => ['type' => 'text', 'attributes' => ['title'], 'provider_options' => ['doctrine' => ['case_insensitive' => 'ilike']]]]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('case_insensitive "ilike" cannot be used: ILIKE is available on PostgreSQL only, the connection is SQLite', $errors[0]);
    }

    public function testRegexpIsAvailableWhenTheConnectionRegistersIt(): void
    {
        self::assertSame([], $this->errors(['r' => ['type' => 'regexp', 'attributes' => ['title']]]));
    }
}
