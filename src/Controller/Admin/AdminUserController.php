<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserForm;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/users', name: 'admin_user_')]
class AdminUserController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        // Utilisation de isActive au lieu de isArchived
        $activeUsers = $userRepository->createQueryBuilder('u')
            ->andWhere('u.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();

        $archivedUsers = $userRepository->createQueryBuilder('u')
            ->andWhere('u.isActive = :active')
            ->setParameter('active', false)
            ->orderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/user/index.html.twig', [
            'page_title'     => 'Gestion des comptes utilisateurs',
            'active_users'   => $activeUsers,
            'archived_users' => $archivedUsers,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $user = new User();

        $form = $this->createForm(UserForm::class, $user, [
            'is_edit' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string|null $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            if ($plainPassword) {
                $hashed = $passwordHasher->hashPassword($user, $plainPassword);
                $user->setPassword($hashed);
            }

            // Par défaut : rôle vendeuse si rien n'est défini
            if (empty($user->getRoles()) || $user->getRoles() === ['ROLE_USER']) {
                $user->setRoles(['ROLE_USER']);
            }

            // Par défaut : actif (déjà fait dans le constructeur, mais on sécurise)
            $user->setIsActive(true);

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', sprintf('Le compte "%s" a été créé.', $user->getUsername()));

            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/new.html.twig', [
            'page_title' => 'Nouveau compte',
            'form'       => $form,
        ]);
    }

    #[Route('/{id}/edit-password', name: 'edit_password', methods: ['GET', 'POST'])]
    public function editPassword(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $form = $this->createForm(UserForm::class, $user, [
            'is_edit' => true,
        ]);
        $form->remove('username'); // on ne change pas l'identifiant ici

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string|null $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            if ($plainPassword) {
                $hashed = $passwordHasher->hashPassword($user, $plainPassword);
                $user->setPassword($hashed);
            }

            $em->flush();

            $this->addFlash('success', sprintf('Le mot de passe de "%s" a été mis à jour.', $user->getUsername()));

            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/edit_password.html.twig', [
            'page_title' => sprintf('Modifier le mot de passe - %s', $user->getUsername()),
            'user'       => $user,
            'form'       => $form,
        ]);
    }

    #[Route('/{id}/toggle-archive', name: 'toggle_archive', methods: ['POST'])]
    public function toggleArchive(
        User $user,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('toggle_archive_'.$user->getId(), $token)) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        // On utilise isActive comme "actif / archivé"
        $user->setIsActive(!$user->isActive());
        $em->flush();

        $this->addFlash(
            'success',
            sprintf(
                'Le compte "%s" a été %s.',
                $user->getUsername(),
                $user->isActive() ? 'réactivé' : 'archivé'
            )
        );

        return $this->redirectToRoute('admin_user_index');
    }

    #[Route('/{id}/toggle-role', name: 'toggle_role', methods: ['POST'])]
    public function toggleRole(
        User $user,
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('toggle_role_'.$user->getId(), $token)) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $roles   = $user->getRoles();
        $isAdmin = in_array('ROLE_ADMIN', $roles, true);

        // Si on veut retirer ROLE_ADMIN, on vérifie qu'il reste au moins un autre admin
        if ($isAdmin) {
            $admins = $userRepository->findAll();
            $adminCount = 0;
            foreach ($admins as $u) {
                if (in_array('ROLE_ADMIN', $u->getRoles(), true)) {
                    $adminCount++;
                }
            }

            if ($adminCount <= 1) {
                $this->addFlash('danger', 'Vous ne pouvez pas retirer le dernier administrateur du système.');
                return $this->redirectToRoute('admin_user_index');
            }

            // On repasse le compte en "vendeuse"
            $user->setRoles(['ROLE_USER']);
            $message = sprintf('Le compte "%s" est maintenant un compte vendeuse.', $user->getUsername());
        } else {
            // On promeut en admin
            $user->setRoles(['ROLE_ADMIN']);
            $message = sprintf('Le compte "%s" est maintenant un compte administrateur.', $user->getUsername());
        }

        $em->flush();

        $this->addFlash('success', $message);

        return $this->redirectToRoute('admin_user_index');
    }
}
