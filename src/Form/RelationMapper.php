<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Form;

use Doctrine\Persistence\ManagerRegistry;
use Sidus\DoctrineFilterBundle\Factory\DoctrineQueryHandlerFactory;
use Sidus\DoctrineFilterBundle\Filter\RelationFilterType;
use Sidus\FilterBundle\Definition\FilterDefinition;
use Sidus\FilterBundle\Definition\ListDefinition;
use Sidus\FilterBundle\Form\Mapper\AbstractFormFilterMapper;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * The form field of a `relation` filter: an `EntityType` on the entity the attribute leads to (derived from the
 * list's entity on a `doctrine` list, or the filter's `class` option). The submitted view data is already the
 * identifier(s), which is the raw input of the filter; the initial data (the normalised default) is turned into entity
 * references. Choices default to every entity of the class: narrow or label them with the filter's `form.options`
 * (`query_builder`, `choice_label`).
 */
final class RelationMapper extends AbstractFormFilterMapper
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly DoctrineQueryHandlerFactory $factory,
    ) {
    }

    public function getFilterType(): string
    {
        return 'relation';
    }

    public function add(FormBuilderInterface $builder, FilterDefinition $filter, ListDefinition $list, mixed $data): void
    {
        $class = $this->classOf($filter, $list);
        $this->addField($builder, $filter, EntityType::class, [
            'class' => $class,
            'multiple' => $filter->options['multiple'],
            'placeholder' => $filter->options['multiple'] ? false : '',
        ], $this->references($filter, $class, $data));
    }

    /**
     * @return class-string
     */
    private function classOf(FilterDefinition $filter, ListDefinition $list): string
    {
        if (null !== ($class = $filter->options['class'])) {
            return $class;
        }
        if (DoctrineQueryHandlerFactory::PROVIDER === $list->provider) {
            $field = $this->factory->mapping($list)->fields->resolve(RelationFilterType::fieldOf($filter, $filter->attributes[0]));
            if ([] !== $field->steps) {
                return $field->steps[\array_key_last($field->steps)]->target;
            }
        }

        throw new \LogicException(\sprintf('Relation filter "%s": the related entity cannot be derived from the list "%s" (provider "%s"), set the filter option "class".', $filter->key, $list->code, $list->provider));
    }

    /**
     * The model data of the field: the normalised identifier(s) as entity references.
     */
    private function references(FilterDefinition $filter, string $class, mixed $ids): mixed
    {
        if (null === $ids) {
            return null;
        }
        $manager = $this->registry->getManagerForClass($class) ?? throw new \LogicException(\sprintf('Relation filter "%s": %s is not a managed entity.', $filter->key, $class));
        $references = array_map(static fn (mixed $id): ?object => $manager->getReference($class, $id), \is_array($ids) ? $ids : [$ids]);

        return $filter->options['multiple'] ? $references : $references[0];
    }
}
