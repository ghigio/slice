<?php

namespace App\Controller;

use App\Entity\Pizzeria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Area di gestione delle pizzerie, protetta da username e password (config/packages/security.yaml).
 */
final class PizzeriaAdminController extends AbstractController
{
    #[Route('/gestione', name: 'gestione', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('gestione/index.html.twig');
    }

    #[Route('/gestione/pizzerie/{id}', name: 'gestione_pizzeria', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pizzeria(Pizzeria $pizzeria): Response
    {
        return $this->render('gestione/pizzeria.html.twig', ['pizzeria' => $pizzeria]);
    }

    #[Route('/gestione/login', name: 'gestione_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authentication): Response
    {
        $error = $authentication->getLastAuthenticationError();

        return $this->render('gestione/login.html.twig', [
            'username' => $authentication->getLastUsername(),
            'error' => match (true) {
                null === $error => null,
                $error instanceof TooManyLoginAttemptsAuthenticationException => 'Troppi tentativi: riprova tra un minuto.',
                default => 'Username o password non validi.',
            },
        ]);
    }

    #[Route('/gestione/logout', name: 'gestione_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Gestito dal firewall «gestione».');
    }
}
