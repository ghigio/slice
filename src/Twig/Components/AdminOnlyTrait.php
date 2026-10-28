<?php

namespace App\Twig\Components;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Componenti dell'area di gestione: da usare con #[AsLiveComponent(route: 'gestione_live_component')].
 * Le azioni si possono chiamare anche dalla rotta pubblica /_components, fuori dal firewall: il ruolo si verifica
 * quindi a ogni montaggio e a ogni richiesta del componente.
 */
trait AdminOnlyTrait
{
    private Security $security;

    #[Required]
    public function setSecurity(Security $security): void
    {
        $this->security = $security;
    }

    #[PostMount]
    #[PostHydrate]
    public function assertAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedHttpException();
        }
    }
}
