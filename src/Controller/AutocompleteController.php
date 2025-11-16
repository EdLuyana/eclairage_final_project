<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class AutocompleteController extends AbstractController
{
    #[Route('/autocomplete/product-reference', name: 'autocomplete_product_reference', methods: ['GET'])]
    public function productReference(Request $request, ProductRepository $productRepository): JsonResponse
    {
        $term = trim((string) $request->query->get('term', ''));

        // Si rien tapé → pas de résultats
        if ($term === '') {
            return $this->json([]);
        }

        $termUpper = mb_strtoupper($term, 'UTF-8');

        $qb = $productRepository->createQueryBuilder('p')
            ->where('UPPER(p.reference) LIKE :term')
            ->setParameter('term', $termUpper.'%')
            ->orderBy('p.reference', 'ASC')
            ->setMaxResults(10);

        $results = [];

        foreach ($qb->getQuery()->getResult() as $product) {
            $results[] = [
                'id'        => $product->getId(),
                'reference' => $product->getReference(),
                'name'      => $product->getName(),
                'label'     => sprintf('%s — %s', $product->getReference(), $product->getName()),
            ];
        }

        return $this->json($results);
    }
}
