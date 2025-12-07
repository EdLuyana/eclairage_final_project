<?php

namespace App\Controller\User;

use App\Entity\Product;
use App\Entity\Size;
use App\Entity\Stock;
use App\Entity\StockMovement;
use App\Repository\SaleModeRepository;
use App\Service\CurrentLocationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class UserSellController extends AbstractController
{
    public const CART_SESSION_KEY        = 'sell_cart';
    /**
     * Clé de session pour la remise panier (0 ou 10 %).
     */
    public const CART_BASKET_DISCOUNT_KEY = 'sell_cart_discount';
    /**
     * Clé de session pour le montant payé via bon d'achat (en euros entiers).
     */
    public const CART_VOUCHER_KEY        = 'sell_cart_voucher';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentLocationService $currentLocationService,
        private readonly SaleModeRepository $saleModeRepository,
    ) {
    }

    /**
     * Affichage de la page de vente + panier actuel.
     */
    #[Route('/user/sell', name: 'user_sell', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $location = $this->currentLocationService->getLocation();
        if (!$location) {
            return $this->redirectToRoute('user_select_location');
        }

        $session = $request->getSession();
        $cart    = $session->get(self::CART_SESSION_KEY, []);

        // Lecture de la remise panier (héritage possible d'une ancienne version booléenne)
        $rawBasketDiscount = $session->get(self::CART_BASKET_DISCOUNT_KEY, 0);
        if ($rawBasketDiscount === true) {
            $rawBasketDiscount = 10;
        }
        $basketDiscountPercent = (int) $rawBasketDiscount;
        // Dans notre logique : seulement 0 ou 10 %
        if ($basketDiscountPercent !== 10) {
            $basketDiscountPercent = 0;
        }

        // Bon d'achat
        if (empty($cart)) {
            // Panier vide = pas de bon d'achat
            $session->remove(self::CART_VOUCHER_KEY);
            $voucherAmount = 0;
        } else {
            $voucherAmount = (int) $session->get(self::CART_VOUCHER_KEY, 0);
        }

        // SaleMode (pour savoir si les remises par article sont actives)
        $saleMode       = $this->saleModeRepository->find(1);
        $saleModeActive = $saleMode?->isDiscountEnabled() ?? false;

        // Totaux prenant en compte :
        // - remises par article (si SaleMode actif)
        // - remise panier (0 ou 10 %)
        $totals      = $this->calculateTotals($cart, $basketDiscountPercent, $saleModeActive);
        $basketTotal = $totals['total_after_discount'];

        // On s'assure que le bon d'achat ne dépasse pas le total panier
        if ($voucherAmount < 0) {
            $voucherAmount = 0;
        }
        if ($voucherAmount > $basketTotal) {
            $voucherAmount = (int) $basketTotal;
        }

        $cashAmount = max(0.0, $basketTotal - (float) $voucherAmount);

        // On remet en session la valeur "nettoyée" du bon d'achat
        $session->set(self::CART_VOUCHER_KEY, $voucherAmount);

        return $this->render('user/sell.html.twig', [
            'page_title'             => 'Vente',
            'current_location'       => $location,
            'cart'                   => $cart,
            'totals'                 => $totals,
            'voucher_amount'         => $voucherAmount,
            'cash_amount'            => $cashAmount,
            'sale_mode_enabled'      => $saleModeActive,
            'basket_discount_percent'=> $basketDiscountPercent,
        ]);
    }

    /**
     * Endpoint JSON pour récupérer les tailles disponibles pour une référence dans le magasin courant.
     */
    #[Route('/user/sell/get-sizes', name: 'user_sell_get_sizes', methods: ['GET'])]
    public function getSizesForReference(Request $request): JsonResponse
    {
        $location = $this->currentLocationService->getLocation();

        if (!$location) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Aucun magasin sélectionné.',
            ], 400);
        }

        $reference = trim((string) $request->query->get('reference', ''));

        if ($reference === '') {
            return new JsonResponse([
                'success' => false,
                'message' => 'Référence manquante.',
            ], 400);
        }

        /** @var Product|null $product */
        $product = $this->em->getRepository(Product::class)
            ->findOneBy(['reference' => $reference]);

        if (!$product) {
            return new JsonResponse([
                'success' => false,
                'message' => sprintf('Aucun produit trouvé pour la référence "%s".', $reference),
            ], 404);
        }

        /** @var Stock[] $stocks */
        $stocks = $this->em->getRepository(Stock::class)->findBy([
            'product'  => $product,
            'location' => $location,
        ]);

        $sizes = [];
        foreach ($stocks as $stock) {
            if ($stock->getQuantity() <= 0) {
                continue;
            }

            $size = $stock->getSize();
            $sizes[] = [
                'id'       => $size->getId(),
                'name'     => $size->getName(),
                'quantity' => $stock->getQuantity(),
            ];
        }

        return new JsonResponse([
            'success'   => true,
            'reference' => $product->getReference(),
            'product'   => $product->getName(),
            'sizes'     => $sizes,
        ]);
    }

    /**
     * Ajout d'une ligne au panier.
     * On attend : reference, size_id, quantity.
     */
    #[Route('/user/sell/add-line', name: 'user_sell_add_line', methods: ['POST'])]
    public function addLine(Request $request): Response
    {
        $location = $this->currentLocationService->getLocation();

        if (!$location) {
            $this->addFlash('danger', 'Aucun magasin sélectionné.');
            return $this->redirectToRoute('user_select_location');
        }

        $reference = trim((string) $request->request->get('reference', ''));
        $sizeId    = (int) $request->request->get('size_id', 0);
        $quantity  = (int) $request->request->get('quantity', 1);

        if ($reference === '' || $sizeId <= 0) {
            $this->addFlash('danger', 'Référence ou taille manquante.');
            return $this->redirectToRoute('user_sell');
        }

        /** @var Product|null $product */
        $product = $this->em->getRepository(Product::class)
            ->findOneBy(['reference' => $reference]);

        if (!$product) {
            $this->addFlash('danger', sprintf(
                'Aucun produit trouvé pour la référence "%s".',
                $reference
            ));
            return $this->redirectToRoute('user_sell');
        }

        /** @var Size|null $size */
        $size = $this->em->getRepository(Size::class)->find($sizeId);

        if (!$size) {
            $this->addFlash('danger', 'Taille introuvable.');
            return $this->redirectToRoute('user_sell');
        }

        /** @var Stock|null $stock */
        $stock = $this->em->getRepository(Stock::class)->findOneBy([
            'product'  => $product,
            'size'     => $size,
            'location' => $location,
        ]);

        if (!$stock || $stock->getQuantity() <= 0) {
            $this->addFlash('danger', 'Aucun stock disponible pour cette taille dans ce magasin.');
            return $this->redirectToRoute('user_sell');
        }

        if ($quantity > $stock->getQuantity()) {
            $this->addFlash('danger', sprintf(
                'Stock insuffisant : il reste seulement %d en stock pour cette taille.',
                $stock->getQuantity()
            ));
            return $this->redirectToRoute('user_sell');
        }

        $session = $request->getSession();
        $cart    = $session->get(self::CART_SESSION_KEY, []);

        $lineKey = $product->getId() . '_' . $size->getId();

        if (isset($cart[$lineKey])) {
            $newQty = $cart[$lineKey]['quantity'] + $quantity;
            if ($newQty > $stock->getQuantity()) {
                $this->addFlash('danger', sprintf(
                    'Stock insuffisant : vous essayez d\'ajouter %d au total, mais il ne reste que %d.',
                    $newQty,
                    $stock->getQuantity()
                ));
                return $this->redirectToRoute('user_sell');
            }
            $cart[$lineKey]['quantity'] = $newQty;
        } else {
            $cart[$lineKey] = [
                'product_id'       => $product->getId(),
                'size_id'          => $size->getId(),
                'reference'        => $product->getReference(),
                'name'             => $product->getName(),
                'size_name'        => $size->getName(),
                'quantity'         => $quantity,
                'unit_price'       => $product->getPrice(),
                'discount_percent' => 0, // remise article (10–50 %) par défaut à 0 %
            ];
        }

        $session->set(self::CART_SESSION_KEY, $cart);

        $this->addFlash('success', sprintf(
            '%d x %s (taille %s) ajouté(s) au panier.',
            $quantity,
            $product->getName(),
            $size->getName()
        ));

        return $this->redirectToRoute('user_sell');
    }

    /**
     * Définir la remise sur une ligne du panier (10–50 %, seulement si SaleMode actif).
     */
    #[Route('/user/sell/line-discount/{lineKey}', name: 'user_sell_set_line_discount', methods: ['POST'])]
    public function setLineDiscount(Request $request, string $lineKey): Response
    {
        $session = $request->getSession();
        $cart    = $session->get(self::CART_SESSION_KEY, []);

        if (!isset($cart[$lineKey])) {
            $this->addFlash('danger', 'Ligne introuvable dans le panier.');
            return $this->redirectToRoute('user_sell');
        }

        $saleMode       = $this->saleModeRepository->find(1);
        $saleModeActive = $saleMode?->isDiscountEnabled() ?? false;

        if (!$saleModeActive) {
            // On force la remise à 0 par sécurité
            $cart[$lineKey]['discount_percent'] = 0;
            $session->set(self::CART_SESSION_KEY, $cart);

            $this->addFlash(
                'danger',
                'Le mode soldes est désactivé : impossible d\'appliquer une remise par article.'
            );
            return $this->redirectToRoute('user_sell');
        }

        $raw     = $request->request->get('discount_percent', '0');
        $percent = ctype_digit((string) $raw) ? (int) $raw : 0;

        $allowed = [0, 10, 20, 30, 40, 50];
        if (!in_array($percent, $allowed, true)) {
            $this->addFlash('danger', 'Remise article invalide.');
            return $this->redirectToRoute('user_sell');
        }

        $cart[$lineKey]['discount_percent'] = $percent;
        $session->set(self::CART_SESSION_KEY, $cart);

        if ($percent === 0) {
            $this->addFlash('info', 'Aucune remise appliquée sur cet article.');
        } else {
            $this->addFlash('success', sprintf(
                'Remise de %d %% appliquée sur cet article.',
                $percent
            ));
        }

        return $this->redirectToRoute('user_sell');
    }

    /**
     * Suppression d'une ligne du panier.
     */
    #[Route('/user/sell/remove-line/{lineKey}', name: 'user_sell_remove_line', methods: ['POST'])]
    public function removeLine(Request $request, string $lineKey): Response
    {
        $session = $request->getSession();
        $cart    = $session->get(self::CART_SESSION_KEY, []);

        if (isset($cart[$lineKey])) {
            unset($cart[$lineKey]);
            $session->set(self::CART_SESSION_KEY, $cart);

            $this->addFlash('info', 'Ligne retirée du panier.');
        }

        return $this->redirectToRoute('user_sell');
    }

    /**
     * Vider complètement le panier (articles + remises + bon d'achat).
     */
    #[Route('/user/sell/clear-cart', name: 'user_sell_clear_cart', methods: ['POST'])]
    public function clearCart(Request $request): Response
    {
        $session = $request->getSession();
        $session->remove(self::CART_SESSION_KEY);
        $session->remove(self::CART_BASKET_DISCOUNT_KEY);
        $session->remove(self::CART_VOUCHER_KEY);

        $this->addFlash('info', 'Panier vidé.');

        return $this->redirectToRoute('user_sell');
    }

    /**
     * Définir la remise panier (geste co).
     * Logique : 0 ou 10 % uniquement, toujours disponible (hors SaleMode).
     */
    #[Route('/user/sell/toggle-discount', name: 'user_sell_toggle_discount', methods: ['POST'])]
    public function toggleBasketDiscount(Request $request): Response
    {
        $session = $request->getSession();
        $cart    = $session->get(self::CART_SESSION_KEY, []);

        if (empty($cart)) {
            $this->addFlash('warning', 'Le panier est vide : aucune réduction à appliquer.');
            return $this->redirectToRoute('user_sell');
        }

        $raw     = $request->request->get('discount_percent', '0');
        $percent = ctype_digit((string) $raw) ? (int) $raw : 0;

        // Dans ta logique : 0 ou 10 uniquement
        if (!in_array($percent, [0, 10], true)) {
            $this->addFlash('danger', 'Remise panier invalide.');
            return $this->redirectToRoute('user_sell');
        }

        $session->set(self::CART_BASKET_DISCOUNT_KEY, $percent);

        if ($percent === 0) {
            $this->addFlash('info', 'Aucune remise panier appliquée.');
        } else {
            $this->addFlash('success', 'Remise de 10 % appliquée sur le panier.');
        }

        return $this->redirectToRoute('user_sell');
    }

    /**
     * Définir / mettre à jour le montant payé en bon d'achat pour le panier courant.
     */
    #[Route('/user/sell/set-voucher', name: 'user_sell_set_voucher', methods: ['POST'])]
    public function setVoucher(Request $request): Response
    {
        $session = $request->getSession();
        $cart    = $session->get(self::CART_SESSION_KEY, []);

        if (empty($cart)) {
            $session->remove(self::CART_VOUCHER_KEY);
            $this->addFlash('info', 'Le panier est vide : aucun bon d\'achat à appliquer.');
            return $this->redirectToRoute('user_sell');
        }

        // Remise panier en % (0 ou 10)
        $rawBasketDiscount = $session->get(self::CART_BASKET_DISCOUNT_KEY, 0);
        if ($rawBasketDiscount === true) {
            $rawBasketDiscount = 10;
        }
        $basketDiscountPercent = (int) $rawBasketDiscount;
        if ($basketDiscountPercent !== 10) {
            $basketDiscountPercent = 0;
        }

        // SaleMode (pour remises article)
        $saleMode       = $this->saleModeRepository->find(1);
        $saleModeActive = $saleMode?->isDiscountEnabled() ?? false;

        // On récupère le montant saisi (on accepte la virgule)
        $raw = (string) $request->request->get('voucher_amount', '0');
        $raw = str_replace(',', '.', $raw);

        if (!is_numeric($raw)) {
            $this->addFlash('danger', 'Montant de bon d\'achat invalide.');
            return $this->redirectToRoute('user_sell');
        }

        $voucherAmount = (float) $raw;
        if ($voucherAmount < 0) {
            $voucherAmount = 0.0;
        }

        // Totaux (après remises article + remise panier)
        $totals      = $this->calculateTotals($cart, $basketDiscountPercent, $saleModeActive);
        $basketTotal = $totals['total_after_discount'];

        // Le bon d'achat ne doit pas dépasser le total
        if ($voucherAmount > $basketTotal) {
            $voucherAmount = $basketTotal;
        }

        // On stocke en euros entiers
        $voucherInt = (int) round($voucherAmount);
        $session->set(self::CART_VOUCHER_KEY, $voucherInt);

        $cashAmount = max(0.0, $basketTotal - $voucherInt);

        $this->addFlash('success', sprintf(
            'Bon d\'achat de %d € appliqué. Total après remises : %.2f €, montant à payer : %.2f €.',
            $voucherInt,
            $basketTotal,
            $cashAmount
        ));

        return $this->redirectToRoute('user_sell');
    }

    /**
     * Validation de la vente :
     * - vérifie les stocks
     * - décrémente les stocks
     * - crée les StockMovement de type SALE
     * - applique les remises article + remise panier
     * - prend en compte le bon d'achat pour le commentaire
     * - vide le panier
     */
    #[Route('/user/sell/validate', name: 'user_sell_validate', methods: ['POST'])]
    public function validateSale(Request $request): Response
    {
        $location = $this->currentLocationService->getLocation();
        if (!$location) {
            $this->addFlash('danger', 'Aucun magasin sélectionné.');
            return $this->redirectToRoute('user_select_location');
        }

        $user = $this->getUser();
        if (!$user) {
            $this->addFlash('danger', 'Utilisateur non connecté.');
            return $this->redirectToRoute('app_login');
        }

        $session = $request->getSession();
        $cart    = $session->get(self::CART_SESSION_KEY, []);

        if (empty($cart)) {
            $this->addFlash('warning', 'Le panier est vide.');
            return $this->redirectToRoute('user_sell');
        }

        // Remise panier (0 ou 10 %)
        $rawBasketDiscount = $session->get(self::CART_BASKET_DISCOUNT_KEY, 0);
        if ($rawBasketDiscount === true) {
            $rawBasketDiscount = 10;
        }
        $basketDiscountPercent = (int) $rawBasketDiscount;
        if ($basketDiscountPercent !== 10) {
            $basketDiscountPercent = 0;
        }

        // Bon d'achat
        $voucherAmount = (int) $session->get(self::CART_VOUCHER_KEY, 0);

        // SaleMode pour savoir si les remises article sont actives au moment de la vente
        $saleMode       = $this->saleModeRepository->find(1);
        $saleModeActive = $saleMode?->isDiscountEnabled() ?? false;

        // Totaux (remises article + remise panier)
        $totals       = $this->calculateTotals($cart, $basketDiscountPercent, $saleModeActive);
        $basketTotal  = $totals['total_after_discount'];
        $basketRate   = $basketDiscountPercent === 10 ? 0.10 : 0.0;

        // Cohérence du bon d'achat
        if ($voucherAmount < 0) {
            $voucherAmount = 0;
        }
        if ($voucherAmount > $basketTotal) {
            $voucherAmount = (int) $basketTotal;
        }

        $cashAmount = max(0.0, $basketTotal - (float) $voucherAmount);

        // 1ère passe : vérification de tous les stocks
        foreach ($cart as $line) {
            /** @var Product|null $product */
            $product = $this->em->getRepository(Product::class)->find($line['product_id'] ?? null);
            /** @var Size|null $size */
            $size = $this->em->getRepository(Size::class)->find($line['size_id'] ?? null);

            if (!$product || !$size) {
                $this->addFlash('danger', 'Produit ou taille introuvable. Vente annulée.');
                return $this->redirectToRoute('user_sell');
            }

            /** @var Stock|null $stock */
            $stock = $this->em->getRepository(Stock::class)->findOneBy([
                'product'  => $product,
                'size'     => $size,
                'location' => $location,
            ]);

            if (!$stock || $stock->getQuantity() < (int) ($line['quantity'] ?? 0)) {
                $this->addFlash('danger', sprintf(
                    'Stock insuffisant pour %s taille %s. Vente annulée.',
                    $product->getReference(),
                    $size->getName()
                ));
                return $this->redirectToRoute('user_sell');
            }
        }

        // 2ème passe : décrémentation + création des mouvements
        foreach ($cart as $line) {
            /** @var Product $product */
            $product = $this->em->getRepository(Product::class)->find($line['product_id']);
            /** @var Size $size */
            $size = $this->em->getRepository(Size::class)->find($line['size_id']);

            /** @var Stock $stock */
            $stock = $this->em->getRepository(Stock::class)->findOneBy([
                'product'  => $product,
                'size'     => $size,
                'location' => $location,
            ]);

            $quantity  = (int) $line['quantity'];
            $unitPrice = (float) $line['unit_price'];

            $newQuantity = $stock->getQuantity() - $quantity;
            $stock->setQuantity($newQuantity);

            $lineOriginal = $unitPrice * $quantity;

            // Remise article
            $linePercent = (int) ($line['discount_percent'] ?? 0);
            if (!$saleModeActive) {
                // Si le SaleMode a été coupé entre-temps, on ignore les remises article
                $linePercent = 0;
            }
            if ($linePercent < 0) {
                $linePercent = 0;
            }
            if ($linePercent > 50) {
                $linePercent = 50;
            }

            $lineRate  = $linePercent > 0 ? $linePercent / 100.0 : 0.0;
            $lineAfter = $lineRate > 0 ? $lineOriginal * (1 - $lineRate) : $lineOriginal;

            // Remise panier 10 % (si active)
            $lineFinal = $basketRate > 0
                ? $lineAfter * (1 - $basketRate)
                : $lineAfter;

            $movement = new StockMovement();
            $movement
                ->setType('SALE')
                ->setProduct($product)
                ->setSize($size)
                ->setLocation($location)
                ->setUser($user)
                ->setQuantity($quantity)
                ->setOriginalPrice(number_format($lineOriginal, 2, '.', ''))
                ->setFinalPrice(number_format($lineFinal, 2, '.', ''))
                ->setCreatedAt(new \DateTimeImmutable());

            if ($lineRate > 0.0 || $basketRate > 0.0) {
                $movement->setIsDiscounted(true);

                // On stocke le "combo" des remises dans le label, pour être clair
                $labels = [];
                if ($lineRate > 0.0) {
                    $labels[] = sprintf('Remise article %d%%', $linePercent);
                }
                if ($basketRate > 0.0) {
                    $labels[] = 'Remise panier 10%';
                }

                $movement
                    ->setDiscountPercent($linePercent) // % article uniquement
                    ->setDiscountLabel(implode(' + ', $labels));
            } else {
                $movement
                    ->setIsDiscounted(false)
                    ->setDiscountPercent(null)
                    ->setDiscountLabel(null);
            }

            $movement->setComment(sprintf(
                'Vente. PU: %.2f. Remise article: %s. Remise panier: %s. Total panier après toutes remises: %.2f. Bon d\'achat utilisé: %d. Montant payé réellement: %.2f.',
                $unitPrice,
                $linePercent > 0 ? sprintf('%d%%', $linePercent) : '0%',
                $basketDiscountPercent === 10 ? '10%' : '0%',
                $basketTotal,
                $voucherAmount,
                $cashAmount
            ));

            $this->em->persist($movement);
            $this->em->persist($stock);
        }

        $this->em->flush();

        // On vide tout
        $session->remove(self::CART_SESSION_KEY);
        $session->remove(self::CART_BASKET_DISCOUNT_KEY);
        $session->remove(self::CART_VOUCHER_KEY);

        $this->addFlash('success', sprintf(
            'Vente enregistrée. Total: %.2f € (remises appliquées, bon d\'achat: %d €, payé réellement: %.2f €).',
            $basketTotal,
            $voucherAmount,
            $cashAmount
        ));

        return $this->redirectToRoute('user_sell');
    }

    /**
     * Calcule le total du panier en tenant compte :
     * - des remises article (si SaleMode actif)
     * - de la remise panier (0 ou 10 %)
     * - avec arrondi final à l'euro supérieur.
     *
     * @param array<int, array<string, mixed>> $cart
     */
    private function calculateTotals(array $cart, int $basketDiscountPercent, bool $saleModeActive): array
    {
        $totalBrut         = 0.0;
        $totalAfterLine    = 0.0;
        $sumLineDiscounted = 0.0;

        foreach ($cart as $line) {
            $unit     = (float) $line['unit_price'];
            $qty      = (int) $line['quantity'];
            $original = $unit * $qty;

            $totalBrut += $original;

            $linePercent = (int) ($line['discount_percent'] ?? 0);

            if (!$saleModeActive) {
                $linePercent = 0;
            }

            if ($linePercent < 0) {
                $linePercent = 0;
            }
            if ($linePercent > 50) {
                $linePercent = 50;
            }

            $lineRate  = $linePercent > 0 ? $linePercent / 100.0 : 0.0;
            $lineFinal = $lineRate > 0 ? $original * (1 - $lineRate) : $original;

            $totalAfterLine    += $lineFinal;
            $sumLineDiscounted += ($original - $lineFinal);
        }

        // Remise panier (0 ou 10 %)
        if ($basketDiscountPercent !== 10) {
            $basketDiscountPercent = 0;
        }
        $basketRate = $basketDiscountPercent === 10 ? 0.10 : 0.0;

        if ($basketRate > 0) {
            $afterBasket = $totalAfterLine * (1 - $basketRate);
        } else {
            $afterBasket = $totalAfterLine;
        }

        $afterBasketRounded = (float) ceil($afterBasket);

        return [
            'total'                => $totalBrut,
            'discount_rate'        => $basketRate, // utilisé si besoin pour affichage, mais on passe aussi basket_discount_percent séparément
            'total_after_discount' => $afterBasketRounded,
            'discount_detail'      => [
                'line_rate_sum' => $sumLineDiscounted,
            ],
        ];
    }
}
