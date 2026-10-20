<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Validation;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Sidus\DoctrineFilterBundle\Factory\DoctrineQueryHandlerFactory;
use Sidus\DoctrineFilterBundle\Metadata\FieldCapabilities;
use Sidus\DoctrineFilterBundle\Metadata\ListMapping;
use Sidus\DoctrineFilterBundle\Metadata\ResolvedField;
use Sidus\FilterBundle\Criterion\FieldCriterion;
use Sidus\FilterBundle\Criterion\Matches;
use Sidus\FilterBundle\Criterion\Sort;
use Sidus\FilterBundle\Definition\Field;
use Sidus\FilterBundle\Definition\FilterDefinition;
use Sidus\FilterBundle\Definition\ListDefinition;
use Sidus\FilterBundle\Exception\ConfigurationException;
use Sidus\FilterBundle\Registry\FilterTypeRegistry;

/**
 * Validates a `doctrine` list against the Doctrine metadata (the field-dependent half of the capability negotiation,
 * which needs runtime services and therefore cannot run at container compile time): every attribute of every
 * filter, every sort column, the criteria each filter can emit on them, and the platform requirements. Reports all
 * the problems of a list, not the first one. Run by the cache warmer and by `lint:sidus-filter`.
 *
 * Checks that need the database (platform detection, the SQLite REGEXP probe) are skipped when the database cannot
 * be reached, so that `cache:warmup` works in a build without a database.
 */
final class ListValidator
{
    private const array SORT_OPTIONS = ['aggregate'];

    public function __construct(
        private readonly DoctrineQueryHandlerFactory $factory,
        private readonly ManagerRegistry $registry,
        private readonly FilterTypeRegistry $filterTypes,
    ) {
    }

    /**
     * @return list<string> the problems, empty when the list is valid
     */
    public function validate(ListDefinition $list): array
    {
        $manager = $this->registry->getManagerForClass($list->options['entity'] ?? '');
        if (!$manager instanceof EntityManagerInterface) {
            return [\sprintf('List "%s": %s is not managed by a Doctrine ORM entity manager.', $list->code, $list->options['entity'] ?? '(no entity)')];
        }
        try {
            $mapping = $this->factory->mapping($list);
            $errors = $this->identifierErrors($list, $mapping);
        } catch (ConfigurationException $e) {
            return [\sprintf('List "%s": %s', $list->code, $e->getMessage())];
        }

        $platform = new PlatformProbe($manager);
        foreach ($list->filters as $filter) {
            foreach ($this->validateFilter($list, $filter, $mapping, $platform) as $error) {
                $errors[$error] = $error;
            }
        }

        return array_values($errors);
    }

    /**
     * @return array<string, string>
     */
    private function identifierErrors(ListDefinition $list, ListMapping $mapping): array
    {
        try {
            $field = $mapping->fields->resolve($mapping->identifier);
        } catch (ConfigurationException $e) {
            return [($message = \sprintf('List "%s", option identifier: %s', $list->code, $e->getMessage())) => $message];
        }
        if ($field->endsOnAssociation() || $field->isToMany()) {
            return [($message = \sprintf('List "%s", option identifier: "%s" must be a scalar field of %s.', $list->code, $mapping->identifier, $mapping->entity)) => $message];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function validateFilter(ListDefinition $list, FilterDefinition $filter, ListMapping $mapping, PlatformProbe $platform): array
    {
        $where = \sprintf('List "%s", filter "%s" (type "%s")', $list->code, $filter->key, $filter->type);
        $operations = $this->filterTypes->get($filter->type)->getRequiredOperations($filter);
        $errors = [];

        if (\in_array(Sort::class, $operations, true)) {
            foreach ($filter->options['columns'] ?? [] as $key => $column) {
                $errors = [...$errors, ...$this->validateSortColumn($where, (string) $key, $column, $mapping, $platform)];
            }
        }

        // Attributes only matter to filters that emit field criteria (a sort or native filter keeps its key as a dummy attribute).
        $fieldOperations = array_values(array_filter($operations, static fn (string $class): bool => is_subclass_of($class, FieldCriterion::class)));
        foreach ([] === $fieldOperations ? [] : $filter->attributes as $field) {
            $resolved = $this->resolve($where.\sprintf(', attribute "%s"', $field), $field, $mapping, $errors);
            if (null === $resolved) {
                continue;
            }
            $errors = [...$errors, ...$this->validateJson($where.\sprintf(', attribute "%s"', $field), $resolved, $platform)];
            if ('shared' === ($filter->getProviderOptions(DoctrineQueryHandlerFactory::PROVIDER)['join'] ?? null) && !$resolved->isToMany()) {
                $errors[] = \sprintf('%s, attribute "%s": provider_options.doctrine.join "shared" has no effect on a path without a collection.', $where, $field);
            }
            foreach ($fieldOperations as $class) {
                if (null !== ($reason = FieldCapabilities::refusal($class, $resolved))) {
                    $errors[] = \sprintf('%s, attribute "%s": %s cannot be compiled: %s.', $where, $field, substr(strrchr($class, '\\') ?: $class, 1), $reason);
                }
            }
        }

        if (\in_array(Matches::class, $operations, true) && null !== ($reason = $platform->regexpRefusal())) {
            $errors[] = \sprintf('%s: Matches cannot be compiled: %s', $where, $reason);
        }
        if ('ilike' === ($filter->getProviderOptions(DoctrineQueryHandlerFactory::PROVIDER)['case_insensitive'] ?? null) && null !== ($reason = $platform->ilikeRefusal())) {
            $errors[] = \sprintf('%s: case_insensitive "ilike" cannot be used: %s', $where, $reason);
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $column
     *
     * @return list<string>
     */
    private function validateSortColumn(string $where, string $key, array $column, ListMapping $mapping, PlatformProbe $platform): array
    {
        $where .= \sprintf(', sort column "%s"', $key);
        $options = $column['provider_options'][DoctrineQueryHandlerFactory::PROVIDER] ?? [];
        $errors = [];
        foreach (array_diff(array_keys($options), self::SORT_OPTIONS) as $unknown) {
            $errors[] = \sprintf('%s: unknown provider_options.doctrine key "%s" (allowed: %s).', $where, $unknown, implode(', ', self::SORT_OPTIONS));
        }
        $field = Field::fromArray($column['field']);
        $resolved = $this->resolve($where, $field, $mapping, $errors);
        if (null !== $resolved && null !== ($reason = FieldCapabilities::sortRefusal($resolved, $options['aggregate'] ?? null))) {
            $errors[] = \sprintf('%s: cannot sort on "%s": %s.', $where, $field, $reason);
        }
        if (null !== $resolved) {
            $errors = [...$errors, ...$this->validateJson($where, $resolved, $platform)];
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateJson(string $where, ResolvedField $field, PlatformProbe $platform): array
    {
        $dialect = $platform->dialect();
        if (!$field->isJson() || null === $dialect) {
            return [];
        }
        $support = $this->factory->jsonSupport($dialect);
        $reason = $support->refusal ?? $support->castRefusal($field->field->cast);

        return null === $reason ? [] : [\sprintf('%s: the JSON path "%s" cannot be compiled: %s', $where, $field->field, $reason)];
    }

    /**
     * @param list<string> $errors
     */
    private function resolve(string $where, Field $field, ListMapping $mapping, array &$errors): ?ResolvedField
    {
        try {
            return $mapping->fields->resolve($field);
        } catch (ConfigurationException $e) {
            $errors[] = $where.': '.$e->getMessage();

            return null;
        }
    }
}
