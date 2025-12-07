<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Entity\StockMovement;
use App\Form\ProductEditForm;
use App\Repository\ProductRepository;
use App\Repository\SupplierRepository;
use App\Repository\SeasonRepository;
use App\Repository\CategoryRepository;
use App\Repository\StockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminProductController extends AbstractController
{
    #[Route('/admin/products', name: 'admin_product_index')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(
        Request $request,
        ProductRepository $productRepository,
        SupplierRepository $supplierRepository,
        SeasonRepository $seasonRepository,
        CategoryRepository $categoryRepository
    ): Response {
        // Valeurs brutes depuis la query string
        $supplierRaw = $request->query->get('supplier');
        $seasonRaw   = $request->query->get('season');
        $categoryRaw = $request->query->get('category');
        $search      = trim((string) $request->query->get('q', ''));
        $showArchived = $request->query->get('archived', '0') === '1';

        // Conversion en int ou null
        $supplierId = ctype_digit((string) $supplierRaw) ? (int) $supplierRaw : null;
        $seasonId   = ctype_digit((string) $seasonRaw) ? (int) $seasonRaw : null;
        $categoryId = ctype_digit((string) $categoryRaw) ? (int) $categoryRaw : null;

        $qb = $productRepository->createQueryBuilder('p')
            ->leftJoin('p.supplier', 's')->addSelect('s')
            ->leftJoin('p.season', 'se')->addSelect('se')
            ->leftJoin('p.category', 'c')->addSelect('c')
            ->leftJoin('p.color', 'co')->addSelect('co');

        if (!$showArchived) {
            $qb->andWhere('p.isArchived = :archived')->setParameter('archived', false);
        }

        if ($supplierId !== null) {
            $qb->andWhere('s.id = :supplierId')->setParameter('supplierId', $supplierId);
        }

        if ($seasonId !== null) {
            $qb->andWhere('se.id = :seasonId')->setParameter('seasonId', $seasonId);
        }

        if ($categoryId !== null) {
            $qb->andWhere('c.id = :categoryId')->setParameter('categoryId', $categoryId);
        }

        if ($search !== '') {
            $qb
                ->andWhere('p.reference LIKE :search OR p.name LIKE :search OR co.name LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        // Tri : fournisseur A→Z puis référence
        $qb
            ->orderBy('s.name', 'ASC')
            ->addOrderBy('p.reference', 'ASC');

        $products = $qb->getQuery()->getResult();

        // Listes pour les filtres
        $suppliers  = $supplierRepository->findBy(['isActive' => true], ['name' => 'ASC']);
        $seasons    = $seasonRepository->findBy(['isActive' => true], ['year' => 'DESC', 'name' => 'ASC']);
        $categories = $categoryRepository->findBy(['isActive' => true], ['name' => 'ASC']);

        return $this->render('admin/product/index.html.twig', [
            'page_title' => 'Tous les produits',
            'products'   => $products,
            'suppliers'  => $suppliers,
            'seasons'    => $seasons,
            'categories' => $categories,
            'filters'    => [
                'supplier' => $supplierId,
                'season'   => $seasonId,
                'category' => $categoryId,
                'archived' => $showArchived,
                'q'        => $search,
            ],
        ]);
    }

    #[Route('/admin/products/{id}/toggle-archive', name: 'admin_product_toggle_archive', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleArchive(
        int $id,
        Request $request,
        ProductRepository $productRepository,
        EntityManagerInterface $em
    ): Response {
        $product = $productRepository->find($id);

        if (!$product) {
            $this->addFlash('danger', 'Produit introuvable.');
            return $this->redirectToRoute('admin_product_index');
        }

        if (!$this->isCsrfTokenValid('toggle_archive_' . $product->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_product_index');
        }

        $newState = !$product->isArchived();
        $product->setIsArchived($newState);

        $em->flush();

        $this->addFlash(
            'success',
            $newState
                ? sprintf('Le produit %s a été archivé.', $product->getReference())
                : sprintf('Le produit %s a été réactivé.', $product->getReference())
        );

        return $this->redirectToRoute('admin_product_index');
    }

    #[Route('/admin/products/{id}/clearance', name: 'admin_product_clearance', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function clearance(
        int $id,
        Request $request,
        ProductRepository $productRepository,
        StockRepository $stockRepository,
        EntityManagerInterface $em
    ): Response {
        $product = $productRepository->find($id);

        if (!$product) {
            $this->addFlash('danger', 'Produit introuvable.');
            return $this->redirectToRoute('admin_product_index');
        }

        if (!$this->isCsrfTokenValid('product_clearance_' . $product->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_product_index');
        }

        $stocks = $stockRepository->findBy(['product' => $product]);
        $totalCleared = 0;

        foreach ($stocks as $stock) {
            $qty = $stock->getQuantity();
            if ($qty <= 0) {
                continue;
            }

            $movement = new StockMovement();
            $movement->setType('CLEARANCE');
            $movement->setQuantity($qty);
            $movement->setProduct($product);
            $movement->setSize($stock->getSize());
            $movement->setLocation($stock->getLocation());
            $movement->setComment('Sortie braderie / clear stock (admin)');

            $user = $this->getUser();
            if ($user instanceof \App\Entity\User) {
                $movement->setUser($user);
            }

            $em->persist($movement);

            $stock->setQuantity(0);
            $totalCleared += $qty;
        }

        $product->setIsArchived(true);

        $em->flush();

        if ($totalCleared > 0) {
            $this->addFlash(
                'success',
                sprintf(
                    'Produit %s envoyé en braderie : %d unités sorties du stock et produit archivé.',
                    $product->getReference(),
                    $totalCleared
                )
            );
        } else {
            $this->addFlash(
                'warning',
                sprintf(
                    'Le produit %s n’avait plus de stock. Il a simplement été archivé.',
                    $product->getReference()
                )
            );
        }

        return $this->redirectToRoute('admin_product_index');
    }

    #[Route('/admin/products/{id}/edit', name: 'admin_product_edit')]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(
        Product $product,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $form = $this->createForm(ProductEditForm::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $product->setUpdatedAt(new \DateTime());
            $em->flush();

            $this->addFlash(
                'success',
                sprintf('Produit %s mis à jour.', $product->getReference())
            );

            return $this->redirectToRoute('admin_product_index');
        }

        return $this->render('admin/product/edit.html.twig', [
            'page_title' => sprintf('Modifier le produit %s', $product->getReference()),
            'product'    => $product,
            'form'       => $form->createView(),
        ]);
    }
}
