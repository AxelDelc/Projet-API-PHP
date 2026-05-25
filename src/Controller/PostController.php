<?php

namespace App\Controller;

use App\Entity\Post;
use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class PostController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PostRepository $postRepository,
    ) {
    }

    #[Route('/api/posts', name: 'api_posts_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = min(50, max(1, (int) $request->query->get('limit', 10)));

        $result = $this->postRepository->findPaginated($page, $limit);

        $posts = array_map(fn (Post $p) => $this->serializePost($p), $result['posts']);

        return new JsonResponse([
            'posts' => $posts,
            'total' => $result['total'],
            'page'  => $page,
            'limit' => $limit,
            'pages' => (int) ceil($result['total'] / $limit),
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/posts/{id}  (public)
    // -------------------------------------------------------------------------
    #[Route('/api/posts/{id}', name: 'api_posts_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $post = $this->findPostOrFail($id);
        if ($post instanceof JsonResponse) {
            return $post;
        }

        return new JsonResponse($this->serializePost($post, true));
    }

    // -------------------------------------------------------------------------
    // POST /api/posts  (authenticated)
    // -------------------------------------------------------------------------
    #[Route('/api/posts', name: 'api_posts_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if ($user === null) {
            return $this->jsonError('Authentication required.', Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->jsonError('Invalid JSON body.', Response::HTTP_BAD_REQUEST);
        }

        $title    = trim($data['title'] ?? '');
        $imageUrl = trim($data['imageUrl'] ?? '');

        if ($title === '') {
            return $this->jsonError('The title field is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($imageUrl === '') {
            return $this->jsonError('The imageUrl field is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            return $this->jsonError('The imageUrl must be a valid URL.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $post = new Post();
        $post->setTitle($title);
        $post->setDescription($data['description'] ?? null);
        $post->setImageUrl($imageUrl);
        $post->setUser($user);

        $this->em->persist($post);
        $this->em->flush();

        return new JsonResponse($this->serializePost($post), Response::HTTP_CREATED);
    }

    // -------------------------------------------------------------------------
    // PUT /api/posts/{id}  (authenticated, owner or admin)
    // -------------------------------------------------------------------------
    #[Route('/api/posts/{id}', name: 'api_posts_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if ($user === null) {
            return $this->jsonError('Authentication required.', Response::HTTP_UNAUTHORIZED);
        }

        $post = $this->findPostOrFail($id);
        if ($post instanceof JsonResponse) {
            return $post;
        }

        if ($post->getUser() !== $user && !$this->isGranted('ROLE_ADMIN')) {
            return $this->jsonError('Access denied.', Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->jsonError('Invalid JSON body.', Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['title'])) {
            $title = trim($data['title']);
            if ($title === '') {
                return $this->jsonError('The title cannot be empty.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $post->setTitle($title);
        }

        if (array_key_exists('description', $data)) {
            $post->setDescription($data['description']);
        }

        if (isset($data['imageUrl'])) {
            $imageUrl = trim($data['imageUrl']);
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                return $this->jsonError('The imageUrl must be a valid URL.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $post->setImageUrl($imageUrl);
        }

        $this->em->flush();

        return new JsonResponse($this->serializePost($post));
    }

    // -------------------------------------------------------------------------
    // DELETE /api/posts/{id}  (authenticated, owner or admin)
    // -------------------------------------------------------------------------
    #[Route('/api/posts/{id}', name: 'api_posts_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $user = $this->getUser();
        if ($user === null) {
            return $this->jsonError('Authentication required.', Response::HTTP_UNAUTHORIZED);
        }

        $post = $this->findPostOrFail($id);
        if ($post instanceof JsonResponse) {
            return $post;
        }

        if ($post->getUser() !== $user && !$this->isGranted('ROLE_ADMIN')) {
            return $this->jsonError('Access denied.', Response::HTTP_FORBIDDEN);
        }

        $this->em->remove($post);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function findPostOrFail(string $id): Post|JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return $this->jsonError('Invalid post ID.', Response::HTTP_BAD_REQUEST);
        }

        $post = $this->postRepository->find(Uuid::fromString($id));
        if ($post === null) {
            return $this->jsonError('Post not found.', Response::HTTP_NOT_FOUND);
        }

        return $post;
    }

    private function serializePost(Post $post, bool $withCounts = false): array
    {
        $data = [
            'id'          => (string) $post->getId(),
            'title'       => $post->getTitle(),
            'description' => $post->getDescription(),
            'imageUrl'    => $post->getImageUrl(),
            'createdAt'   => $post->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt'   => $post->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'user'        => [
                'id'       => (string) $post->getUser()?->getId(),
                'username' => $post->getUser()?->getUsername(),
            ],
        ];

        if ($withCounts) {
            $data['likesCount']    = $post->getLikes()->count();
            $data['commentsCount'] = $post->getComments()->count();
        }

        return $data;
    }

    private function jsonError(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
