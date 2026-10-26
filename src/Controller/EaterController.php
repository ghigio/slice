<?php

namespace App\Controller;

use App\Entity\Eater;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EaterController extends AbstractController
{
    #[Route('/g/{token}', name: 'eater_show', requirements: ['token' => '[0-9a-f]{64}'], methods: ['GET'])]
    public function show(#[MapEntity(mapping: ['token' => 'token'])] Eater $eater): Response
    {
        return $this->render('eater/show.html.twig', ['eater' => $eater, 'evening' => $eater->getEvening()]);
    }
}
