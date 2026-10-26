<?php

namespace App\Twig\Components;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Evening\EveningAdministration;
use App\Evening\Exception\EveningRuleException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Gestione dei gruppi della serata (pagina admin): aggiunta, rinomina, eliminazione, gruppo dell'organizzatore e link.
 * Gli id ricevuti dal browser vengono verificati da EveningAdministration contro la serata del componente.
 */
#[AsLiveComponent]
final class EaterManager
{
    use DefaultActionTrait;

    #[LiveProp]
    public Evening $evening;

    #[LiveProp(writable: true)]
    public string $newName = '';

    #[LiveProp(writable: true)]
    public ?int $editingId = null;

    #[LiveProp(writable: true)]
    public string $editingName = '';

    public ?string $error = null;
    public ?string $notice = null;

    public function __construct(
        private readonly EveningAdministration $administration,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[LiveAction]
    public function add(): void
    {
        $this->apply(function () {
            $eater = $this->administration->addEater($this->evening, $this->newName);
            $this->newName = '';

            return sprintf('Gruppo «%s» aggiunto.', $eater->getName());
        });
    }

    #[LiveAction]
    public function startEdit(#[LiveArg] int $eaterId): void
    {
        foreach ($this->evening->getEaters() as $eater) {
            if ($eater->getId() === $eaterId) {
                $this->editingId = $eaterId;
                $this->editingName = $eater->getName();
            }
        }
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editingName = '';
    }

    #[LiveAction]
    public function rename(): void
    {
        if (null === $this->editingId) {
            return;
        }
        $this->apply(function () {
            $this->administration->renameEater($this->evening, $this->editingId, $this->editingName);
            $this->cancelEdit();

            return 'Gruppo rinominato.';
        });
    }

    #[LiveAction]
    public function delete(#[LiveArg] int $eaterId, #[LiveArg] bool $confirmed = false): void
    {
        $this->apply(function () use ($eaterId, $confirmed) {
            $this->administration->deleteEater($this->evening, $eaterId, $confirmed);

            return 'Gruppo eliminato.';
        });
    }

    #[LiveAction]
    public function setOrganizer(#[LiveArg] ?int $eaterId = null): void
    {
        $this->apply(function () use ($eaterId) {
            $this->administration->setOrganizerEater($this->evening, $eaterId);

            return null === $eaterId ? 'Nessun gruppo indicato come tuo.' : 'Gruppo indicato come tuo.';
        });
    }

    #[LiveAction]
    public function regenerate(#[LiveArg] int $eaterId): void
    {
        $this->apply(function () use ($eaterId) {
            $this->administration->regenerateEaterToken($this->evening, $eaterId);

            return 'Nuovo link creato: il precedente non funziona più.';
        });
    }

    public function eaterUrl(Eater $eater): string
    {
        return $this->urlGenerator->generate('eater_show', ['token' => $eater->getToken()], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function whatsappUrl(Eater $eater): string
    {
        $message = sprintf('Slice – %s: ecco il link per ordinare le pizze di %s: %s', $this->evening->getName(), $eater->getName(), $this->eaterUrl($eater));

        return 'https://wa.me/?text='.rawurlencode($message);
    }

    /** @param callable(): string $operation restituisce il messaggio di conferma */
    private function apply(callable $operation): void
    {
        try {
            $this->notice = $operation();
        } catch (EveningRuleException $e) {
            $this->error = $e->getMessage();
        }
    }
}
