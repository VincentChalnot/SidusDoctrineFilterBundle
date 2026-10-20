<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Form;

use Doctrine\Persistence\ManagerRegistry;
use Sidus\FilterBundle\Definition\FilterDefinition;
use Sidus\FilterBundle\Form\Mapper\AbstractFormFilterMapper;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * The form field of a `relation` filter: an `EntityType` on the entity named by the filter's `class` option. The
 * submitted view data is already the identifier(s), which is the raw input of the filter; the initial data is the
 * normalised default turned into entity references by {@see toFormData()} (which the list form calls before add()). Choices default to every entity of the class:
 * narrow them, or label them, with the filter's `form.options` (`query_builder`, `choice_label`).
 *
 * The mapper receives the filter, not the list, so the class cannot be derived from the list's entity: it is
 * declared (`options.class`) and checked against the Doctrine metadata at warm-up.
 */
final class RelationMapper extends AbstractFormFilterMapper
{
    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    public function getFilterType(): string
    {
        return 'relation';
    }

    public function add(FormBuilderInterface $builder, FilterDefinition $filter, mixed $data): void
    {
        $class = $this->classOf($filter);
        $this->addField($builder, $filter, EntityType::class, [
            'class' => $class,
            'multiple' => $filter->options['multiple'],
            'placeholder' => $filter->options['multiple'] ? false : '',
        ], $data);
    }

    public function toFormData(FilterDefinition $filter, mixed $normalized): mixed
    {
        if (null === $normalized) {
            return null;
        }
        $class = $this->classOf($filter);
        $manager = $this->registry->getManagerForClass($class) ?? throw new \LogicException(\sprintf('Relation filter "%s": %s is not a managed entity.', $filter->key, $class));
        $references = array_map(static fn (mixed $id): ?object => $manager->getReference($class, $id), \is_array($normalized) ? $normalized : [$normalized]);

        return $filter->options['multiple'] ? $references : $references[0];
    }

    /**
     * @return class-string
     */
    private function classOf(FilterDefinition $filter): string
    {
        return $filter->options['class'] ?? throw new \LogicException(\sprintf('Relation filter "%s": the form field needs the related entity, set the filter option "class".', $filter->key));
    }
}
