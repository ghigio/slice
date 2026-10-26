<?php

namespace App\Twig\Components;

use App\Entity\Evening;
use App\Enum\DiscountMode;
use App\Enum\EveningStatus;
use App\Evening\BillManagement;
use App\Evening\BillSummary;
use App\Evening\Exception\EveningRuleException;
use App\Money\MoneyFormatter;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Inserimento e modifica del conto (pagina admin): totale pagato, modalità, anteprima delle quote prima di confermare.
 */
#[AsLiveComponent]
final class BillForm
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public Evening $evening;

    #[LiveProp(writable: true)]
    public string $paidTotal = '';

    #[LiveProp(writable: true)]
    public string $mode = 'proportional';

    #[LiveProp(writable: true)]
    public bool $surchargeConfirmed = false;

    public ?string $error = null;
    public ?string $notice = null;

    private bool $previewComputed = false;
    private ?BillSummary $preview = null;
    private ?string $previewError = null;

    public function __construct(private readonly BillManagement $bills)
    {
    }

    public function mount(Evening $evening): void
    {
        $this->evening = $evening;
        if (null !== $evening->getPaidTotalCents()) {
            $this->paidTotal = MoneyFormatter::formatInput($evening->getPaidTotalCents());
            $this->mode = $evening->getDiscountMode()->value;
        }
    }

    #[LiveAction]
    public function save(): void
    {
        $wasEntered = EveningStatus::BillEntered === $this->evening->getStatus();
        try {
            $this->bills->enterBill($this->evening, $this->paidTotal, $this->discountMode(), $this->surchargeConfirmed);
            $this->notice = $wasEntered ? 'Conto aggiornato: le quote sono state ricalcolate.' : 'Conto inserito: ora i gruppi vedono la propria quota.';
            $this->previewComputed = false;
            $this->emit('billUpdated');
        } catch (EveningRuleException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Prezzi mancanti completati dall'organizzatore: l'anteprima va ricalcolata. */
    #[LiveListener('pricesUpdated')]
    public function refresh(): void
    {
        $this->previewComputed = false;
    }

    public function canEnterBill(): bool
    {
        return \in_array($this->evening->getStatus(), [EveningStatus::OrdersClosed, EveningStatus::BillEntered], true);
    }

    public function isUpdate(): bool
    {
        return EveningStatus::BillEntered === $this->evening->getStatus();
    }

    public function listTotalCents(): int
    {
        $total = 0;
        foreach ($this->evening->getEaters() as $eater) {
            foreach ($eater->getPizzas() as $pizza) {
                $total += $pizza->getPriceCents() ?? 0;
            }
        }

        return $total;
    }

    public function getPreview(): ?BillSummary
    {
        $this->computePreview();

        return $this->preview;
    }

    public function getPreviewError(): ?string
    {
        $this->computePreview();

        return $this->previewError;
    }

    private function computePreview(): void
    {
        if ($this->previewComputed) {
            return;
        }
        $this->previewComputed = true;
        $this->preview = $this->previewError = null;
        if ('' === trim($this->paidTotal) || !$this->canEnterBill()) {
            return;
        }
        try {
            $this->preview = $this->bills->preview($this->evening, $this->paidTotal, $this->discountMode());
        } catch (EveningRuleException $e) {
            $this->previewError = $e->getMessage();
        }
    }

    private function discountMode(): DiscountMode
    {
        return DiscountMode::tryFrom($this->mode) ?? DiscountMode::Proportional;
    }
}
