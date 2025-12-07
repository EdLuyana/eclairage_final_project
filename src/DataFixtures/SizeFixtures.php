<?php

namespace App\DataFixtures;

use App\Entity\Size;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class SizeFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $sizes = [];
        $order = 1;

        // Tailles FR (vêtements)
        foreach ([34, 36, 38, 40, 42, 44] as $value) {
            $size = new Size();
            $size
                ->setName((string) $value)
                ->setCode('FR' . $value)
                ->setType('FR')
                ->setSortOrder($order++);

            $manager->persist($size);
            $sizes[$size->getCode()] = $size;
        }

        // Tailles US (vêtements)
        foreach (['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'] as $us) {
            $size = new Size();
            $size
                ->setName($us)
                ->setCode('US' . $us)
                ->setType('US')
                ->setSortOrder($order++);

            $manager->persist($size);
            $sizes[$size->getCode()] = $size;
        }

        // Taille unique
        $unique = new Size();
        $unique
            ->setName('U')
            ->setCode('U')
            ->setType('UNIQUE')
            ->setSortOrder($order++);

        $manager->persist($unique);
        $sizes['U'] = $unique;

        // Tailles CHAUSSURES 36 → 41
        foreach ([36, 37, 38, 39, 40, 41] as $shoe) {
            $size = new Size();
            $size
                ->setName((string) $shoe)
                ->setCode('SH' . $shoe)      // ex : SH36, SH37...
                ->setType('SHOE')
                ->setSortOrder($order++);

            $manager->persist($size);
        }

        $manager->flush();
    }
}
