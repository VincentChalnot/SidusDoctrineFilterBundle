<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Not associated with Author in the mapping: reachable only through a named join.
 */
#[ORM\Entity]
#[ORM\Table(name: 'profile')]
class Profile
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(name: 'author_ref', type: 'integer')]
    private int $authorId;

    #[ORM\Column(type: 'string', length: 100)]
    private string $bio;

    public function __construct(int $id, int $authorId, string $bio)
    {
        $this->id = $id;
        $this->authorId = $authorId;
        $this->bio = $bio;
    }

    public function getId(): int
    {
        return $this->id;
    }
}
