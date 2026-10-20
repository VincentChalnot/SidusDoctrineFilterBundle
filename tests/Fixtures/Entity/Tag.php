<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tag')]
class Tag
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 100)]
    private string $label;

    #[ORM\ManyToOne(targetEntity: Post::class, inversedBy: 'tags')]
    #[ORM\JoinColumn(nullable: false)]
    private Post $post;

    public function __construct(int $id, string $label, Post $post)
    {
        $this->id = $id;
        $this->label = $label;
        $this->post = $post;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getPost(): Post
    {
        return $this->post;
    }
}
