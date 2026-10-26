<?php

namespace App\Controller;

use App\Entity\Evening;
use App\Form\Data\NewEveningData;
use App\Form\NewEveningType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EveningController extends AbstractController
{
    #[Route('/nuova-serata', name: 'evening_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $data = new NewEveningData();
        $form = $this->createForm(NewEveningType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $evening = new Evening($data->name, $data->date, $data->organizerName, $data->pizzeria, $data->refundNotes);
            $em->persist($evening);
            $em->flush();

            $this->addFlash('evening_created', true);

            return $this->redirectToRoute('evening_admin', ['adminToken' => $evening->getAdminToken()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('evening/new.html.twig', ['form' => $form]);
    }

    #[Route('/s/{adminToken}', name: 'evening_admin', requirements: ['adminToken' => '[0-9a-f]{64}'], methods: ['GET'])]
    public function admin(#[MapEntity(mapping: ['adminToken' => 'adminToken'])] Evening $evening): Response
    {
        return $this->render('evening/admin.html.twig', ['evening' => $evening]);
    }
}
