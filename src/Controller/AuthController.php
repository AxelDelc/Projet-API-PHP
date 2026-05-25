<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AuthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->jsonError('Invalid JSON body.', Response::HTTP_BAD_REQUEST);
        }

        $email    = trim($data['email'] ?? '');
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';

        // Basic presence validation
        if ($email === '' || $username === '' || $password === '') {
            return $this->jsonError('Fields email, username and password are required.', Response::HTTP_BAD_REQUEST);
        }

        // Validate email format
        $emailViolations = $this->validator->validate($email, [
            new Assert\Email(message: 'The email "{{ value }}" is not a valid email.'),
        ]);
        if (count($emailViolations) > 0) {
            return $this->jsonError((string) $emailViolations->get(0)->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Validate password length
        if (strlen($password) < 8) {
            return $this->jsonError('Password must be at least 8 characters.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Validate username length
        if (strlen($username) < 3 || strlen($username) > 50) {
            return $this->jsonError('Username must be between 3 and 50 characters.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Check uniqueness
        if ($this->userRepository->findOneByEmail($email) !== null) {
            return $this->jsonError('This email is already in use.', Response::HTTP_CONFLICT);
        }

        if ($this->userRepository->findOneByUsername($username) !== null) {
            return $this->jsonError('This username is already taken.', Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setUsername($username);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return new JsonResponse([
            'id'        => (string) $user->getId(),
            'email'     => $user->getEmail(),
            'username'  => $user->getUsername(),
            'createdAt' => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'message'   => 'User registered successfully.',
        ], Response::HTTP_CREATED);
    }

    private function jsonError(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
