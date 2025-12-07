<?php

namespace App\Controller\Admin;

use App\Entity\Product;
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

class AdminLabelController extends AbstractController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly SizeRepository $sizeRepository,
        private readonly LabelPrintStateRepository $labelPrintStateRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/products/{id}/labels', name: 'admin_product_labels', methods: ['GET', 'POST'])]
    public function labels(Request $request, Product $product): Response
    {
        // 1. Tailles
        $sizesConfig = $product->getSizesConfig();
        $sizes       = [];

        if ($sizesConfig) {
            /** @var int[] $sizeIds */
            $sizeIds = json_decode($sizesConfig, true, 512, JSON_THROW_ON_ERROR);

            if (!empty($sizeIds)) {
                $sizeEntities = $this->sizeRepository->findBy(['id' => $sizeIds]);

                foreach ($sizeEntities as $size) {
                    $sizes[] = [
                        'id'    => $size->getId(),
                        'label' => $size->getName(),
                    ];
                }

                usort(
                    $sizes,
                    fn (array $a, array $b)
                        => array_search($a['id'], $sizeIds, true) <=> array_search($b['id'], $sizeIds, true)
                );
            }
        }

        // 2. GET -> formulaire
        if ($request->isMethod('GET')) {
            return $this->render('admin/product/labels.html.twig', [
                'page_title' => 'Étiquettes produit',
                'product'    => $product,
                'sizes'      => $sizes,
            ]);
        }

        // 3. POST -> génération PDF
        $submittedToken = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('generate_labels_' . $product->getId(), $submittedToken)) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        /** @var array<string, mixed> $labelsRequest */
        $labelsRequest = $request->request->all('labels');

        $labels  = [];
        $barcode = new Barcode();

        foreach ($labelsRequest as $sizeId => $qty) {
            $quantity = (int) $qty;
            if ($quantity <= 0) {
                continue;
            }

            $size = $this->sizeRepository->find((int) $sizeId);
            if (!$size) {
                continue;
            }

            $sizeLabel = $size->getName();
            $codeValue = sprintf('%s-%s', $product->getReference(), $sizeLabel);

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

            for ($i = 0; $i < $quantity; $i++) {
                $labels[] = [
                    'reference'  => $product->getReference(),
                    'name'       => $product->getName(),
                    'color'      => $product->getColor()?->getName() ?? '',
                    'size'       => $sizeLabel,
                    'price'      => $product->getPrice(),
                    'datamatrix' => $barcodeBase64,
                    'code_value' => $codeValue,
                ];
            }
        }

        if (count($labels) === 0) {
            $this->addFlash('warning', 'Aucune étiquette à générer.');
            return $this->redirectToRoute('admin_product_labels', ['id' => $product->getId()]);
        }

        // 56 étiquettes max par page, en tenant compte de la dernière case utilisée
        $labelsPerPage = 56;

        // Récupération / création de l'état global d'impression
        $state       = $this->labelPrintStateRepository->getSingleton();
        $startOffset = $state->getLastPosition(); // 0 à 55

        $labelsWithBlanks = [];

        // On ajoute des slots "vides" pour représenter les étiquettes déjà consommées
        for ($i = 0; $i < $startOffset; $i++) {
            $labelsWithBlanks[] = null;
        }

        // Puis on ajoute les vraies étiquettes à imprimer
        foreach ($labels as $label) {
            $labelsWithBlanks[] = $label;
        }

        // On découpe en pages de 56 cases (étiquette réelle ou vide)
        $pages = array_chunk($labelsWithBlanks, $labelsPerPage);

        // Mise à jour de la dernière position utilisée :
        // on part de startOffset et on avance du nombre d'étiquettes réellement ajoutées
        $totalUsed = ($startOffset + count($labels)) % $labelsPerPage;
        $state->setLastPosition($totalUsed);
        $this->em->persist($state);
        $this->em->flush();

        $html = $this->twig->render('admin/product/labels_pdf.html.twig', [
            'product' => $product,
            'pages'   => $pages,
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $fileName = sprintf('etiquettes_%s.pdf', $product->getReference());

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
