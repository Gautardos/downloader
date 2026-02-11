<?php

namespace App\Controller;

use App\Service\AuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AuthController extends AbstractController
{
    #[Route('/login', name: 'login')]
    public function login(Request $request, AuthService $auth, \App\Service\JsonStorage $storage): Response
    {
        if ($auth->isAuthenticated()) {
            return $this->redirectToRoute('music');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $user = $request->request->get('username');
            $pass = $request->request->get('password');

            if ($auth->authenticate($user, $pass)) {
                return $this->redirectToRoute('music');
            }
            $error = 'Invalid credentials';
        }

        return $this->render('auth/login.html.twig', [
            'error' => $error,
            'is_configured' => $storage->isConfigured()
        ]);
    }

    #[Route('/logout', name: 'logout')]
    public function logout(AuthService $auth): Response
    {
        $auth->logout();
        return $this->redirectToRoute('login');
    }
}
