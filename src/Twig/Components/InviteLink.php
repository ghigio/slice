<?php

namespace App\Twig\Components;

use App\Entity\Evening;
use App\Evening\EveningAdministration;
use App\Evening\Exception\EveningRuleException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Link d'invito nella pagina admin: copia, condivisione su WhatsApp e rigenerazione.
 */
#[AsLiveComponent]
final class InviteLink
{
    use DefaultActionTrait;

    #[LiveProp]
    public Evening $evening;

    public ?string $error = null;
    public ?string $notice = null;

    public function __construct(
        private readonly EveningAdministration $administration,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[LiveAction]
    public function regenerate(): void
    {
        try {
            $this->administration->regenerateInviteToken($this->evening);
            $this->notice = "Nuovo link d'invito creato: il precedente non funziona più.";
        } catch (EveningRuleException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function inviteUrl(): string
    {
        return $this->urlGenerator->generate('evening_invite', ['inviteToken' => $this->evening->getInviteToken()], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function whatsappUrl(): string
    {
        $message = sprintf('Slice – %s: aggiungi il tuo gruppo e ordina le pizze qui: %s', $this->evening->getName(), $this->inviteUrl());

        return 'https://wa.me/?text='.rawurlencode($message);
    }
}
