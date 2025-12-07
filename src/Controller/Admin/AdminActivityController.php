<?php

namespace App\Controller\Admin;

use App\Repository\LocationRepository;
use App\Repository\StockMovementRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/activity', name: 'admin_activity_')]
class AdminActivityController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        StockMovementRepository $stockMovementRepository,
        UserRepository $userRepository,
        LocationRepository $locationRepository,
    ): Response {
        // --- 1. Filtres depuis la query string ---
        $period     = $request->query->get('period', '7d');   // 24h, 7d, 1m, all
        $userId     = $request->query->get('user');           // id user ou null
        $locationId = $request->query->get('location');       // id magasin ou null
        $type       = $request->query->get('type');           // type de mouvement ou null

        // --- 2. Construction du QueryBuilder ---
        $qb = $stockMovementRepository->createQueryBuilder('sm')
            ->leftJoin('sm.user', 'u')->addSelect('u')
            ->leftJoin('sm.location', 'l')->addSelect('l')
            ->leftJoin('sm.product', 'p')->addSelect('p')
            ->leftJoin('sm.size', 's')->addSelect('s')
            ->orderBy('sm.createdAt', 'DESC');

        // Filtre période
        $now = new \DateTimeImmutable('now');
        switch ($period) {
            case '24h':
                $from = $now->sub(new \DateInterval('P1D'));
                break;
            case '7d':
                $from = $now->sub(new \DateInterval('P7D'));
                break;
            case '1m':
                $from = $now->sub(new \DateInterval('P1M'));
                break;
            case 'all':
            default:
                $from = null;
                break;
        }

        if ($from !== null) {
            $qb->andWhere('sm.createdAt >= :from')
               ->setParameter('from', $from);
        }

        // Filtre utilisateur
        if (!empty($userId)) {
            $qb->andWhere('u.id = :uid')
               ->setParameter('uid', (int) $userId);
        }

        // Filtre magasin
        if (!empty($locationId)) {
            $qb->andWhere('l.id = :lid')
               ->setParameter('lid', (int) $locationId);
        }

        // Filtre type
        if (!empty($type)) {
            $qb->andWhere('sm.type = :type')
               ->setParameter('type', $type);
        }

        $movements = $qb->getQuery()->getResult();

        // --- 3. Totaux entrées / sorties ---
        $totalIn  = 0;
        $totalOut = 0;

        foreach ($movements as $movement) {
            $qty = $movement->getQuantity(); // hypothèse : getQuantity() existe

            if ($qty > 0) {
                $totalIn += $qty;
            } elseif ($qty < 0) {
                $totalOut += abs($qty);
            }
        }

        // --- 4. Listes pour les filtres (users / locations / types) ---
        $users     = $userRepository->findBy([], ['username' => 'ASC']);
        $locations = $locationRepository->findBy([], ['name' => 'ASC']);

        // On récupère tous les types distincts existants en BDD
        $rawTypes = $stockMovementRepository->createQueryBuilder('sm2')
            ->select('DISTINCT sm2.type')
            ->orderBy('sm2.type', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        $types = [];
        foreach ($rawTypes as $t) {
            if ($t !== null && $t !== '') {
                $types[] = $t;
            }
        }

        // --- 5. Libellés FR + classes de badge pour les types ---
        $typeLabels = [
            'ADD'         => 'Ajout',
            'REMOVE'      => 'Retrait',
            'RETURN'      => 'Retour client',
            'SALE'        => 'Vente',
            'TRANSFER'    => 'Transfert',
            'RESERVATION' => 'Réservation',
            'ADJUST'      => 'Ajustement',
        ];

        $typeBadgeClasses = [
            'ADD'         => 'bg-success',
            'REMOVE'      => 'bg-danger',
            'RETURN'      => 'bg-primary',
            'SALE'        => 'bg-warning',
            'TRANSFER'    => 'bg-info',
            'RESERVATION' => 'bg-secondary',
            'ADJUST'      => 'bg-secondary',
        ];

        return $this->render('admin/activity/index.html.twig', [
            'page_title'          => 'Journal d’activité',
            'movements'           => $movements,
            'users'               => $users,
            'locations'           => $locations,
            'types'               => $types,
            'current_period'      => $period,
            'current_user_id'     => $userId,
            'current_location_id' => $locationId,
            'current_type'        => $type,
            'total_in'            => $totalIn,
            'total_out'           => $totalOut,
            'typeLabels'          => $typeLabels,
            'typeBadgeClasses'    => $typeBadgeClasses,
        ]);
    }
}
