<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Not associated with Post in the mapping: reachable only through a named join (cardinality many).
 */
#[ORM\Entity]
#[ORM\Table(name: 'review')]
class Review
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(name: 'post_ref', type: 'integer')]
    private int $postId;

    #[ORM\Column(type: 'integer')]
    private int $score;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $comment;

    public function __construct(int $id, int $postId, int $score, ?string $comment = null)
    {
        $this->id = $id;
        $this->postId = $postId;
        $this->score = $score;
        $this->comment = $comment;
    }

    public function getId(): int
    {
        return $this->id;
    }
}
