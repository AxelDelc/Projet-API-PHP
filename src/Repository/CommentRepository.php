<?php

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Post;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Comment>
 */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /**
     * Returns root comments (no parent) for a given post, with their children eagerly loaded.
     *
     * @return Comment[]
     */
    public function findByPostWithChildren(Post $post): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')
            ->addSelect('u')
            ->leftJoin('c.children', 'ch')
            ->addSelect('ch')
            ->leftJoin('ch.user', 'chu')
            ->addSelect('chu')
            ->where('c.post = :post')
            ->andWhere('c.parent IS NULL')
            ->setParameter('post', $post)
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('ch.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
