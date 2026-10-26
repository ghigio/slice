<?php

namespace App\Twig\Components;

use App\Entity\Evening;
use App\Evening\EveningAdministration;
use App\Evening\Exception\EveningRuleException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Stato della serata e pulsanti delle transizioni ammesse (pagina admin).
 */
#[AsLiveComponent]
final class EveningStatusPanel
{
    use DefaultActionTrait;

    #[LiveProp]
    public Evening $evening;

    public ?string $error = null;
    public ?string $notice = null;

    public function __construct(private readonly EveningAdministration $administration)
    {
    }

    #[LiveAction]
    public function closeOrders(): void
    {
        $this->apply(fn () => $this->administration->closeOrders($this->evening), 'Ordini chiusi.');
    }

    #[LiveAction]
    public function reopenOrders(): void
    {
        $this->apply(fn () => $this->administration->reopenOrders($this->evening), 'Ordini riaperti.');
    }

    #[LiveAction]
    public function archive(): void
    {
        $this->apply(fn () => $this->administration->archive($this->evening), 'Serata archiviata.');
    }

    #[LiveAction]
    public function unarchive(): void
    {
        $this->apply(fn () => $this->administration->unarchive($this->evening), 'Serata riaperta.');
    }

    private function apply(callable $operation, string $notice): void
    {
        try {
            $operation();
            $this->notice = $notice;
        } catch (EveningRuleException $e) {
            $this->error = $e->getMessage();
        }
    }
}
