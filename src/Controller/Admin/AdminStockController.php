<?php

namespace App\Controller\Admin;

use App\Entity\StockMovement;
use App\Repository\StockRepository;
use App\Repository\SupplierRepository;
use App\Repository\SeasonRepository;
use App\Repository\CategoryRepository;
use App\Repository\LocationRepository;
use App\Repository\StockMovementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminStockController extends AbstractController
{
    #[Route('/admin/stock', name: 'admin_stock_index')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(
        Request $request,
        StockRepository $stockRepository,
        SupplierRepository $supplierRepository,
        SeasonRepository $seasonRepository,
        CategoryRepository $categoryRepository,
        LocationRepository $locationRepository,
        StockMovementRepository $stockMovementRepository
    ): Response {
        // Filtres
        $supplierRaw = $request->query->get('supplier');
        $seasonRaw   = $request->query->get('season');
        $categoryRaw = $request->query->get('category');
        $search      = trim((string) $request->query->get('q', ''));

        $supplierId = ctype_digit((string) $supplierRaw) ? (int) $supplierRaw : null;
        $seasonId   = ctype_digit((string) $seasonRaw) ? (int) $seasonRaw : null;
        $categoryId = ctype_digit((string) $categoryRaw) ? (int) $categoryRaw : null;

        // Récupération des lignes de stock avec produit + magasin + taille
        $qb = $stockRepository->createQueryBuilder('st')
            ->join('st.product', 'p')->addSelect('p')
            ->join('st.location', 'loc')->addSelect('loc')
            ->leftJoin('st.size', 'sz')->addSelect('sz')
            ->leftJoin('p.supplier', 's')->addSelect('s')
            ->leftJoin('p.season', 'se')->addSelect('se')
            ->leftJoin('p.category', 'c')->addSelect('c')
            ->leftJoin('p.color', 'co')->addSelect('co')
            ->andWhere('p.isArchived = :archived')->setParameter('archived', false);

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

        $qb
            ->orderBy('s.name', 'ASC')
            ->addOrderBy('p.reference', 'ASC')
            ->addOrderBy('sz.name', 'ASC');

        $stocks = $qb->getQuery()->getResult();

        // Liste des magasins → colonnes dynamiques
        $locations = $locationRepository->findBy([], ['code' => 'ASC']);

        /**
         * Agrégation par PRODUIT + TAILLE
         * - key = productId_sizeId
         * - perLocation[code] = quantité
         * - stocks[code] = entité Stock pour ce magasin
         * - total = somme sur tous les magasins pour cette taille
         */
        $rows = [];

        foreach ($stocks as $stock) {
            $product  = $stock->getProduct();
            $location = $stock->getLocation();
            $size     = $stock->getSize();

            if (!$product || !$location) {
                continue;
            }

            $productId = $product->getId();
            $sizeId    = $size ? $size->getId() : 0;
            $rowKey    = $productId . '_' . $sizeId;
            $locCode   = $location->getCode();
            $qty       = $stock->getQuantity() ?? 0;

            if (!isset($rows[$rowKey])) {
                $rows[$rowKey] = [
                    'product'     => $product,
                    'size'        => $size,
                    'perLocation' => [],
                    'stocks'      => [],
                    'total'       => 0,
                ];

                // Init magasins
                foreach ($locations as $loc) {
                    $rows[$rowKey]['perLocation'][$loc->getCode()] = 0;
                    $rows[$rowKey]['stocks'][$loc->getCode()]      = null;
                }
            }

            $rows[$rowKey]['perLocation'][$locCode] += $qty;
            $rows[$rowKey]['stocks'][$locCode]      = $stock;
            $rows[$rowKey]['total']                += $qty;
        }

        // Ventes sur les 30 derniers jours (toutes tailles confondues)
        $since = new \DateTimeImmutable('-30 days');

        $qbSales = $stockMovementRepository->createQueryBuilder('m')
            ->select('IDENTITY(m.product) AS productId, SUM(m.quantity) AS qtySold')
            ->andWhere('m.type = :type')
            ->andWhere('m.createdAt >= :since')
            ->setParameter('type', StockMovement::TYPE_SALE)
            ->setParameter('since', $since)
            ->groupBy('m.product');

        $salesResults = $qbSales->getQuery()->getResult();

        $sales30d = [];
        foreach ($salesResults as $rowSales) {
            $pid = (int) $rowSales['productId'];
            $sales30d[$pid] = (int) $rowSales['qtySold'];
        }

        // Listes pour filtres
        $suppliers  = $supplierRepository->findBy(['isActive' => true], ['name' => 'ASC']);
        $seasons    = $seasonRepository->findBy(['isActive' => true], ['year' => 'DESC', 'name' => 'ASC']);
        $categories = $categoryRepository->findBy(['isActive' => true], ['name' => 'ASC']);

        return $this->render('admin/stock/index.html.twig', [
            'page_title' => 'Stock global',
            'rows'       => $rows,
            'locations'  => $locations,
            'suppliers'  => $suppliers,
            'seasons'    => $seasons,
            'categories' => $categories,
            'sales30d'   => $sales30d,
            'filters'    => [
                'supplier' => $supplierId,
                'season'   => $seasonId,
                'category' => $categoryId,
                'q'        => $search,
            ],
        ]);
    }

    #[Route('/admin/stock/decrement', name: 'admin_stock_decrement', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function decrement(
        Request $request,
        StockRepository $stockRepository,
        EntityManagerInterface $em
    ): Response {
        $stockId  = $request->request->get('stock_id');
        $quantity = (int) $request->request->get('quantity', 1);
        $reason   = (string) $request->request->get('reason', '');

        if (!$stockId || $quantity <= 0) {
            $this->addFlash('warning', 'Veuillez sélectionner une ligne de stock et une quantité valide.');
            return $this->redirectToRoute('admin_stock_index');
        }

        $stock = $stockRepository->find($stockId);
        if (!$stock) {
            $this->addFlash('danger', 'Ligne de stock introuvable.');
            return $this->redirectToRoute('admin_stock_index');
        }

        $currentQty = $stock->getQuantity() ?? 0;
        if ($quantity > $currentQty) {
            $this->addFlash(
                'danger',
                sprintf(
                    'Impossible de retirer %d, le stock disponible est de %d.',
                    $quantity,
                    $currentQty
                )
            );
            return $this->redirectToRoute('admin_stock_index');
        }

        // Décrément du stock
        $stock->setQuantity($currentQty - $quantity);

        // Création du mouvement d'ajustement
        $movement = new StockMovement();
        $movement
            ->setProduct($stock->getProduct())
            ->setSize($stock->getSize())
            ->setLocation($stock->getLocation())
            ->setType(StockMovement::TYPE_MANUAL_DECREMENT)
            ->setQuantity($quantity)
            ->setComment(
                $reason !== ''
                    ? $reason
                    : 'Ajustement manuel (décrément admin)'
            );

        $user = $this->getUser();
        if ($user) {
            $movement->setUser($user);
        }

        $em->persist($movement);
        $em->flush();

        $this->addFlash(
            'success',
            sprintf(
                'Stock décrémenté de %d pour %s (%s) au magasin %s.',
                $quantity,
                $stock->getProduct()->getReference(),
                $stock->getSize()?->getName() ?? 'Taille inconnue',
                $stock->getLocation()->getName()
            )
        );

        return $this->redirectToRoute('admin_stock_index');
    }
}
