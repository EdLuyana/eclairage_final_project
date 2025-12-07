<?php

namespace App\Controller\Admin;

use App\Repository\StockMovementRepository;
use App\Repository\LocationRepository;
use App\Repository\UserRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminSalesController extends AbstractController
{
    #[Route('/admin/sales', name: 'admin_sales_index')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(
        Request $request,
        StockMovementRepository $stockMovementRepository,
        LocationRepository $locationRepository,
        UserRepository $userRepository
    ): Response {
        [
            $movements,
            $label,
            $totalLines,
            $totalQuantity,
            $totalAmountGross,
            $totalAmountNetRaw,
            $totalVoucherAmount,
            $totalAmountNet,
            $period,
            $locationId,
            $userId,
            $now,
        ] = $this->getFilteredSales($request, $stockMovementRepository, $locationRepository, $userRepository);

        $locations = $locationRepository->findBy([], ['code' => 'ASC']);
        $users     = $userRepository->findBy([], ['username' => 'ASC']);

        return $this->render('admin/sales/index.html.twig', [
            'page_title'         => 'Journal des ventes',
            'movements'          => $movements,
            'locations'          => $locations,
            'users'              => $users,
            'filters'            => [
                'period'   => $period,
                'location' => $locationId,
                'user'     => $userId,
            ],
            'period_label'       => $label,
            'total_lines'        => $totalLines,
            'total_quantity'     => $totalQuantity,

            // CA net réellement encaissé (après remises ET bons d'achat)
            'total_amount'       => $totalAmountNet,

            // Pour affichage détaillé
            'total_amount_raw'   => $totalAmountNetRaw,   // net après remises, avant bons d'achat
            'total_amount_gross' => $totalAmountGross,    // brut avant remises
            'total_voucher'      => $totalVoucherAmount,  // total des bons d'achat utilisés

            'now'                => $now,
        ]);
    }

    #[Route('/admin/sales/export-pdf', name: 'admin_sales_export_pdf')]
    #[IsGranted('ROLE_ADMIN')]
    public function exportPdf(
        Request $request,
        StockMovementRepository $stockMovementRepository,
        LocationRepository $locationRepository,
        UserRepository $userRepository
    ): Response {
        [
            $movements,
            $label,
            $totalLines,
            $totalQuantity,
            $totalAmountGross,
            $totalAmountNetRaw,
            $totalVoucherAmount,
            $totalAmountNet,
            $period,
            $locationId,
            $userId,
            $now,
        ] = $this->getFilteredSales($request, $stockMovementRepository, $locationRepository, $userRepository);

        // Préparation du HTML via Twig
        $html = $this->renderView('admin/sales/export.pdf.twig', [
            'page_title'         => 'Journal des ventes',
            'movements'          => $movements,
            'period_label'       => $label,
            'total_lines'        => $totalLines,
            'total_quantity'     => $totalQuantity,

            // CA net réellement encaissé (après remises et bons d'achat)
            'total_amount'       => $totalAmountNet,
            'total_amount_gross' => $totalAmountGross,
            'total_voucher'      => $totalVoucherAmount,
            'now'                => $now,
        ]);

        // Configuration Dompdf
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans'); // gère bien l’UTF-8
        $options->setIsRemoteEnabled(true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf('journal_ventes_%s.pdf', (new \DateTime())->format('Ymd_His'));

        return new Response(
            $dompdf->output(),
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    /**
     * Retourne :
     * [movements, label, totalLines, totalQuantity,
     *  totalAmountGross, totalAmountNetRaw, totalVoucherAmount, totalAmountNet,
     *  period, locationId, userId, now]
     */
    private function getFilteredSales(
        Request $request,
        StockMovementRepository $stockMovementRepository,
        LocationRepository $locationRepository,
        UserRepository $userRepository
    ): array {
        $period      = $request->query->get('period', 'month'); // 24h / 7d / 1m / month / year / all
        $locationRaw = $request->query->get('location');
        $userRaw     = $request->query->get('user');

        $locationId = ctype_digit((string) $locationRaw) ? (int) $locationRaw : null;
        $userId     = ctype_digit((string) $userRaw) ? (int) $userRaw : null;

        $now   = new \DateTimeImmutable('now');
        $from  = null;
        $label = '';

        switch ($period) {
            case '24h':
                $from  = $now->sub(new \DateInterval('P1D'));
                $label = '24 dernières heures';
                break;
            case '7d':
                $from  = $now->sub(new \DateInterval('P7D'));
                $label = '7 derniers jours';
                break;
            case '1m':
                $from  = $now->sub(new \DateInterval('P1M'));
                $label = '30 derniers jours';
                break;
            case 'month':
                $from  = $now->modify('first day of this month')->setTime(0, 0, 0);
                $label = 'Mois en cours';
                break;
            case 'year':
                $from  = $now->modify('first day of January ' . $now->format('Y'))->setTime(0, 0, 0);
                $label = 'Année en cours';
                break;
            case 'all':
            default:
                $from  = null;
                $label = 'Toutes les ventes';
                break;
        }

        $qb = $stockMovementRepository->createQueryBuilder('m')
            ->join('m.product', 'p')->addSelect('p')
            ->leftJoin('m.location', 'loc')->addSelect('loc')
            ->leftJoin('m.user', 'u')->addSelect('u')
            ->leftJoin('m.size', 's')->addSelect('s')
            ->andWhere('m.type = :type')
            ->setParameter('type', 'SALE');

        if ($from !== null) {
            $qb->andWhere('m.createdAt >= :from')
               ->setParameter('from', $from);
        }

        if ($locationId !== null) {
            $qb->andWhere('loc.id = :locId')->setParameter('locId', $locationId);
        }

        if ($userId !== null) {
            $qb->andWhere('u.id = :userId')->setParameter('userId', $userId);
        }

        $qb->orderBy('m.createdAt', 'DESC');

        $movements = $qb->getQuery()->getResult();

        $totalLines        = 0;
        $totalQuantity     = 0;
        $totalAmountGross  = 0.0; // avant remises
        $totalAmountNetRaw = 0.0; // après remises (articles + panier), avant bons d'achat
        $totalVoucherAmount = 0.0; // total des bons d'achat utilisés
        $voucherKeys       = [];  // pour ne pas compter un bon plusieurs fois

        /** @var \App\Entity\StockMovement $movement */
        foreach ($movements as $movement) {
            $qty = $movement->getQuantity() ?? 0;

            // Brut (avant remise)
            $original = $movement->getOriginalPrice();
            // Final (après remise)
            $final    = $movement->getFinalPrice();

            // Fallback pour les anciennes ventes : calcule à partir du prix produit
            $product = $movement->getProduct();
            if ($product && $product->getPrice() !== null) {
                $unitPrice = (float) $product->getPrice();
                $fallback  = $unitPrice * $qty;

                if ($original === null) {
                    $original = $fallback;
                }
                if ($final === null) {
                    $final = $fallback;
                }
            } else {
                // Si pas de produit ou pas de prix, on cast quand même les valeurs éventuelles
                if ($original === null) {
                    $original = 0.0;
                }
                if ($final === null) {
                    $final = $original;
                }
            }

            $originalFloat = (float) $original;
            $finalFloat    = (float) $final;

            $totalLines++;
            $totalQuantity     += $qty;
            $totalAmountGross  += $originalFloat;
            $totalAmountNetRaw += $finalFloat;

            // Tentative de récupération du montant de bon d'achat utilisé
            // à partir du commentaire StockMovement (si présent).
            $comment = (string) $movement->getComment();
            if ($comment !== '') {
                if (preg_match("/Bon d'achat utilisé:\\s*(\\d+)/", $comment, $matches)) {
                    $voucherValue = (float) $matches[1];

                    if ($voucherValue > 0) {
                        // On identifie une "vente" par un triplet (date, user, location)
                        $createdAt = $movement->getCreatedAt();
                        $user      = $movement->getUser();
                        $location  = $movement->getLocation();

                        $saleKeyParts = [
                            $createdAt ? $createdAt->format('Y-m-d H:i:s') : '0',
                            $user ? (string) $user->getId() : '0',
                            $location ? (string) $location->getId() : '0',
                        ];
                        $saleKey = implode('#', $saleKeyParts);

                        // On ne compte le bon d'achat qu'une seule fois par vente
                        if (!array_key_exists($saleKey, $voucherKeys)) {
                            $voucherKeys[$saleKey] = true;
                            $totalVoucherAmount   += $voucherValue;
                        }
                    }
                }
            }
        }

        // CA net réellement encaissé = total des prix finaux - bons d'achat
        $totalAmountNet = max(0.0, $totalAmountNetRaw - $totalVoucherAmount);

        return [
            $movements,
            $label,
            $totalLines,
            $totalQuantity,
            $totalAmountGross,
            $totalAmountNetRaw,
            $totalVoucherAmount,
            $totalAmountNet,
            $period,
            $locationId,
            $userId,
            $now,
        ];
    }
}
