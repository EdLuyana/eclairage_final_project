<?php

namespace App\Controller\User;

use App\Entity\Product;
use App\Entity\Size;
use App\Entity\Stock;
use App\Entity\StockMovement;
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
    public const CART_SESSION_KEY      = 'sell_cart';
    public const CART_DISCOUNT_KEY     = 'sell_cart_discount';
    public const CART_VOUCHER_KEY      = 'sell_cart_voucher'; // 💳 bon d'achat

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentLocationService $currentLocationService,
    ) {
    }

    /**
     * Affichage de la page de vente + panier actuel
     */
    #[Route('/user/sell', name: 'user_sell', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $location = $this->currentLocationService->getLocation();

        if (!$location) {
            return $this->redirectToRoute('user_select_location');
        }

        $session         = $request->getSession();
        $cart            = $session->get(self::CART_SESSION_KEY, []);
        $discountApplied = (bool) $session->get(self::CART_DISCOUNT_KEY, false);

        // Si le panier est vide, on remet le bon d'achat à 0 pour éviter les incohérences
        if (empty($cart)) {
            $session->remove(self::CART_VOUCHER_KEY);
            $voucherAmount = 0;
        } else {
            // On stocke le bon d'achat en euros entiers
            $voucherAmount = (int) $session->get(self::CART_VOUCHER_KEY, 0);
        }

        $totals       = $this->calculateTotals($cart, $discountApplied);
        $basketTotal  = $totals['total_after_discount'];

        // On s'assure que le bon d'achat ne dépasse pas le total
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
            'page_title'       => 'Vente',
            'current_location' => $location,
            'cart'             => $cart,
            'discount_applied' => $discountApplied,
            'totals'           => $totals,
            'voucher_amount'   => $voucherAmount,
            'cash_amount'      => $cashAmount,
        ]);
    }

    /**
     * Récupère les tailles disponibles pour une référence donnée
     * (endpoint JSON pour futur JS/autocomplétion)
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
                'message' => 'Produit introuvable pour cette référence.',
            ], 404);
        }

        // On récupère tous les stocks du produit dans le magasin courant
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
     * On attend : reference, size_id, quantity
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

        if ($reference === '' || $sizeId <= 0 || $quantity <= 0) {
            $this->addFlash('danger', 'Données incomplètes pour ajouter au panier.');
            return $this->redirectToRoute('user_sell');
        }

        /** @var Product|null $product */
        $product = $this->em->getRepository(Product::class)
            ->findOneBy(['reference' => $reference]);

        if (!$product) {
            $this->addFlash('danger', 'Produit introuvable pour cette référence.');
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

        // Récupération/initialisation du panier
        $session = $request->getSession();
        $cart = $session->get(self::CART_SESSION_KEY, []);

        // Prix unitaire : on part du prix produit actuel
        $unitPrice = (float) $product->getPrice();

        // Fusion si même produit + même taille déjà dans le panier
        $lineFound = false;
        foreach ($cart as &$line) {
            if ($line['product_id'] === $product->getId() && $line['size_id'] === $size->getId()) {
                $newQuantity = $line['quantity'] + $quantity;

                if ($newQuantity > $stock->getQuantity()) {
                    $this->addFlash('danger', sprintf(
                        'Impossible d’ajouter %d : il ne reste que %d en stock pour cette taille.',
                        $quantity,
                        $stock->getQuantity()
                    ));
                    return $this->redirectToRoute('user_sell');
                }

                $line['quantity'] = $newQuantity;
                $lineFound = true;
                break;
            }
        }
        unset($line);

        if (!$lineFound) {
            $cart[] = [
                'product_id'         => $product->getId(),
                'product_reference'  => $product->getReference(),
                'product_name'       => $product->getName(),
                'size_id'            => $size->getId(),
                'size_name'          => $size->getName(),
                'quantity'           => $quantity,
                'unit_price'         => $unitPrice,
            ];
        }

        $session->set(self::CART_SESSION_KEY, $cart);

        $this->addFlash('success', 'Article ajouté au panier.');

        return $this->redirectToRoute('user_sell');
    }

    /**
     * Supprimer une ligne du panier (par index)
     */
    #[Route('/user/sell/remove-line/{index}', name: 'user_sell_remove_line', methods: ['POST'])]
    public function removeLine(Request $request, int $index): Response
    {
        $session = $request->getSession();
        $cart = $session->get(self::CART_SESSION_KEY, []);

        if (isset($cart[$index])) {
            unset($cart[$index]);
            $cart = array_values($cart);
            $session->set(self::CART_SESSION_KEY, $cart);

            $this->addFlash('success', 'Ligne supprimée du panier.');
        }

        return $this->redirectToRoute('user_sell');
    }

    /**
     * Appliquer / retirer la réduction de 10% sur le panier.
     * On stocke juste un booléen en session.
     */
    #[Route('/user/sell/toggle-discount', name: 'user_sell_toggle_discount', methods: ['POST'])]
    public function toggleDiscount(Request $request): Response
    {
        $session = $request->getSession();

        $current = (bool) $session->get(self::CART_DISCOUNT_KEY, false);
        $session->set(self::CART_DISCOUNT_KEY, !$current);

        if ($current) {
            $this->addFlash('info', 'Réduction 10% retirée du panier.');
        } else {
            $this->addFlash('success', 'Réduction 10% appliquée au panier.');
        }

        return $this->redirectToRoute('user_sell');
    }

    /**
     * Définir / mettre à jour le montant payé en bon d'achat pour le panier courant.
     */
    #[Route('/user/sell/set-voucher', name: 'user_sell_set_voucher', methods: ['POST'])]
    public function setVoucher(Request $request): Response
    {
        $session         = $request->getSession();
        $cart            = $session->get(self::CART_SESSION_KEY, []);
        $discountApplied = (bool) $session->get(self::CART_DISCOUNT_KEY, false);

        if (empty($cart)) {
            $session->remove(self::CART_VOUCHER_KEY);
            $this->addFlash('info', 'Le panier est vide : aucun bon d\'achat à appliquer.');
            return $this->redirectToRoute('user_sell');
        }

        // On récupère le montant saisi (on accepte la virgule comme séparateur)
        $raw = (string) $request->request->get('voucher_amount', '0');
        $raw = str_replace(',', '.', $raw);

        // On force en euros entiers
        $voucherAmount = (int) round((float) $raw);

        if ($voucherAmount < 0) {
            $voucherAmount = 0;
        }

        $totals      = $this->calculateTotals($cart, $discountApplied);
        $basketTotal = $totals['total_after_discount'];

        if ($voucherAmount > $basketTotal) {
            $voucherAmount = (int) $basketTotal;
        }

        $session->set(self::CART_VOUCHER_KEY, $voucherAmount);

        if ($voucherAmount > 0) {
            $this->addFlash('success', sprintf(
                'Bon d\'achat de %d € appliqué sur cette vente.',
                $voucherAmount
            ));
        } else {
            $this->addFlash('info', 'Aucun bon d\'achat appliqué sur cette vente.');
        }

        return $this->redirectToRoute('user_sell');
    }

    /**
     * Validation de la vente :
     * - vérifie à nouveau les stocks
     * - décrémente les stocks
     * - crée les StockMovement de type SALE
     * - applique la réduction si activée
     * - prend en compte le bon d'achat pour le CA
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

        $session         = $request->getSession();
        $cart            = $session->get(self::CART_SESSION_KEY, []);
        $discountApplied = (bool) $session->get(self::CART_DISCOUNT_KEY, false);
        $voucherAmount   = (int) $session->get(self::CART_VOUCHER_KEY, 0);

        if (empty($cart)) {
            $this->addFlash('warning', 'Le panier est vide.');
            return $this->redirectToRoute('user_sell');
        }

        // Recalcul des totaux (avec ou sans réduction)
        $totals       = $this->calculateTotals($cart, $discountApplied);
        $basketTotal  = $totals['total_after_discount']; // montant final arrondi à l’euro sup.
        $discountRate = $totals['discount_rate'];

        // On s'assure une dernière fois que le bon d'achat est cohérent
        if ($voucherAmount < 0) {
            $voucherAmount = 0;
        }
        if ($voucherAmount > $basketTotal) {
            $voucherAmount = (int) $basketTotal;
        }

        $cashAmount = max(0.0, $basketTotal - (float) $voucherAmount);

        // Vérification des stocks
        foreach ($cart as $line) {
            /** @var Product|null $product */
            $product = $this->em->getRepository(Product::class)->find($line['product_id']);
            /** @var Size|null $size */
            $size = $this->em->getRepository(Size::class)->find($line['size_id']);

            if (!$product || !$size) {
                $this->addFlash('danger', 'Erreur interne : produit ou taille introuvable.');
                return $this->redirectToRoute('user_sell');
            }

            /** @var Stock|null $stock */
            $stock = $this->em->getRepository(Stock::class)->findOneBy([
                'product'  => $product,
                'size'     => $size,
                'location' => $location,
            ]);

            if (!$stock || $stock->getQuantity() < $line['quantity']) {
                $this->addFlash('danger', sprintf(
                    'Stock insuffisant pour %s taille %s. Vente annulée.',
                    $product->getReference(),
                    $size->getName()
                ));
                return $this->redirectToRoute('user_sell');
            }
        }

        // Tout est OK : on décrémente les stocks + crée les mouvements
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

            $newQuantity = $stock->getQuantity() - $line['quantity'];
            $stock->setQuantity($newQuantity);

            $movement = new StockMovement();
            $movement
                ->setType('SALE')
                ->setProduct($product)
                ->setSize($size)
                ->setLocation($location)
                ->setUser($user)
                ->setQuantity($line['quantity'])
                ->setCreatedAt(new \DateTimeImmutable())
                ->setComment(sprintf(
                    'Vente. PU: %.2f. Réduction: %s. Total panier: %.2f. Bon d\'achat utilisé: %d. Montant payé réellement: %.2f.',
                    $line['unit_price'],
                    $discountApplied ? '10%' : '0%',
                    $basketTotal,
                    $voucherAmount,
                    $cashAmount
                ));

            $this->em->persist($movement);
            $this->em->persist($stock);
        }

        $this->em->flush();

        // On vide le panier, la réduction et le bon d'achat
        $session->remove(self::CART_SESSION_KEY);
        $session->remove(self::CART_DISCOUNT_KEY);
        $session->remove(self::CART_VOUCHER_KEY);

        $this->addFlash('success', sprintf(
            'Vente enregistrée. Total: %.2f € (réduction %s, bon d\'achat: %d €, payé réellement: %.2f €).',
            $basketTotal,
            $discountApplied ? '10%' : '0%',
            $voucherAmount,
            $cashAmount
        ));

        return $this->redirectToRoute('user_sell');
    }

    /**
     * Calcule le total du panier, applique la remise si activée
     * et arrondit le total final à l’euro supérieur.
     *
     * @param array<int, array<string, mixed>> $cart
     */
    private function calculateTotals(array $cart, bool $discountApplied): array
    {
        $total = 0.0;

        foreach ($cart as $line) {
            $total += ((float) $line['unit_price']) * (int) $line['quantity'];
        }

        $discountRate = $discountApplied ? 0.10 : 0.0;

        if ($discountApplied) {
            $afterDiscount = $total * (1 - $discountRate);
        } else {
            $afterDiscount = $total;
        }

        $afterDiscountRounded = (float) ceil($afterDiscount);

        return [
            'total'                => $total,
            'discount_rate'        => $discountRate,
            'total_after_discount' => $afterDiscountRounded,
        ];
    }
}
