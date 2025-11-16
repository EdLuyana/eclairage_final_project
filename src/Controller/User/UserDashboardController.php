<?php

namespace App\Controller\User;

use App\Service\CurrentLocationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class UserDashboardController extends AbstractController
{
    #[Route('/user', name: 'user_dashboard')]
    public function index(CurrentLocationService $currentLocationService): Response
    {
        $location = $currentLocationService->getLocation();

        // Si aucun magasin n'est encore choisi → on y oblige la vendeuse
        if (!$location) {
            return $this->redirectToRoute('user_select_location');
        }

        return $this->render('user/dashboard.html.twig', [
            'page_title' => 'Espace vendeuse',
            'current_location' => $location,
        ]);
    }
}
