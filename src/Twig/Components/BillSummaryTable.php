<?php

namespace App\Twig\Components;

use App\Entity\Evening;
use App\Evening\BillManagement;
use App\Evening\BillSummary;
use App\Evening\Exception\EveningRuleException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Riepilogo quote e rimborsi (pagina admin), visibile con conto inserito o serata archiviata.
 */
#[AsLiveComponent]
final class BillSummaryTable
{
    use DefaultActionTrait;

    #[LiveProp]
    public Evening $evening;

    public ?string $error = null;

    public function __construct(
        private readonly BillManagement $bills,
        private readonly ClockInterface $clock,
    ) {
    }

    #[LiveAction]
    public function toggleReimbursed(#[LiveArg] int $eaterId, #[LiveArg] bool $reimbursed): void
    {
        try {
            $this->bills->setReimbursed($this->evening, $eaterId, $reimbursed, $this->clock->now());
        } catch (EveningRuleException $e) {
            $this->error = $e->getMessage();
        }
    }

    #[LiveListener('billUpdated')]
    public function refresh(): void
    {
    }

    public function getSummary(): ?BillSummary
    {
        return BillSummary::forEvening($this->evening);
    }
}
