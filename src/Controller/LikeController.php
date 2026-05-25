<?php

namespace App\Controller;

use App\Entity\Like;
use App\Repository\LikeRepository;
use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class LikeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PostRepository $postRepository,
        private readonly LikeRepository $likeRepository,
    ) {
    }

    #[Route('/api/posts/{postId}/like', name: 'api_posts_like', methods: ['POST'])]
    public function toggle(string $postId): JsonResponse
    {
        $user = $this->getUser();
        if ($user === null) {
            return $this->jsonError('Authentication required.', Response::HTTP_UNAUTHORIZED);
        }

        if (!Uuid::isValid($postId)) {
            return $this->jsonError('Invalid post ID.', Response::HTTP_BAD_REQUEST);
        }

        $post = $this->postRepository->find(Uuid::fromString($postId));
        if ($post === null) {
            return $this->jsonError('Post not found.', Response::HTTP_NOT_FOUND);
        }

        $existingLike = $this->likeRepository->findOneByUserAndPost($user, $post);

        if ($existingLike !== null) {
            // Toggle off: remove the like
            $this->em->remove($existingLike);
            $this->em->flush();

            $likesCount = $this->likeRepository->count(['post' => $post]);

            return new JsonResponse([
                'liked'      => false,
                'likesCount' => $likesCount,
            ]);
        }

        // Toggle on: create a new like
        $like = new Like();
        $like->setUser($user);
        $like->setPost($post);

        $this->em->persist($like);
        $this->em->flush();

        $likesCount = $this->likeRepository->count(['post' => $post]);

        return new JsonResponse([
            'liked'      => true,
            'likesCount' => $likesCount,
        ], Response::HTTP_CREATED);
    }

    private function jsonError(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
