<?php

namespace App\Controller\User;

use App\Entity\Location;
use App\Service\CurrentLocationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class UserLocationController extends AbstractController
{
    #[Route('/user/location', name: 'user_select_location')]
    public function select(
        EntityManagerInterface $em,
        CurrentLocationService $currentLocationService
    ): Response {
        $locations = $em->getRepository(Location::class)->findBy(['isActive' => true]);

        return $this->render('user/select_location.html.twig', [
            'locations' => $locations,
            'page_title' => 'Choisir un magasin',
        ]);
    }

    #[Route('/user/location/set/{id}', name: 'user_set_location')]
    public function set(
        Location $location,
        CurrentLocationService $currentLocationService
    ): Response {
        $currentLocationService->setLocation($location);

        // Une fois le magasin choisi, on redirige vers le dashboard vendeuse
        return $this->redirectToRoute('user_dashboard');
    }
}
