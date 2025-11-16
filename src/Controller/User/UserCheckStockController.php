<?php

namespace App\Controller\User;

use App\Entity\Product;
use App\Entity\Reservation;
use App\Entity\TransferRequest;
use App\Repository\ProductRepository;
use App\Repository\StockRepository;
use App\Repository\SizeRepository;
use App\Repository\LocationRepository;
use App\Service\CurrentLocationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class UserCheckStockController extends AbstractController
{
    #[Route('/user/check-stock', name: 'user_check_stock')]
    public function index(
        Request $request,
        CurrentLocationService $currentLocationService,
        ProductRepository $productRepository,
        StockRepository $stockRepository
    ): Response {
        $currentLocation = $currentLocationService->getLocation();

        if (!$currentLocation) {
            $this->addFlash('warning', 'Veuillez choisir un magasin avant de consulter les stocks.');
            return $this->redirectToRoute('user_select_location');
        }

        // On accepte ?reference=... en GET et le champ "reference" en POST
        $reference = trim((string) ($request->query->get('reference') ?? $request->request->get('reference', '')));

        $product = null;
        $resultsByLocation = [];

        if ($reference !== '') {
            $product = $productRepository->findOneBy(['reference' => $reference]);

            if ($product instanceof Product) {
                // On récupère les stocks pour ce produit, groupés par magasin + taille
                $qb = $stockRepository->createQueryBuilder('s')
                    ->join('s.location', 'l')
                    ->join('s.size', 'size')
                    ->addSelect('l', 'size')
                    ->andWhere('s.product = :product')
                    ->setParameter('product', $product)
                    ->orderBy('l.name', 'ASC')
                    ->addOrderBy('size.name', 'ASC');

                $stocks = $qb->getQuery()->getResult();

                $grouped = [];

                foreach ($stocks as $stock) {
                    $location = $stock->getLocation();
                    $size     = $stock->getSize();

                    if (!$location || !$size) {
                        continue;
                    }

                    $locId = $location->getId();

                    if (!isset($grouped[$locId])) {
                        $grouped[$locId] = [
                            'location_id'   => $locId,
                            'location_name' => $location->getName(),
                            'location_code' => $location->getCode(),
                            'rows'          => [],
                        ];
                    }

                    $grouped[$locId]['rows'][] = [
                        'size_id'   => $size->getId(),
                        'size_name' => $size->getName(),
                        'quantity'  => $stock->getQuantity(),
                    ];
                }

                $resultsByLocation = array_values($grouped);
            }
        }

        return $this->render('user/check_stock.html.twig', [
            'page_title'          => 'Consultation du stock',
            'current_location'    => $currentLocation,
            'searched_reference'  => $reference,
            'product'             => $product,
            'results_by_location' => $resultsByLocation,
        ]);
    }

    #[Route('/user/check-stock/reservation', name: 'user_check_stock_reservation', methods: ['POST'])]
    public function createReservation(
        Request $request,
        CurrentLocationService $currentLocationService,
        ProductRepository $productRepository,
        SizeRepository $sizeRepository,
        LocationRepository $locationRepository,
        EntityManagerInterface $em
    ): Response {
        $currentLocation = $currentLocationService->getLocation();

        if (!$currentLocation) {
            $this->addFlash('warning', 'Veuillez choisir un magasin avant de créer une réservation.');
            return $this->redirectToRoute('user_select_location');
        }

        $reference   = (string) $request->request->get('reference', '');
        $productId   = (int) $request->request->get('product_id', 0);
        $sizeId      = (int) $request->request->get('size_id', 0);
        $locationId  = (int) $request->request->get('location_id', 0);

        $product  = $productRepository->find($productId);
        $size     = $sizeRepository->find($sizeId);
        $location = $locationRepository->find($locationId);

        if (!$product || !$size || !$location) {
            $this->addFlash('danger', 'Impossible de créer la réservation (données manquantes ou invalides).');
            return $this->redirectToRoute('user_check_stock', ['reference' => $reference]);
        }

        $reservation = new Reservation();
        $reservation
            ->setProduct($product)
            ->setSize($size)
            ->setLocation($location)                 // là où le produit est physiquement
            ->setRequestedByLocation($currentLocation) // magasin du client
            ->setQuantity(1)
            ->setCreatedBy($this->getUser());

        $em->persist($reservation);
        $em->flush();

        $this->addFlash(
            'success',
            sprintf(
                'Réservation créée dans le magasin %s pour la référence %s (taille %s).',
                $location->getName(),
                $product->getReference(),
                $size->getName()
            )
        );

        return $this->redirectToRoute('user_check_stock', ['reference' => $reference]);
    }

    #[Route('/user/check-stock/transfer-request', name: 'user_check_stock_transfer_request', methods: ['POST'])]
    public function createTransferRequest(
        Request $request,
        CurrentLocationService $currentLocationService,
        ProductRepository $productRepository,
        SizeRepository $sizeRepository,
        LocationRepository $locationRepository,
        EntityManagerInterface $em
    ): Response {
        $currentLocation = $currentLocationService->getLocation();

        if (!$currentLocation) {
            $this->addFlash('warning', 'Veuillez choisir un magasin avant de demander un transfert.');
            return $this->redirectToRoute('user_select_location');
        }

        $reference   = (string) $request->request->get('reference', '');
        $productId   = (int) $request->request->get('product_id', 0);
        $sizeId      = (int) $request->request->get('size_id', 0);
        $locationId  = (int) $request->request->get('location_id', 0); // magasin source (où il y a du stock)

        $product     = $productRepository->find($productId);
        $size        = $sizeRepository->find($sizeId);
        $fromLocation = $locationRepository->find($locationId);

        if (!$product || !$size || !$fromLocation) {
            $this->addFlash('danger', 'Impossible de créer la demande de transfert (données manquantes ou invalides).');
            return $this->redirectToRoute('user_check_stock', ['reference' => $reference]);
        }

        $transfer = new TransferRequest();
        $transfer
            ->setProduct($product)
            ->setSize($size)
            ->setFromLocation($fromLocation)
            ->setToLocation($currentLocation)
            ->setQuantity(1)
            ->setCreatedBy($this->getUser());

        $em->persist($transfer);
        $em->flush();

        $this->addFlash(
            'success',
            sprintf(
                'Demande de transfert créée depuis %s vers %s pour la référence %s (taille %s).',
                $fromLocation->getName(),
                $currentLocation->getName(),
                $product->getReference(),
                $size->getName()
            )
        );

        return $this->redirectToRoute('user_check_stock', ['reference' => $reference]);
    }
}
