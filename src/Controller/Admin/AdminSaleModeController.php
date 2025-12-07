<?php

namespace App\Controller\Admin;

use App\Entity\SaleMode;
use App\Repository\SaleModeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/discounts')]
class AdminSaleModeController extends AbstractController
{
    #[Route('', name: 'admin_discounts_index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        SaleModeRepository $saleModeRepository,
        EntityManagerInterface $em
    ): Response {
        $saleMode = $saleModeRepository->find(1);

        if (!$saleMode) {
            $saleMode = new SaleMode();
            $saleMode->setDiscountEnabled(false);
            $em->persist($saleMode);
            $em->flush();
        }

        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if ($this->isCsrfTokenValid('toggle_sale_mode', $token)) {
                $enabled = $request->request->getBoolean('discount_enabled', false);
                $saleMode->setDiscountEnabled($enabled);
                $em->flush();

                $this->addFlash(
                    'success',
                    $enabled
                        ? 'Le mode promotions est désormais activé.'
                        : 'Le mode promotions est désormais désactivé.'
                );

                return $this->redirectToRoute('admin_discounts_index');
            }
        }

        return $this->render('admin/discounts/index.html.twig', [
            'sale_mode' => $saleMode,
        ]);
    }
}
