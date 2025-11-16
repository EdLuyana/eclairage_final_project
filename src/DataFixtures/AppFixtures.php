<?php

namespace App\DataFixtures;

use App\Entity\User;
use App\Entity\Location;
use App\Entity\Size;
use App\Entity\Supplier;
use App\Entity\Season;
use App\Entity\Category;
use App\Entity\Color;
use App\Entity\Product;
use App\Entity\Stock;
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
        // 1) Magasins
        $locations = [];
        foreach ([
            ['name' => 'Magasin La Teste',    'code' => 'LTB'],
            ['name' => 'Magasin Arcachon',    'code' => 'ARC'],
            ['name' => 'Magasin Le Moulleau', 'code' => 'MOU'],
        ] as $locData) {
            $location = new Location();
            $location
                ->setName($locData['name'])
                ->setCode($locData['code'])
                ->setIsActive(true);

            $manager->persist($location);
            $locations[$locData['code']] = $location;
        }

        // 2) Tailles (FR, US, UNIQUE)
        $sizes = [];

        // FR 34 à 44
        $order = 1;
        foreach ([34, 36, 38, 40, 42, 44] as $sizeValue) {
            $size = new Size();
            $size
                ->setName((string) $sizeValue)
                ->setCode('FR'.$sizeValue)
                ->setType('FR')
                ->setSortOrder($order++);

            $manager->persist($size);
            $sizes[$size->getCode()] = $size;
        }

        // US XS à XL
        foreach (['XS', 'S', 'M', 'L', 'XL'] as $usSize) {
            $size = new Size();
            $size
                ->setName($usSize)
                ->setCode('US'.$usSize)
                ->setType('US')
                ->setSortOrder($order++);

            $manager->persist($size);
            $sizes[$size->getCode()] = $size;
        }

        // UNIQUE (U)
        $unique = new Size();
        $unique
            ->setName('U')
            ->setCode('U')
            ->setType('UNIQUE')
            ->setSortOrder($order++);
        $manager->persist($unique);
        $sizes['U'] = $unique;

        // 3) Fournisseurs
        $suppliers = [];
        foreach (['Zara', 'Mango', 'Promod'] as $supplierName) {
            $supplier = new Supplier();
            $slug = strtolower(str_replace(' ', '-', $supplierName));

            $supplier
                ->setName($supplierName)
                ->setSlug($slug)
                ->setIsActive(true);

            $manager->persist($supplier);
            $suppliers[$slug] = $supplier;
        }

        // 4) Saisons
        $seasons = [];
        foreach ([
            ['name' => 'Été 2025',   'slug' => 'ete-2025',   'year' => 2025],
            ['name' => 'Hiver 2025', 'slug' => 'hiver-2025', 'year' => 2025],
        ] as $seasonData) {
            $season = new Season();
            $season
                ->setName($seasonData['name'])
                ->setSlug($seasonData['slug'])
                ->setYear($seasonData['year'])
                ->setIsActive(true);

            $manager->persist($season);
            $seasons[$seasonData['slug']] = $season;
        }

        // 5) Catégories
        $categories = [];
        foreach (['Robe', 'Pantalon', 'Chemise'] as $catName) {
            $category = new Category();
            $slug = strtolower(str_replace(' ', '-', $catName));

            $category
                ->setName($catName)
                ->setSlug($slug)
                ->setIsActive(true);

            $manager->persist($category);
            $categories[$slug] = $category;
        }

        // 6) Couleurs
        $colors = [];
        foreach ([
            ['name' => 'Noir',  'hex' => '#000000'],
            ['name' => 'Blanc', 'hex' => '#FFFFFF'],
            ['name' => 'Bleu',  'hex' => '#0000FF'],
        ] as $colorData) {
            $color = new Color();
            $slug = strtolower($colorData['name']);

            $color
                ->setName($colorData['name'])
                ->setSlug($slug)
                ->setHexCode($colorData['hex'])
                ->setIsActive(true);

            $manager->persist($color);
            $colors[$slug] = $color;
        }

        // 7) Utilisateur admin
        $admin = new User();
        $admin
            ->setUsername('admin')
            ->setRoles(['ROLE_ADMIN'])
            ->setIsActive(true);

        $hashedPassword = $this->passwordHasher->hashPassword($admin, 'admin');
        $admin->setPassword($hashedPassword);

        $manager->persist($admin);

        // 8) Utilisateur vendeuse
        $vendeuse = new User();
        $vendeuse
            ->setUsername('vendeuse')
            ->setRoles(['ROLE_USER'])
            ->setIsActive(true);

        $hashedPassword = $this->passwordHasher->hashPassword($vendeuse, 'vendeuse');
        $vendeuse->setPassword($hashedPassword);

        $manager->persist($vendeuse);

        // 9) Produit de démo + stock
        $product = new Product();
        $product
            ->setName('Robe bleue fluide')
            ->setReference('ZARA_ETE2025_ROBE_BLEUE')
            ->setPrice('49.00')
            ->setStatus('ACTIVE')
            ->setSupplier($suppliers['zara'])
            ->setSeason($seasons['ete-2025'])
            ->setCategory($categories['robe'])
            ->setColor($colors['bleu']);

        $manager->persist($product);

        // Quelques stocks pour ce produit (principalement à La Teste)
        $stockData = [
            ['size_code' => 'FR36', 'location_code' => 'LTB', 'quantity' => 5],
            ['size_code' => 'FR38', 'location_code' => 'LTB', 'quantity' => 3],
            ['size_code' => 'FR40', 'location_code' => 'LTB', 'quantity' => 2],
            ['size_code' => 'FR36', 'location_code' => 'ARC', 'quantity' => 4],
            ['size_code' => 'FR38', 'location_code' => 'ARC', 'quantity' => 2],
            ['size_code' => 'FR36', 'location_code' => 'MOU', 'quantity' => 1],
        ];

        foreach ($stockData as $sd) {
            if (
                !isset($sizes[$sd['size_code']])
                || !isset($locations[$sd['location_code']])
            ) {
                continue;
            }

            $stock = new Stock();
            $stock
                ->setProduct($product)
                ->setSize($sizes[$sd['size_code']])
                ->setLocation($locations[$sd['location_code']])
                ->setQuantity($sd['quantity']);

            $manager->persist($stock);
        }

        // Flush final
        $manager->flush();
    }
}
