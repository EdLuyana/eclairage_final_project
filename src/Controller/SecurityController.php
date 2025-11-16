<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route(path: '/', name: 'homepage')]
    public function homepage(): Response
    {
        // Page d'accueil : on redirige directement vers la page de connexion
        return $this->redirectToRoute('app_login');
    }

    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Si l'utilisateur est déjà connecté, on le redirige vers le bon dashboard
        if ($this->getUser()) {
            $user = $this->getUser();
            $roles = $user->getRoles();

            if (in_array('ROLE_ADMIN', $roles, true)) {
                return $this->redirectToRoute('admin_dashboard');
            }

            return $this->redirectToRoute('user_dashboard');
        }

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        // Ce code ne sera jamais exécuté : Symfony intercepte cette route automatiquement.
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
