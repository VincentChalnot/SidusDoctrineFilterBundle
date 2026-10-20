<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Author;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Company;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Post;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Tag;
use Sidus\FilterBundle\Test\Contract\FixtureDataset;

/**
 * (Re)creates the schema and loads the shared contract fixture ({@see FixtureDataset}) into it. Alice works for the
 * company "Acme", Émile for "Globex", bob for none (nested to-one paths with a null in the middle).
 */
final class FixtureLoader
{
    public static function load(EntityManagerInterface $em): void
    {
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $companies = [1 => new Company(1, 'Acme', 'Lyon'), 3 => new Company(2, 'Globex', 'Paris')];
        foreach ($companies as $company) {
            $em->persist($company);
        }
        $authors = [];
        foreach (FixtureDataset::authors() as $data) {
            $authors[$data['id']] = new Author($data['id'], $data['name'], $companies[$data['id']] ?? null);
            $em->persist($authors[$data['id']]);
        }
        foreach (FixtureDataset::posts() as $data) {
            $post = new Post($data['id'], $data['title'], $data['status']);
            $post->setViews($data['views']);
            // As an application stores its dates: in the PHP default timezone (DBAL does not convert).
            $post->setPublishedAt($data['publishedAt']?->setTimezone(new \DateTimeZone(date_default_timezone_get())));
            $post->setSummary($data['summary']);
            $post->setAuthor(null === $data['author'] ? null : $authors[$data['author']['id']]);
            $em->persist($post);
            foreach ($data['tags'] as $tag) {
                $em->persist(new Tag($tag['id'], $tag['label'], $post));
            }
        }
        $em->flush();
        $em->clear();
    }
}
