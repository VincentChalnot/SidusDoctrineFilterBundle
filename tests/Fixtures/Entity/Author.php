<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'author')]
class Author
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Company $company = null;

    public function __construct(int $id, string $name, ?Company $company = null)
    {
        $this->id = $id;
        $this->name = $name;
        $this->company = $company;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }
}
