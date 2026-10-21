<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Author;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Category;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Company;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Post;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Profile;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Review;
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
        // Posts 1 and 2 are in "music", post 3 in "cinema", the others in none (string identifier target).
        $categories = ['music' => new Category('music', 'Music'), 'cinema' => new Category('cinema', 'Cinema')];
        foreach ($categories as $category) {
            $em->persist($category);
        }
        $postCategories = [1 => 'music', 2 => 'music', 3 => 'cinema'];
        foreach (FixtureDataset::posts() as $data) {
            $post = new Post($data['id'], $data['title'], $data['status']);
            $post->setViews($data['views']);
            // As an application stores its dates: in the PHP default timezone (DBAL does not convert).
            $post->setPublishedAt($data['publishedAt']?->setTimezone(new \DateTimeZone(date_default_timezone_get())));
            $post->setSummary($data['summary']);
            $post->setAuthor(null === $data['author'] ? null : $authors[$data['author']['id']]);
            $post->setCategory(isset($postCategories[$data['id']]) ? $categories[$postCategories[$data['id']]] : null);
            $em->persist($post);
            foreach ($data['tags'] as $tag) {
                $em->persist(new Tag($tag['id'], $tag['label'], $post));
            }
        }
        // Alice and Émile have a profile; bob has none.
        $em->persist(new Profile(1, 1, 'Alice bio'));
        $em->persist(new Profile(2, 3, 'Émile bio'));
        // Reviews: post 1 has scores 5 and 3, post 2 has 4, post 4 has 5 twice, post 5 has 1.
        foreach ([[1, 1, 5, 'great'], [2, 1, 3, 'ok'], [3, 2, 4, null], [4, 4, 5, 'great'], [5, 4, 5, 'great again'], [6, 5, 1, 'bad']] as [$id, $post, $score, $comment]) {
            $em->persist(new Review($id, $post, $score, $comment));
        }
        $em->flush();
        $em->clear();
    }
}
