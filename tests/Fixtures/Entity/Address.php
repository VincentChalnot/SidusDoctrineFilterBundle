<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class Address
{
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $city;

    public function __construct(?string $city = null)
    {
        $this->city = $city;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }
}
