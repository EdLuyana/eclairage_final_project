<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Repository\SizeRepository;
use App\Repository\LabelPrintStateRepository;
use Com\Tecnick\Barcode\Barcode;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route('/admin/reassort', name: 'admin_reassort_')]
class AdminReassortController extends AbstractController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly ProductRepository $productRepository,
        private readonly SizeRepository $sizeRepository,
        private readonly LabelPrintStateRepository $labelPrintStateRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        /** @var int[] $batchProductIds */
        $batchProductIds = $session->get('reassort_batch', []);
        if (!is_array($batchProductIds)) {
            $batchProductIds = [];
        }

        $reference = '';

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', '');

            // 1) Ajout d'un produit au lot
            if ($action === 'add_product') {
                $reference = trim((string) $request->request->get('reference'));

                if ($reference === '') {
                    $this->addFlash('danger', 'Veuillez saisir une référence produit.');
                } else {
                    /** @var Product|null $product */
                    $product = $this->productRepository->findOneBy(['reference' => $reference]);

                    if (!$product) {
                        $this->addFlash(
                            'danger',
                            sprintf('Aucun produit trouvé pour la référence "%s".', $reference)
                        );
                    } else {
                        $productId = $product->getId();
                        if (!in_array($productId, $batchProductIds, true)) {
                            $batchProductIds[] = $productId;
                            $session->set('reassort_batch', $batchProductIds);
                            $this->addFlash('success', sprintf(
                                'Produit "%s" (%s) ajouté au lot de réassort.',
                                $product->getName(),
                                $product->getReference()
                            ));
                        } else {
                            $this->addFlash(
                                'info',
                                sprintf('Le produit "%s" est déjà dans le lot.', $product->getReference())
                            );
                        }
                    }
                }

                return $this->redirectToRoute('admin_reassort_index');
            }

            // 2) Suppression d'un produit du lot
            if ($action === 'remove_product') {
                $productId = (int) $request->request->get('product_id', 0);

                if (
                    !$this->isCsrfTokenValid(
                        'reassort_remove_' . $productId,
                        (string) $request->request->get('_token')
                    )
                ) {
                    $this->addFlash('danger', 'Le formulaire a expiré, veuillez réessayer.');
                    return $this->redirectToRoute('admin_reassort_index');
                }

                if ($productId > 0 && in_array($productId, $batchProductIds, true)) {
                    $batchProductIds = array_values(array_filter(
                        $batchProductIds,
                        static fn (int $id): bool => $id !== $productId
                    ));
                    $session->set('reassort_batch', $batchProductIds);
                    $this->addFlash('success', 'Produit retiré du lot de réassort.');
                }

                return $this->redirectToRoute('admin_reassort_index');
            }

            // 3) Génération des étiquettes du lot
            if ($action === 'generate_labels') {
                if (
                    !$this->isCsrfTokenValid(
                        'reassort_generate_labels',
                        (string) $request->request->get('_token')
                    )
                ) {
                    $this->addFlash('danger', 'Le formulaire a expiré, veuillez réessayer.');
                    return $this->redirectToRoute('admin_reassort_index');
                }

                /** @var array<string, array<string, int|string>> $quantities */
                $quantities = $request->request->all('quantities');

                if (empty($batchProductIds) || empty($quantities)) {
                    $this->addFlash('warning', 'Aucun produit ou aucune quantité saisie pour ce réassort.');
                    return $this->redirectToRoute('admin_reassort_index');
                }

                $products = $this->productRepository->findBy(['id' => $batchProductIds]);

                $labels  = [];
                $barcode = new Barcode();

                foreach ($products as $product) {
                    $productId = (string) $product->getId();

                    if (!isset($quantities[$productId])) {
                        continue;
                    }

                    $sizesConfig = $product->getSizesConfig();
                    $sizeIds     = [];

                    if ($sizesConfig) {
                        /** @var int[] $decodedIds */
                        $decodedIds = json_decode($sizesConfig, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decodedIds)) {
                            $sizeIds = $decodedIds;
                        }
                    }

                    if (empty($sizeIds)) {
                        continue;
                    }

                    $sizeEntities = $this->sizeRepository->findBy(['id' => $sizeIds]);

                    foreach ($sizeEntities as $size) {
                        $sizeId = (string) $size->getId();

                        if (!isset($quantities[$productId][$sizeId])) {
                            continue;
                        }

                        $qty = (int) $quantities[$productId][$sizeId];
                        if ($qty <= 0) {
                            continue;
                        }

                        $sizeLabel = $size->getName();
                        $codeValue = sprintf('%s-%s', $product->getReference(), $sizeLabel);

                        // DataMatrix (ref-taille)
                        $barcodeObj = $barcode->getBarcodeObj(
                            'DATAMATRIX',
                            $codeValue,
                            -4,
                            -4,
                            'black',
                            [0, 0, 0, 0]
                        );

                        $pngData       = $barcodeObj->getPngData();
                        $barcodeBase64 = base64_encode($pngData);

                        for ($i = 0; $i < $qty; $i++) {
                            $labels[] = [
                                'reference'      => $product->getReference(),
                                'name'           => $product->getName(),
                                'color'          => $product->getColor()?->getName() ?? '',
                                'size'           => $sizeLabel,
                                'price'          => $product->getPrice(),
                                'datamatrix'     => $barcodeBase64,
                                'code_value'     => $codeValue,
                                'barcode_base64' => $barcodeBase64,
                            ];
                        }
                    }
                }

                if (count($labels) === 0) {
                    $this->addFlash('warning', 'Aucune quantité valide saisie pour ce réassort.');
                    return $this->redirectToRoute('admin_reassort_index');
                }

                // 56 étiquettes par page (4x14), en tenant compte de la dernière case utilisée
                $labelsPerPage = 56;

                $state       = $this->labelPrintStateRepository->getSingleton();
                $startOffset = $state->getLastPosition(); // 0 à 55

                $labelsWithBlanks = [];

                // Cases déjà utilisées sur la feuille courante
                for ($i = 0; $i < $startOffset; $i++) {
                    $labelsWithBlanks[] = null;
                }

                // Ajout des étiquettes du réassort
                foreach ($labels as $label) {
                    $labelsWithBlanks[] = $label;
                }

                // On découpe en pages de 56 cases
                $pages = array_chunk($labelsWithBlanks, $labelsPerPage);

                // Mise à jour de la dernière position utilisée
                $totalUsed = ($startOffset + count($labels)) % $labelsPerPage;
                $state->setLastPosition($totalUsed);
                $this->em->persist($state);
                $this->em->flush();

                $html = $this->twig->render('admin/product/labels_pdf.html.twig', [
                    'product' => $products[0] ?? null, // juste pour le titre
                    'pages'   => $pages,
                ]);

                $options = new Options();
                $options->set('isRemoteEnabled', true);
                $options->set('defaultFont', 'DejaVu Sans');

                $dompdf = new Dompdf($options);
                $dompdf->loadHtml($html);
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();

                // On vide le lot après génération
                $session->remove('reassort_batch');

                $fileName = 'etiquettes_reassort.pdf';

                return new Response(
                    $dompdf->output(),
                    200,
                    [
                        'Content-Type'        => 'application/pdf',
                        'Content-Disposition' => 'inline; filename="' . $fileName . '"',
                    ]
                );
            }
        }

        // GET : affichage du lot courant
        $products = [];
        if (!empty($batchProductIds)) {
            $products = $this->productRepository->findBy(['id' => $batchProductIds]);
        }

        $batch = [];
        foreach ($products as $product) {
            $sizesConfig = $product->getSizesConfig();
            $sizes       = [];

            if ($sizesConfig) {
                /** @var int[] $sizeIds */
                $sizeIds = json_decode($sizesConfig, true, 512, JSON_THROW_ON_ERROR);
                if (!empty($sizeIds)) {
                    $sizeEntities = $this->sizeRepository->findBy(['id' => $sizeIds]);
                    foreach ($sizeEntities as $sizeEntity) {
                        $sizes[] = $sizeEntity;
                    }
                }
            }

            $batch[] = [
                'product' => $product,
                'sizes'   => $sizes,
            ];
        }

        return $this->render('admin/reassort/index.html.twig', [
            'page_title' => 'Réassort',
            'reference'  => $reference,
            'batch'      => $batch,
        ]);
    }

    #[Route('/autocomplete', name: 'autocomplete', methods: ['GET'])]
    public function autocomplete(Request $request): Response
    {
        $term = trim((string) (
            $request->query->get('q')
            ?? $request->query->get('term')
            ?? $request->query->get('query')
            ?? ''
        ));

        if ($term === '') {
            return $this->json([]);
        }

        $qb = $this->productRepository->createQueryBuilder('p');
        $qb
            ->where('p.reference LIKE :term OR p.name LIKE :term')
            ->setParameter('term', '%' . $term . '%')
            ->setMaxResults(10);

        /** @var Product[] $products */
        $products = $qb->getQuery()->getResult();

        $results = [];
        foreach ($products as $product) {
            $label = sprintf('%s — %s', $product->getReference(), $product->getName());

            $results[] = [
                'value'     => $product->getReference(),
                'label'     => $label,
                'reference' => $product->getReference(),
            ];
        }

        return $this->json($results);
    }
}
