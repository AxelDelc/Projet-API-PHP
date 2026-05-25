<?php

namespace App\Controller;

use App\Entity\Post;
use App\Repository\CommentRepository;
use App\Repository\PostRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly PostRepository $postRepository,
        private readonly CommentRepository $commentRepository,
    ) {
    }

    // -------------------------------------------------------------------------
    // GET /api/admin/users
    // -------------------------------------------------------------------------
    #[Route('/api/admin/users', name: 'api_admin_users_list', methods: ['GET'])]
    public function listUsers(): JsonResponse
    {
        $users = $this->userRepository->findAll();

        $data = array_map(function ($u) {
            return [
                'id'         => (string) $u->getId(),
                'email'      => $u->getEmail(),
                'username'   => $u->getUsername(),
                'roles'      => $u->getRoles(),
                'createdAt'  => $u->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'postsCount' => $u->getPosts()->count(),
            ];
        }, $users);

        return new JsonResponse($data);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/admin/users/{id}
    // -------------------------------------------------------------------------
    #[Route('/api/admin/users/{id}', name: 'api_admin_users_delete', methods: ['DELETE'])]
    public function deleteUser(string $id): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return $this->jsonError('Invalid user ID.', Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->find(Uuid::fromString($id));
        if ($user === null) {
            return $this->jsonError('User not found.', Response::HTTP_NOT_FOUND);
        }

        // Prevent admin from deleting themselves
        if ($user === $this->getUser()) {
            return $this->jsonError('You cannot delete your own account.', Response::HTTP_FORBIDDEN);
        }

        $this->em->remove($user);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/admin/users/{id}/role
    // -------------------------------------------------------------------------
    #[Route('/api/admin/users/{id}/role', name: 'api_admin_users_role', methods: ['PATCH'])]
    public function updateUserRole(string $id, Request $request): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return $this->jsonError('Invalid user ID.', Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->find(Uuid::fromString($id));
        if ($user === null) {
            return $this->jsonError('User not found.', Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->jsonError('Invalid JSON body.', Response::HTTP_BAD_REQUEST);
        }

        $role = $data['role'] ?? '';

        if (!in_array($role, ['ROLE_ADMIN', 'ROLE_USER'], true)) {
            return $this->jsonError('Role must be ROLE_ADMIN or ROLE_USER.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->setRoles([$role]);
        $this->em->flush();

        return new JsonResponse([
            'id'    => (string) $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/admin/posts
    // -------------------------------------------------------------------------
    #[Route('/api/admin/posts', name: 'api_admin_posts_list', methods: ['GET'])]
    public function listPosts(): JsonResponse
    {
        $posts = $this->postRepository->findBy([], ['createdAt' => 'DESC']);

        $data = array_map(function (Post $p) {
            return [
                'id'          => (string) $p->getId(),
                'title'       => $p->getTitle(),
                'description' => $p->getDescription(),
                'imageUrl'    => $p->getImageUrl(),
                'createdAt'   => $p->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'updatedAt'   => $p->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
                'user'        => [
                    'id'       => (string) $p->getUser()?->getId(),
                    'username' => $p->getUser()?->getUsername(),
                    'email'    => $p->getUser()?->getEmail(),
                ],
                'likesCount'    => $p->getLikes()->count(),
                'commentsCount' => $p->getComments()->count(),
            ];
        }, $posts);

        return new JsonResponse($data);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/admin/posts/{id}
    // -------------------------------------------------------------------------
    #[Route('/api/admin/posts/{id}', name: 'api_admin_posts_delete', methods: ['DELETE'])]
    public function deletePost(string $id): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return $this->jsonError('Invalid post ID.', Response::HTTP_BAD_REQUEST);
        }

        $post = $this->postRepository->find(Uuid::fromString($id));
        if ($post === null) {
            return $this->jsonError('Post not found.', Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($post);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/admin/comments/{id}
    // -------------------------------------------------------------------------
    #[Route('/api/admin/comments/{id}', name: 'api_admin_comments_delete', methods: ['DELETE'])]
    public function deleteComment(string $id): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return $this->jsonError('Invalid comment ID.', Response::HTTP_BAD_REQUEST);
        }

        $comment = $this->commentRepository->find(Uuid::fromString($id));
        if ($comment === null) {
            return $this->jsonError('Comment not found.', Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($comment);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function jsonError(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
