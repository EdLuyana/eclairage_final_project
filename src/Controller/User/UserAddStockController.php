<?php

namespace App\Controller\User;

use App\Entity\Product;
use App\Entity\Size;
use App\Entity\Stock;
use App\Entity\StockMovement;
use App\Entity\TransferRequest;
use App\Repository\TransferRequestRepository;
use App\Repository\ProductRepository;
use App\Repository\SizeRepository;
use App\Repository\StockRepository;
use App\Service\CurrentLocationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

#[IsGranted('ROLE_USER')]
class UserAddStockController extends AbstractController
{
    private const CART_SESSION_KEY = 'add_stock_cart';

    #[Route('/user/stock/add', name: 'user_add_stock', methods: ['GET'])]
    public function index(
        CurrentLocationService $currentLocationService,
        Request $request,
        SessionInterface $session
    ): Response {
        $location = $currentLocationService->getLocation();

        if (!$location) {
            return $this->redirectToRoute('user_select_location');
        }

        $reference = trim((string) $request->query->get('reference', ''));

        /** @var array<int, array<string, mixed>> $cart */
        $cart = $session->get(self::CART_SESSION_KEY, []);

        $totalLines = \count($cart);
        $totalQuantity = 0;
        foreach ($cart as $line) {
            $totalQuantity += (int) ($line['quantity'] ?? 0);
        }

        return $this->render('user/add_stock.html.twig', [
            'page_title'          => 'Ajout de stock',
            'current_location'    => $location,
            'prefilled_reference' => $reference,
            'cart'                => $cart,
            'total_lines'         => $totalLines,
            'total_quantity'      => $totalQuantity,
        ]);
    }

    #[Route('/user/stock/add/add-line', name: 'user_add_stock_add_line', methods: ['POST'])]
    public function addLine(
        Request $request,
        CurrentLocationService $currentLocationService,
        ProductRepository $productRepository,
        SizeRepository $sizeRepository,
        SessionInterface $session
    ): Response {
        $location = $currentLocationService->getLocation();

        if (!$location) {
            $this->addFlash('warning', 'Aucun magasin sélectionné.');
            return $this->redirectToRoute('user_select_location');
        }

        $reference = trim((string) $request->request->get('reference', ''));
        $sizeId    = (int) $request->request->get('size_id', 0);
        $quantity  = (int) $request->request->get('quantity', 1);

        if ($quantity <= 0) {
            $quantity = 1;
        }

        if ($reference === '' || $sizeId <= 0) {
            $this->addFlash('danger', 'Veuillez renseigner une référence et sélectionner une taille.');
            return $this->redirectToRoute('user_add_stock', [
                'reference' => $reference,
            ]);
        }

        /** @var Product|null $product */
        $product = $productRepository->findOneBy(['reference' => $reference]);
        if (!$product) {
            $this->addFlash('warning', sprintf('Produit introuvable pour la référence "%s".', $reference));
            return $this->redirectToRoute('user_add_stock', [
                'reference' => $reference,
            ]);
        }

        /** @var Size|null $size */
        $size = $sizeRepository->find($sizeId);
        if (!$size) {
            $this->addFlash('warning', 'Taille sélectionnée introuvable.');
            return $this->redirectToRoute('user_add_stock', [
                'reference' => $reference,
            ]);
        }

        /** @var array<int, array<string, mixed>> $cart */
        $cart = $session->get(self::CART_SESSION_KEY, []);

        $found = false;
        foreach ($cart as &$line) {
            if (
                (int) ($line['product_id'] ?? 0) === $product->getId()
                && (int) ($line['size_id'] ?? 0) === $size->getId()
            ) {
                $line['quantity'] = (int) ($line['quantity'] ?? 0) + $quantity;
                $found = true;
                break;
            }
        }
        unset($line);

        if (!$found) {
            $cart[] = [
                'product_id'   => $product->getId(),
                'product_name' => $product->getName(),
                'reference'    => $product->getReference(),
                'size_id'      => $size->getId(),
                'size_name'    => $size->getName(),
                'quantity'     => $quantity,
            ];
        }

        $session->set(self::CART_SESSION_KEY, $cart);

        $this->addFlash(
            'success',
            sprintf(
                '%d pièce(s) ajoutée(s) au panier pour %s (%s).',
                $quantity,
                $product->getReference(),
                $size->getName()
            )
        );

        return $this->redirectToRoute('user_add_stock', [
            'reference' => $product->getReference(),
        ]);
    }

    #[Route('/user/stock/add/decrement-line/{index}', name: 'user_add_stock_decrement_line', methods: ['POST'])]
    public function decrementLine(
        int $index,
        SessionInterface $session
    ): Response {
        /** @var array<int, array<string, mixed>> $cart */
        $cart = $session->get(self::CART_SESSION_KEY, []);

        if (isset($cart[$index])) {
            $currentQty = (int) ($cart[$index]['quantity'] ?? 0);

            if ($currentQty > 1) {
                $cart[$index]['quantity'] = $currentQty - 1;
                $session->set(self::CART_SESSION_KEY, $cart);
                $this->addFlash('info', 'Quantité diminuée de 1 pour cette ligne.');
            } else {
                // On laisse à 1, le bouton "✕" permet de supprimer complètement
                $this->addFlash('info', 'La quantité minimale est 1. Utilisez la croix pour supprimer la ligne.');
            }
        }

        return $this->redirectToRoute('user_add_stock');
    }

    #[Route('/user/stock/add/remove-line/{index}', name: 'user_add_stock_remove_line', methods: ['POST'])]
    public function removeLine(
        int $index,
        SessionInterface $session
    ): Response {
        /** @var array<int, array<string, mixed>> $cart */
        $cart = $session->get(self::CART_SESSION_KEY, []);

        if (isset($cart[$index])) {
            unset($cart[$index]);
            $cart = array_values($cart);
            $session->set(self::CART_SESSION_KEY, $cart);
            $this->addFlash('info', 'Ligne retirée du panier d\'ajout de stock.');
        }

        return $this->redirectToRoute('user_add_stock');
    }

    #[Route('/user/stock/add/validate', name: 'user_add_stock_validate', methods: ['POST'])]
    public function validate(
    CurrentLocationService $currentLocationService,
    ProductRepository $productRepository,
    SizeRepository $sizeRepository,
    StockRepository $stockRepository,
    TransferRequestRepository $transferRequestRepository,
    EntityManagerInterface $em,
    SessionInterface $session
): Response {
        $location = $currentLocationService->getLocation();

        if (!$location) {
            $this->addFlash('warning', 'Aucun magasin sélectionné.');
            return $this->redirectToRoute('user_select_location');
        }

        /** @var array<int, array<string, mixed>> $cart */
        $cart = $session->get(self::CART_SESSION_KEY, []);

        if (empty($cart)) {
            $this->addFlash('info', 'Aucun produit à intégrer : le panier d\'ajout de stock est vide.');
            return $this->redirectToRoute('user_add_stock');
        }

        $totalQuantity = 0;

        foreach ($cart as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $sizeId    = (int) ($line['size_id'] ?? 0);
            $quantity  = (int) ($line['quantity'] ?? 0);

            if ($productId <= 0 || $sizeId <= 0 || $quantity <= 0) {
                continue;
            }

            /** @var Product|null $product */
            $product = $productRepository->find($productId);
            /** @var Size|null $size */
            $size = $sizeRepository->find($sizeId);

            if (!$product || !$size) {
                continue;
            }

            /** @var Stock|null $stock */
            $stock = $stockRepository->findOneBy([
                'product'  => $product,
                'size'     => $size,
                'location' => $location,
            ]);

            if (!$stock) {
                $stock = new Stock();
                $stock
                    ->setProduct($product)
                    ->setSize($size)
                    ->setLocation($location)
                    ->setQuantity(0);
                $em->persist($stock);
            }

            $stock->setQuantity($stock->getQuantity() + $quantity);

            $movement = new StockMovement();
            $movement
                ->setType('ADD')
                ->setProduct($product)
                ->setSize($size)
                ->setLocation($location)
                ->setUser($this->getUser())
                ->setQuantity($quantity)
                ->setCreatedAt(new \DateTimeImmutable())
                ->setComment('Ajout de stock par la vendeuse (réception / réassort, validation panier).');

            $em->persist($movement);

            // Si une demande de transfert préparée existe pour ce produit / taille / magasin,
// on la marque comme complétée.
$transfer = $transferRequestRepository->findOneBy([
    'product'     => $product,
    'size'        => $size,
    'toLocation'  => $location,
    'status'      => TransferRequest::STATUS_PREPARED,
]);

if ($transfer) {
    $transfer->setStatus(TransferRequest::STATUS_COMPLETED);
    $em->persist($transfer);
}

            $totalQuantity += $quantity;
        }

        $em->flush();

        $session->remove(self::CART_SESSION_KEY);

        $this->addFlash(
            'success',
            sprintf('%d pièce(s) ont été intégrées au stock du magasin %s.', $totalQuantity, $location->getName())
        );

        return $this->redirectToRoute('user_add_stock');
    }
}
