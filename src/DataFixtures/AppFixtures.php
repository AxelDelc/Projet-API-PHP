<?php

namespace App\DataFixtures;

use App\Entity\Comment;
use App\Entity\Like;
use App\Entity\Post;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // ------------------------------------------------------------------
        // Users
        // ------------------------------------------------------------------

        $admin = new User();
        $admin->setEmail('admin@photo.com');
        $admin->setUsername('admin');
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'Admin1234!'));
        $admin->setRoles(['ROLE_ADMIN']);
        $manager->persist($admin);

        $regularUsers = [];
        for ($i = 1; $i <= 3; $i++) {
            $user = new User();
            $user->setEmail("user{$i}@photo.com");
            $user->setUsername("user{$i}");
            $user->setPassword($this->passwordHasher->hashPassword($user, 'User1234!'));
            $manager->persist($user);
            $regularUsers[] = $user;
        }

        $allUsers = array_merge([$admin], $regularUsers);

        // ------------------------------------------------------------------
        // Posts (5 per user)
        // ------------------------------------------------------------------

        $imageSeeds = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120, 130, 140, 150, 160, 170, 180, 190, 200];
        $allPosts   = [];
        $seedIndex  = 0;

        $postTitles = [
            'Golden Hour Vibes',
            'Morning Mist',
            'Urban Jungle',
            'Ocean Waves',
            'Mountain Peaks',
        ];

        foreach ($allUsers as $owner) {
            for ($p = 0; $p < 5; $p++) {
                $seed    = $imageSeeds[$seedIndex % count($imageSeeds)];
                $seedIndex++;

                $post = new Post();
                $post->setTitle($postTitles[$p] . ' by ' . $owner->getUsername());
                $post->setDescription('A beautiful photo shared by ' . $owner->getUsername() . '.');
                $post->setImageUrl("https://picsum.photos/seed/{$seed}/800/600");
                $post->setUser($owner);
                $manager->persist($post);
                $allPosts[] = $post;
            }
        }

        // ------------------------------------------------------------------
        // Comments
        // ------------------------------------------------------------------

        $commentTexts = [
            'Amazing shot!',
            'Love the composition.',
            'This is breathtaking.',
            'Where was this taken?',
            'Incredible colors!',
        ];

        // Root comments on first 6 posts
        $rootComments = [];
        for ($i = 0; $i < 6 && $i < count($allPosts); $i++) {
            $post        = $allPosts[$i];
            $commenter   = $regularUsers[$i % count($regularUsers)];
            $commentText = $commentTexts[$i % count($commentTexts)];

            $comment = new Comment();
            $comment->setContent($commentText);
            $comment->setUser($commenter);
            $comment->setPost($post);
            $manager->persist($comment);
            $rootComments[] = $comment;
        }

        // Flush to get IDs before adding children
        $manager->flush();

        // Child comments on first 3 root comments
        for ($i = 0; $i < 3 && $i < count($rootComments); $i++) {
            $parent    = $rootComments[$i];
            $responder = $regularUsers[($i + 1) % count($regularUsers)];

            $reply = new Comment();
            $reply->setContent('Thanks for the kind words! ' . $parent->getPost()->getTitle());
            $reply->setUser($responder);
            $reply->setPost($parent->getPost());
            $reply->setParent($parent);
            $manager->persist($reply);
        }

        // ------------------------------------------------------------------
        // Likes
        // ------------------------------------------------------------------

        // Each regular user likes the first 4 posts (that they don't own)
        $likedCombinations = [];
        foreach ($regularUsers as $liker) {
            $likeCount = 0;
            foreach ($allPosts as $post) {
                if ($likeCount >= 4) {
                    break;
                }
                // Don't like own posts
                if ($post->getUser() === $liker) {
                    continue;
                }
                $key = (string) $liker->getId() . '|' . (string) $post->getId();
                if (isset($likedCombinations[$key])) {
                    continue;
                }
                $likedCombinations[$key] = true;

                $like = new Like();
                $like->setUser($liker);
                $like->setPost($post);
                $manager->persist($like);
                $likeCount++;
            }
        }

        $manager->flush();
    }
}
