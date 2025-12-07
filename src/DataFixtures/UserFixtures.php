<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $admin = new User();
        $admin
            ->setUsername('Domibre')
            ->setRoles(['ROLE_ADMIN'])
            ->setIsActive(true);

        // Mot de passe : "eclair1225"
        $hashed = $this->passwordHasher->hashPassword($admin, 'eclair1225');
        $admin->setPassword($hashed);

        $manager->persist($admin);

        $manager->flush();
    }
}
