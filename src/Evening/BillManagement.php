<?php

namespace App\Evening;

use App\Discount\Exception\EmptyOrderException;
use App\Discount\Exception\NegativeDiscountedPriceException;
use App\Entity\Evening;
use App\Enum\DiscountMode;
use App\Enum\EveningStatus;
use App\Evening\Exception\EveningRuleException;
use App\Money\InvalidPriceException;
use App\Money\MoneyFormatter;
use App\Money\PriceParser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Conto della serata (anteprima, inserimento, modifica) e rimborsi dei gruppi, con le regole verificate lato server.
 */
final class BillManagement
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Calcola le quote senza salvare nulla. */
    public function preview(Evening $evening, string $paidTotal, DiscountMode $mode): BillSummary
    {
        if ($evening->isArchived()) {
            throw new EveningRuleException('La serata è archiviata: riaprila per modificarla.');
        }
        if (EveningStatus::OrdersOpen === $evening->getStatus()) {
            throw new EveningRuleException('Chiudi gli ordini prima di inserire il conto.');
        }

        $missing = 0;
        foreach ($evening->getEaters() as $eater) {
            foreach ($eater->getPizzas() as $pizza) {
                $missing += $pizza->hasPrice() ? 0 : 1;
            }
        }
        if ($missing > 0) {
            throw new EveningRuleException(sprintf('%s senza prezzo: completale prima di inserire il conto.', 1 === $missing ? "C'è 1 pizza" : "Ci sono $missing pizze"));
        }

        try {
            $paidTotalCents = PriceParser::parseTotalCents($paidTotal);
        } catch (InvalidPriceException $e) {
            throw new EveningRuleException($e->getMessage(), 0, $e);
        }

        try {
            return BillSummary::compute($evening, $paidTotalCents, $mode);
        } catch (EmptyOrderException $e) {
            throw new EveningRuleException('Nessuna pizza: il conto non si può inserire.', 0, $e);
        } catch (NegativeDiscountedPriceException $e) {
            $pizza = BillSummary::pizzaForException($evening, $e);
            throw new EveningRuleException(sprintf(
                'In modalità uguale la pizza %s di %s (%s) da %s andrebbe a %s: usa la modalità proporzionale.',
                $pizza->getPizzaName(), $pizza->getPerson(), $pizza->getEater()->getName(),
                MoneyFormatter::format($e->listPriceCents), MoneyFormatter::format($e->discountedPriceCents),
            ), 0, $e);
        }
    }

    /** Inserisce o modifica il conto; se il totale pagato supera il totale da listino serve la conferma esplicita. */
    public function enterBill(Evening $evening, string $paidTotal, DiscountMode $mode, bool $surchargeConfirmed): BillSummary
    {
        $summary = $this->preview($evening, $paidTotal, $mode);

        if ($summary->isSurcharge() && !$surchargeConfirmed) {
            throw new EveningRuleException(sprintf(
                "Il totale pagato (%s) è maggiore del totale da listino (%s): controlla l'importo e conferma per continuare.",
                MoneyFormatter::format($summary->paidTotalCents()), MoneyFormatter::format($summary->listTotalCents()),
            ));
        }

        $evening->enterBill($summary->paidTotalCents(), $mode);
        $this->em->flush();

        return $summary;
    }

    public function setReimbursed(Evening $evening, int $eaterId, bool $reimbursed, \DateTimeImmutable $now): void
    {
        if (!$evening->canRecordReimbursements() || !$evening->hasBill()) {
            throw new EveningRuleException('I rimborsi si segnano dopo aver inserito il conto.');
        }

        $eater = null;
        foreach ($evening->getEaters() as $candidate) {
            if ($candidate->getId() === $eaterId) {
                $eater = $candidate;
            }
        }
        if (null === $eater) {
            throw new EveningRuleException('Gruppo non trovato.');
        }
        if (BillSummary::forEvening($evening)->share($eater)->owesNothing()) {
            throw new EveningRuleException('Questo gruppo non deve rimborsare nulla.');
        }

        $reimbursed ? $eater->markReimbursed($now) : $eater->clearReimbursed();
        $this->em->flush();
    }
}
