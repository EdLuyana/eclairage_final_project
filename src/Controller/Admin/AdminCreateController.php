<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Entity\Supplier;
use App\Entity\Season;
use App\Entity\Category;
use App\Entity\Color;
use App\Form\ProductForm;
use App\Form\SupplierForm;
use App\Form\SeasonForm;
use App\Form\CategoryForm;
use App\Form\ColorForm;
use App\Repository\ProductRepository;
use App\Repository\SizeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminCreateController extends AbstractController
{
    #[Route('/admin/create', name: 'admin_create_index')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(
        Request $request,
        EntityManagerInterface $em,
        ProductRepository $productRepository,
        SizeRepository $sizeRepository
    ): Response {
        // Instances pour les formulaires
        $product   = new Product();
        $supplier  = new Supplier();
        $season    = new Season();
        $category  = new Category();
        $color     = new Color();

        $productForm   = $this->createForm(ProductForm::class, $product);
        $supplierForm  = $this->createForm(SupplierForm::class, $supplier);
        $seasonForm    = $this->createForm(SeasonForm::class, $season);
        $categoryForm  = $this->createForm(CategoryForm::class, $category);
        $colorForm     = $this->createForm(ColorForm::class, $color);

        $productForm->handleRequest($request);
        $supplierForm->handleRequest($request);
        $seasonForm->handleRequest($request);
        $categoryForm->handleRequest($request);
        $colorForm->handleRequest($request);

        // Tailles sélectionnées (checkbox "sizes[]")
        // -> all('sizes') renvoie toujours un tableau (ou [] si rien)
        $selectedSizesIds = $request->request->all('sizes');
        if (!is_array($selectedSizesIds)) {
            $selectedSizesIds = [];
        }
        $selectedSizesIds = array_values(array_filter($selectedSizesIds, static function ($v) {
            return ctype_digit((string) $v);
        }));
        $selectedSizesIds = array_map('intval', $selectedSizesIds);

        // Création de produits (multi-couleurs + tailles)
        if ($productForm->isSubmitted() && $productForm->isValid()) {
            $selectedColors = $productForm->get('colors')->getData(); // Collection<Color>

            if (count($selectedColors) === 0) {
                $this->addFlash('warning', 'Veuillez sélectionner au moins une couleur pour créer le produit.');
                return $this->redirectToRoute('admin_create_index');
            }

            if (count($selectedSizesIds) === 0) {
                $this->addFlash('warning', 'Veuillez sélectionner au moins une taille.');
                return $this->redirectToRoute('admin_create_index');
            }

            $sizesConfig = json_encode($selectedSizesIds, JSON_THROW_ON_ERROR);

            $createdRefs = [];

            foreach ($selectedColors as $selectedColor) {
                $newProduct = new Product();
                $newProduct->setName($product->getName());
                $newProduct->setSupplier($product->getSupplier());
                $newProduct->setSeason($product->getSeason());
                $newProduct->setCategory($product->getCategory());
                $newProduct->setColor($selectedColor);
                $newProduct->setPrice($product->getPrice());
                $newProduct->setStatus('ACTIVE');
                $newProduct->setIsArchived(false);
                $newProduct->setCreatedAt(new \DateTimeImmutable());
                $newProduct->setSizesConfig($sizesConfig);

                $reference = $this->generateReference($newProduct);
                $newProduct->setReference($reference);

                $barcodeBase = $this->generateUniqueBarcodeBase($productRepository);
                $newProduct->setBarcodeBase($barcodeBase);

                $em->persist($newProduct);
                $createdRefs[] = $reference;
            }

            $em->flush();

            $this->addFlash('success', sprintf(
                'Produits créés (%d couleur(s), %d taille(s)) : %s',
                count($selectedColors),
                count($selectedSizesIds),
                implode(', ', $createdRefs)
            ));

            return $this->redirectToRoute('admin_create_index');
        }

        // Nouveau fournisseur
        if ($supplierForm->isSubmitted() && $supplierForm->isValid()) {
            $supplier->setSlug($this->slugify($supplier->getName()));

            $em->persist($supplier);
            $em->flush();

            $this->addFlash('success', sprintf(
                'Nouveau fournisseur créé : %s',
                $supplier->getName()
            ));

            return $this->redirectToRoute('admin_create_index');
        }

        // Nouvelle saison / collection
        if ($seasonForm->isSubmitted() && $seasonForm->isValid()) {
            $season->setSlug($this->slugify($season->getName() . ' ' . $season->getYear()));

            $em->persist($season);
            $em->flush();

            $this->addFlash('success', sprintf(
                'Nouvelle collection créée : %s %d',
                $season->getName(),
                $season->getYear()
            ));

            return $this->redirectToRoute('admin_create_index');
        }

        // Nouvelle catégorie
        if ($categoryForm->isSubmitted() && $categoryForm->isValid()) {
            $category->setSlug($this->slugify($category->getName()));

            $em->persist($category);
            $em->flush();

            $this->addFlash('success', sprintf(
                'Nouvelle catégorie créée : %s',
                $category->getName()
            ));

            return $this->redirectToRoute('admin_create_index');
        }

        // Nouvelle couleur
        if ($colorForm->isSubmitted() && $colorForm->isValid()) {
            $color->setSlug($this->slugify($color->getName()));

            $em->persist($color);
            $em->flush();

            $this->addFlash('success', sprintf(
                'Nouvelle couleur créée : %s',
                $color->getName()
            ));

            return $this->redirectToRoute('admin_create_index');
        }

        // Récupération des tailles actives, groupées par type (US / FR / UNIQUE / AUTRE)
        $sizes = $sizeRepository->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']);
        $sizeGroups = [
            'FR'     => [],
            'US'     => [],
            'UNIQUE' => [],
            'OTHER'  => [],
        ];

        foreach ($sizes as $size) {
            $type = strtoupper($size->getType() ?? 'OTHER');
            if (!isset($sizeGroups[$type])) {
                $type = 'OTHER';
            }
            $sizeGroups[$type][] = $size;
        }

        return $this->render('admin/create/index.html.twig', [
            'page_title'    => 'Créer un produit ou un élément lié',
            'product_form'  => $productForm->createView(),
            'supplier_form' => $supplierForm->createView(),
            'season_form'   => $seasonForm->createView(),
            'category_form' => $categoryForm->createView(),
            'color_form'    => $colorForm->createView(),
            'size_groups'   => $sizeGroups,
            'selected_sizes'=> $selectedSizesIds,
        ]);
    }

    private function slugify(string $string): string
    {
        $string = strtolower(trim($string));
        $string = iconv('UTF-8', 'ASCII//TRANSLIT', $string);
        $string = preg_replace('/[^a-z0-9]+/', '-', $string);
        $string = trim($string, '-');

        return $string !== '' ? $string : 'n-a';
    }

    private function normalizeRefPart(string $value): string
    {
        $value = strtolower(trim($value));
        $value = iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = trim($value, '_');

        return $value !== '' ? $value : 'x';
    }

    private function generateReference(Product $product): string
    {
        $supplier = $product->getSupplier();
        $season   = $product->getSeason();
        $color    = $product->getColor();

        $supplierPart = $supplier ? substr($this->normalizeRefPart($supplier->getName()), 0, 6) : 'SUP';
        $seasonPart   = $season
            ? $this->normalizeRefPart($season->getName() . $season->getYear())
            : 'NA';
        $namePart     = substr($this->normalizeRefPart($product->getName()), 0, 25);
        $colorPart    = $color ? $this->normalizeRefPart($color->getName()) : 'NA';

        return sprintf('%s_%s_%s_%s', $supplierPart, $seasonPart, $namePart, $colorPart);
    }

    private function generateUniqueBarcodeBase(ProductRepository $productRepository): string
    {
        do {
            $candidate = (string) random_int(100000, 9999999);
        } while ($productRepository->findOneBy(['barcodeBase' => $candidate]) !== null);

        return $candidate;
    }
}
