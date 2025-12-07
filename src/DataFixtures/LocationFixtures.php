<?php

namespace App\DataFixtures;

use App\Entity\Location;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class LocationFixtures extends Fixture
{
    public const LOC_LTB = 'loc_ltb';
    public const LOC_A2  = 'loc_a2';
    public const LOC_MOU = 'loc_mou';

    public function load(ObjectManager $manager): void
    {
        $data = [
            self::LOC_LTB => ['name' => 'Magasin La Teste',    'code' => 'LTB'],
            self::LOC_A2  => ['name' => 'Magasin Arcachon',    'code' => 'A2'],
            self::LOC_MOU => ['name' => 'Magasin Le Moulleau', 'code' => 'MOU'],
        ];

        foreach ($data as $ref => $locData) {
            $location = new Location();
            $location
                ->setName($locData['name'])
                ->setCode($locData['code'])
                ->setIsActive(true);

            $manager->persist($location);

            $this->addReference($ref, $location);
        }

        $manager->flush();
    }
}
