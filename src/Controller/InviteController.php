<?php

namespace App\Controller;

use App\Entity\Evening;
use App\Enum\EveningStatus;
use App\Evening\EveningAdministration;
use App\Evening\Exception\EveningRuleException;
use App\Form\InviteEaterType;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Link d'invito della serata: mostra i nomi dei gruppi e permette di aggiungere il proprio (solo con ordini aperti).
 */
final class InviteController extends AbstractController
{
    #[Route('/i/{inviteToken}', name: 'evening_invite', requirements: ['inviteToken' => '[0-9a-f]{64}'], methods: ['GET', 'POST'])]
    public function show(
        #[MapEntity(mapping: ['inviteToken' => 'inviteToken'])] Evening $evening,
        Request $request,
        EveningAdministration $administration,
    ): Response {
        $form = $this->createForm(InviteEaterType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $eater = $administration->addEaterFromInvite($evening, (string) $form->get('name')->getData());
                $this->addFlash('eater_created', true);

                return $this->redirectToRoute('eater_show', ['token' => $eater->getToken()], Response::HTTP_SEE_OTHER);
            } catch (EveningRuleException $e) {
                $form->get('name')->addError(new FormError($e->getMessage()));
            }
        }

        $eaters = $evening->getEaters()->map(fn ($eater) => $eater->getName())->toArray();
        sort($eaters, \SORT_NATURAL | \SORT_FLAG_CASE);

        return $this->render('invite/show.html.twig', [
            'evening' => $evening,
            'eaters' => $eaters,
            'form' => $form,
            'can_join' => EveningStatus::OrdersOpen === $evening->getStatus(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
