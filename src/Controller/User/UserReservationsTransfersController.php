<?php

namespace App\Controller\User;

use App\Entity\Reservation;
use App\Entity\TransferRequest;
use App\Entity\Stock;
use App\Entity\StockMovement;
use App\Repository\ReservationRepository;
use App\Repository\TransferRequestRepository;
use App\Repository\StockRepository;
use App\Service\CurrentLocationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class UserReservationsTransfersController extends AbstractController
{
    #[Route('/user/reservations-transfers', name: 'user_reservations_transfers')]
    public function index(
        CurrentLocationService $currentLocationService,
        ReservationRepository $reservationRepository,
        TransferRequestRepository $transferRequestRepository
    ): Response {
        $location = $currentLocationService->getLocation();

        if (!$location) {
            $this->addFlash('warning', 'Aucun magasin sélectionné.');
            return $this->redirectToRoute('user_select_location');
        }

        $reservations = $reservationRepository->findOpenForLocation($location->getId());
        $transferRequests = $transferRequestRepository->findOutgoingForLocation($location->getId());

        return $this->render('user/reservations_transfers.html.twig', [
            'page_title'        => 'Réservations & transferts',
            'current_location'  => $location,
            'reservations'      => $reservations,
            'transfer_requests' => $transferRequests,
        ]);
    }

    #[Route(
        '/user/reservations-transfers/reservation/{id}/confirm',
        name: 'user_reservation_confirm',
        methods: ['POST']
    )]
    public function confirmReservation(
        int $id,
        CurrentLocationService $currentLocationService,
        ReservationRepository $reservationRepository,
        EntityManagerInterface $em
    ): Response {
        $location = $currentLocationService->getLocation();

        if (!$location) {
            $this->addFlash('warning', 'Aucun magasin sélectionné.');
            return $this->redirectToRoute('user_select_location');
        }

        $reservation = $reservationRepository->find($id);

        if (!$reservation || $reservation->getLocation()?->getId() !== $location->getId()) {
            $this->addFlash('danger', 'Réservation introuvable pour ce magasin.');
            return $this->redirectToRoute('user_reservations_transfers');
        }

        if ($reservation->getStatus() !== Reservation::STATUS_PENDING) {
            $this->addFlash('info', 'Cette réservation a déjà été traitée.');
            return $this->redirectToRoute('user_reservations_transfers');
        }

        $reservation->setStatus(Reservation::STATUS_CONFIRMED);
        $em->flush();

        $this->addFlash('success', 'Réservation marquée comme “produit mis de côté”.');

        return $this->redirectToRoute('user_reservations_transfers');
    }

    #[Route(
        '/user/reservations-transfers/reservation/{id}/cancel',
        name: 'user_reservation_cancel',
        methods: ['POST']
    )]
    public function cancelReservation(
        int $id,
        CurrentLocationService $currentLocationService,
        ReservationRepository $reservationRepository,
        EntityManagerInterface $em
    ): Response {
        $location = $currentLocationService->getLocation();

        if (!$location) {
            $this->addFlash('warning', 'Aucun magasin sélectionné.');
            return $this->redirectToRoute('user_select_location');
        }

        $reservation = $reservationRepository->find($id);

        if (!$reservation || $reservation->getLocation()?->getId() !== $location->getId()) {
            $this->addFlash('danger', 'Réservation introuvable pour ce magasin.');
            return $this->redirectToRoute('user_reservations_transfers');
        }

        if (in_array($reservation->getStatus(), [
            Reservation::STATUS_COMPLETED,
            Reservation::STATUS_CANCELLED,
        ], true)) {
            $this->addFlash('info', 'Cette réservation est déjà clôturée.');
            return $this->redirectToRoute('user_reservations_transfers');
        }

        $reservation->setStatus(Reservation::STATUS_CANCELLED);
        $em->flush();

        $this->addFlash('success', 'Réservation annulée.');

        return $this->redirectToRoute('user_reservations_transfers');
    }

    #[Route(
        '/user/reservations-transfers/transfer/{id}/prepare',
        name: 'user_transfer_prepare',
        methods: ['POST']
    )]
    public function prepareTransfer(
        int $id,
        CurrentLocationService $currentLocationService,
        TransferRequestRepository $transferRequestRepository,
        StockRepository $stockRepository,
        EntityManagerInterface $em
    ): Response {
        $location = $currentLocationService->getLocation();

        if (!$location) {
            $this->addFlash('warning', 'Aucun magasin sélectionné.');
            return $this->redirectToRoute('user_select_location');
        }

        $transfer = $transferRequestRepository->find($id);

        if (
            !$transfer ||
            $transfer->getFromLocation()?->getId() !== $location->getId()
        ) {
            $this->addFlash('danger', 'Demande de transfert introuvable pour ce magasin.');
            return $this->redirectToRoute('user_reservations_transfers');
        }

        if ($transfer->getStatus() !== TransferRequest::STATUS_REQUESTED) {
            $this->addFlash('info', 'Ce transfert est déjà préparé ou clôturé.');
            return $this->redirectToRoute('user_reservations_transfers');
        }

        $product   = $transfer->getProduct();
        $size      = $transfer->getSize();
        $quantity  = $transfer->getQuantity();
        $user      = $this->getUser();

        if (!$product || !$size) {
            $this->addFlash('danger', 'Produit ou taille manquant pour ce transfert.');
            return $this->redirectToRoute('user_reservations_transfers');
        }

        /** @var Stock|null $stock */
        $stock = $stockRepository->findOneBy([
            'product'  => $product,
            'size'     => $size,
            'location' => $location,
        ]);

        if (!$stock || $stock->getQuantity() < $quantity) {
            $this->addFlash('danger', 'Stock insuffisant pour préparer ce transfert.');
            return $this->redirectToRoute('user_reservations_transfers');
        }

        // On décrémente le stock du magasin source
        $stock->setQuantity($stock->getQuantity() - $quantity);

        // On crée un mouvement de type TRANSFER_OUT
        $movement = new StockMovement();
        $movement
            ->setType('TRANSFER_OUT')
            ->setProduct($product)
            ->setSize($size)
            ->setLocation($location)
            ->setUser($user)
            ->setQuantity($quantity)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setComment(sprintf(
                'Préparation transfert vers %s.',
                $transfer->getToLocation()?->getName() ?? 'magasin inconnu'
            ));

        $transfer->setStatus(TransferRequest::STATUS_PREPARED);

        $em->persist($stock);
        $em->persist($movement);
        $em->persist($transfer);
        $em->flush();

        $this->addFlash('success', 'Transfert préparé : produit mis de côté et stock décrémenté.');

        return $this->redirectToRoute('user_reservations_transfers');
    }

    #[Route(
        '/user/reservations-transfers/transfer/{id}/cancel',
        name: 'user_transfer_cancel',
        methods: ['POST']
    )]
    public function cancelTransfer(
        int $id,
        CurrentLocationService $currentLocationService,
        TransferRequestRepository $transferRequestRepository,
        EntityManagerInterface $em
    ): Response {
        $location = $currentLocationService->getLocation();

        if (!$location) {
            $this->addFlash('warning', 'Aucun magasin sélectionné.');
            return $this->redirectToRoute('user_select_location');
        }

        $transfer = $transferRequestRepository->find($id);

        if (
            !$transfer ||
            $transfer->getFromLocation()?->getId() !== $location->getId()
        ) {
            $this->addFlash('danger', 'Demande de transfert introuvable pour ce magasin.');
            return $this->redirectToRoute('user_reservations_transfers');
        }

        if ($transfer->getStatus() !== TransferRequest::STATUS_REQUESTED) {
            $this->addFlash('info', 'Ce transfert ne peut plus être annulé.');
            return $this->redirectToRoute('user_reservations_transfers');
        }

        $transfer->setStatus(TransferRequest::STATUS_CANCELLED);
        $em->flush();

        $this->addFlash('success', 'Demande de transfert annulée.');

        return $this->redirectToRoute('user_reservations_transfers');
    }
}
