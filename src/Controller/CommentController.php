<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Repository\CommentRepository;
use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class CommentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PostRepository $postRepository,
        private readonly CommentRepository $commentRepository,
    ) {
    }

    #[Route('/api/posts/{postId}/comments', name: 'api_comments_list', methods: ['GET'])]
    public function list(string $postId): JsonResponse
    {
        $post = $this->findPostOrFail($postId);
        if ($post instanceof JsonResponse) {
            return $post;
        }

        $comments = $this->commentRepository->findByPostWithChildren($post);

        return new JsonResponse(
            array_map(fn (Comment $c) => $this->serializeComment($c, true), $comments)
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/posts/{postId}/comments  (authenticated)
    // -------------------------------------------------------------------------
    #[Route('/api/posts/{postId}/comments', name: 'api_comments_create', methods: ['POST'])]
    public function create(string $postId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if ($user === null) {
            return $this->jsonError('Authentication required.', Response::HTTP_UNAUTHORIZED);
        }

        $post = $this->findPostOrFail($postId);
        if ($post instanceof JsonResponse) {
            return $post;
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->jsonError('Invalid JSON body.', Response::HTTP_BAD_REQUEST);
        }

        $content = trim($data['content'] ?? '');
        if ($content === '') {
            return $this->jsonError('The content field is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $parent = null;
        if (!empty($data['parentId'])) {
            $parentId = $data['parentId'];
            if (!Uuid::isValid($parentId)) {
                return $this->jsonError('Invalid parentId.', Response::HTTP_BAD_REQUEST);
            }
            $parent = $this->commentRepository->find(Uuid::fromString($parentId));
            if ($parent === null) {
                return $this->jsonError('Parent comment not found.', Response::HTTP_NOT_FOUND);
            }
            // Ensure parent belongs to the same post
            if ($parent->getPost() !== $post) {
                return $this->jsonError('Parent comment does not belong to this post.', Response::HTTP_BAD_REQUEST);
            }
        }

        $comment = new Comment();
        $comment->setContent($content);
        $comment->setUser($user);
        $comment->setPost($post);
        $comment->setParent($parent);

        $this->em->persist($comment);
        $this->em->flush();

        return new JsonResponse($this->serializeComment($comment, false), Response::HTTP_CREATED);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/comments/{id}  (authenticated, owner or admin)
    // -------------------------------------------------------------------------
    #[Route('/api/comments/{id}', name: 'api_comments_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $user = $this->getUser();
        if ($user === null) {
            return $this->jsonError('Authentication required.', Response::HTTP_UNAUTHORIZED);
        }

        if (!Uuid::isValid($id)) {
            return $this->jsonError('Invalid comment ID.', Response::HTTP_BAD_REQUEST);
        }

        $comment = $this->commentRepository->find(Uuid::fromString($id));
        if ($comment === null) {
            return $this->jsonError('Comment not found.', Response::HTTP_NOT_FOUND);
        }

        if ($comment->getUser() !== $user && !$this->isGranted('ROLE_ADMIN')) {
            return $this->jsonError('Access denied.', Response::HTTP_FORBIDDEN);
        }

        $this->em->remove($comment);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function findPostOrFail(string $postId): \App\Entity\Post|JsonResponse
    {
        if (!Uuid::isValid($postId)) {
            return $this->jsonError('Invalid post ID.', Response::HTTP_BAD_REQUEST);
        }

        $post = $this->postRepository->find(Uuid::fromString($postId));
        if ($post === null) {
            return $this->jsonError('Post not found.', Response::HTTP_NOT_FOUND);
        }

        return $post;
    }

    private function serializeComment(Comment $comment, bool $withChildren = false): array
    {
        $data = [
            'id'        => (string) $comment->getId(),
            'content'   => $comment->getContent(),
            'createdAt' => $comment->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'user'      => [
                'id'       => (string) $comment->getUser()?->getId(),
                'username' => $comment->getUser()?->getUsername(),
            ],
            'parentId'  => $comment->getParent() ? (string) $comment->getParent()->getId() : null,
        ];

        if ($withChildren) {
            $data['children'] = array_map(
                fn (Comment $child) => $this->serializeComment($child, false),
                $comment->getChildren()->toArray()
            );
        }

        return $data;
    }

    private function jsonError(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
