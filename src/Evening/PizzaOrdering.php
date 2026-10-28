<?php

namespace App\Evening;

use App\Discount\DiscountCalculator;
use App\Discount\Exception\NegativeDiscountedPriceException;
use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use App\Enum\EveningStatus;
use App\Evening\Exception\EveningRuleException;
use App\Money\InvalidPriceException;
use App\Money\MoneyFormatter;
use App\Money\PriceParser;
use App\Text\NameNormalizer;
use App\Text\PreferredForm;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Inserimento, modifica ed eliminazione delle pizze di un gruppo, con le regole di stato verificate lato server.
 * Il prezzo è facoltativo finché non si inserisce il conto e lo inserisce solo l'organizzatore: le pizze dei gruppi
 * prendono l'ultimo prezzo noto per quel nome nella serata, i prezzi mancanti li completa l'organizzatore.
 * Il gruppo può modificare solo con ordini aperti; l'organizzatore in ogni stato tranne «Archiviata».
 */
final class PizzaOrdering
{
    public const int MAX_PIZZAS_PER_EVENING = 300;
    private const int MAX_PERSON_LENGTH = 60;
    private const int MAX_PIZZA_NAME_LENGTH = 80;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function addPizza(Eater $eater, string $person, string $pizzaName, string $price, bool $asOrganizer): Pizza
    {
        $evening = $eater->getEvening();
        $this->assertCanEdit($evening, $asOrganizer);
        [$person, $pizzaName, $priceCents] = $this->validate($evening, $person, $pizzaName, $asOrganizer ? $price : null);
        $priceCents ??= $asOrganizer ? null : $this->knownPriceFor($evening, NameNormalizer::normalize($pizzaName));
        if ($this->countPizzas($evening) >= self::MAX_PIZZAS_PER_EVENING) {
            throw new EveningRuleException(sprintf('La serata ha raggiunto il limite di %d pizze.', self::MAX_PIZZAS_PER_EVENING));
        }
        $this->assertBillStillComputable($evening, fn (array $prices) => $prices + ['new' => $priceCents], ['new' => [$pizzaName, $person, $eater->getName()]]);

        $pizza = new Pizza($eater, $person, $pizzaName, $priceCents);
        $this->em->persist($pizza);
        $this->em->flush();

        return $pizza;
    }

    public function updatePizza(Eater $eater, int $pizzaId, string $person, string $pizzaName, string $price, bool $asOrganizer): void
    {
        $this->assertCanEdit($eater->getEvening(), $asOrganizer);
        $pizza = $this->pizza($eater, $pizzaId);
        [$person, $pizzaName, $priceCents] = $this->validate($eater->getEvening(), $person, $pizzaName, $asOrganizer ? $price : null);
        if (!$asOrganizer) {
            $normalizedName = NameNormalizer::normalize($pizzaName);
            $priceCents = $normalizedName === $pizza->getNormalizedName()
                ? $pizza->getPriceCents()
                : $this->knownPriceFor($eater->getEvening(), $normalizedName);
        }
        $key = spl_object_id($pizza);
        $this->assertBillStillComputable($eater->getEvening(), fn (array $prices) => array_replace($prices, [$key => $priceCents]), [$key => [$pizzaName, $person, $eater->getName()]]);

        $pizza->setPerson($person);
        $pizza->setPizzaName($pizzaName);
        $pizza->setPriceCents($priceCents);
        $this->em->flush();
    }

    public function deletePizza(Eater $eater, int $pizzaId, bool $asOrganizer): void
    {
        $this->assertCanEdit($eater->getEvening(), $asOrganizer);
        $pizza = $this->pizza($eater, $pizzaId);
        $key = spl_object_id($pizza);
        $this->assertBillStillComputable($eater->getEvening(), fn (array $prices) => array_diff_key($prices, [$key => true]), []);

        $eater->removePizza($pizza);
        $this->em->remove($pizza);
        $this->em->flush();
    }

    /**
     * L'organizzatore completa i prezzi mancanti: il prezzo vale per tutte le pizze senza prezzo con quel nome.
     *
     * @return int numero di pizze aggiornate
     */
    public function setMissingPrices(Evening $evening, string $normalizedName, string $price): int
    {
        $this->assertCanEdit($evening, true);
        if ('' === trim($price)) {
            throw new EveningRuleException('Inserisci il prezzo.');
        }
        try {
            $priceCents = PriceParser::parseCents($price);
        } catch (InvalidPriceException $e) {
            throw new EveningRuleException($e->getMessage(), 0, $e);
        }

        $updated = 0;
        foreach ($evening->getEaters() as $eater) {
            foreach ($eater->getPizzas() as $pizza) {
                if (!$pizza->hasPrice() && $pizza->getNormalizedName() === $normalizedName) {
                    $pizza->setPriceCents($priceCents);
                    ++$updated;
                }
            }
        }
        $this->em->flush();

        return $updated;
    }

    /**
     * L'organizzatore unisce due pizze (es. «Salamino piccante» in «Salamino»): le pizze unite prendono il nome mostrato
     * della destinazione e il suo ultimo prezzo noto; se la destinazione non ha prezzo mantengono il loro.
     *
     * @return int numero di pizze unite
     */
    public function mergePizzas(Evening $evening, string $fromNormalizedName, string $intoNormalizedName): int
    {
        $this->assertCanEdit($evening, true);
        if ($fromNormalizedName === $intoNormalizedName) {
            throw new EveningRuleException('Scegli due pizze diverse.');
        }

        $from = [];
        $intoForms = [];
        $intoPrice = null;
        foreach ($this->em->getRepository(Pizza::class)->findForEvening($evening) as $pizza) {
            if ($pizza->getNormalizedName() === $fromNormalizedName) {
                $from[] = $pizza;
            } elseif ($pizza->getNormalizedName() === $intoNormalizedName) {
                $intoForms[] = $pizza->getPizzaName();
                $intoPrice = $pizza->getPriceCents() ?? $intoPrice;
            }
        }
        if ([] === $from || [] === $intoForms) {
            throw new EveningRuleException('Pizza non trovata.');
        }

        if (null !== $intoPrice) {
            $changed = array_fill_keys(array_map(spl_object_id(...), $from), $intoPrice);
            $this->assertBillStillComputable($evening, fn (array $prices) => array_replace($prices, $changed), []);
        }

        $intoName = PreferredForm::pick($intoForms);
        foreach ($from as $pizza) {
            $pizza->setPizzaName($intoName);
            $pizza->setPriceCents($intoPrice ?? $pizza->getPriceCents());
        }
        $this->em->flush();

        return \count($from);
    }

    public static function canEdit(Evening $evening, bool $asOrganizer): bool
    {
        return $asOrganizer ? $evening->canManageEaters() : EveningStatus::OrdersOpen === $evening->getStatus();
    }

    private function assertCanEdit(Evening $evening, bool $asOrganizer): void
    {
        if (self::canEdit($evening, $asOrganizer)) {
            return;
        }

        throw new EveningRuleException($asOrganizer
            ? 'La serata è archiviata: riaprila per modificarla.'
            : "Ordini chiusi dall'organizzatore.");
    }

    /**
     * Con il conto inserito le quote devono restare calcolabili: simula la modifica sui prezzi e la rifiuta se
     * non restano pizze o se, in modalità uguale, una pizza andrebbe sotto zero.
     *
     * @param callable(array<int|string, int>): array<int|string, int> $change    prezzi attuali => prezzi dopo la modifica
     * @param array<int|string, array{string, string, string}>       $described nome, persona e gruppo aggiornati delle pizze toccate
     */
    private function assertBillStillComputable(Evening $evening, callable $change, array $described): void
    {
        if (!$evening->hasBill()) {
            return;
        }

        $prices = [];
        $current = [];
        foreach ($evening->getEaters() as $eater) {
            foreach ($eater->getPizzas() as $pizza) {
                $prices[spl_object_id($pizza)] = $pizza->getPriceCents();
                $current[spl_object_id($pizza)] = [$pizza->getPizzaName(), $pizza->getPerson(), $eater->getName()];
            }
        }
        $prices = $change($prices);
        if ([] === $prices) {
            throw new EveningRuleException('Con il conto inserito deve restare almeno una pizza.');
        }

        try {
            (new DiscountCalculator())->calculate($prices, $evening->getPaidTotalCents(), $evening->getDiscountMode());
        } catch (NegativeDiscountedPriceException $e) {
            [$name, $person, $eaterName] = $described[$e->pizzaKey] ?? $current[$e->pizzaKey];
            throw new EveningRuleException(sprintf(
                'Con questa modifica, in modalità uguale, la pizza %s di %s (%s) da %s andrebbe a %s: passa alla modalità proporzionale.',
                $name, $person, $eaterName, MoneyFormatter::format($e->listPriceCents), MoneyFormatter::format($e->discountedPriceCents),
            ), 0, $e);
        }
    }

    private function pizza(Eater $eater, int $pizzaId): Pizza
    {
        foreach ($eater->getPizzas() as $pizza) {
            if ($pizza->getId() === $pizzaId) {
                return $pizza;
            }
        }

        throw new EveningRuleException('Pizza non trovata.');
    }

    /**
     * Ultimo prezzo inserito per quel nome nella serata (l'organizzatore può averlo corretto); altrimenti il prezzo
     * del menu della pizzeria nota; null se non c'è nessuno dei due.
     */
    private function knownPriceFor(Evening $evening, string $normalizedName): ?int
    {
        $known = null;
        foreach ($this->em->getRepository(Pizza::class)->findForEvening($evening) as $pizza) {
            if ($pizza->hasPrice() && $pizza->getNormalizedName() === $normalizedName) {
                $known = $pizza->getPriceCents();
            }
        }
        if (null !== $known) {
            return $known;
        }

        foreach ($evening->getKnownPizzeria()?->getMenuPizzas() ?? [] as $menuPizza) {
            if ($menuPizza->getNormalizedName() === $normalizedName) {
                return $menuPizza->getPriceCents();
            }
        }

        return null;
    }

    /**
     * @param ?string $price null per i gruppi, che non inseriscono il prezzo
     *
     * @return array{string, string, ?int} prezzo facoltativo, obbligatorio con il conto inserito
     */
    private function validate(Evening $evening, string $person, string $pizzaName, ?string $price): array
    {
        $person = NameNormalizer::clean($person);
        $pizzaName = NameNormalizer::clean($pizzaName);

        if ('' === $person) {
            throw new EveningRuleException('Il nome della persona è obbligatorio.');
        }
        if (mb_strlen($person) > self::MAX_PERSON_LENGTH) {
            throw new EveningRuleException(sprintf('Il nome della persona può avere al massimo %d caratteri.', self::MAX_PERSON_LENGTH));
        }
        if ('' === $pizzaName) {
            throw new EveningRuleException('Il nome della pizza è obbligatorio.');
        }
        if (mb_strlen($pizzaName) > self::MAX_PIZZA_NAME_LENGTH) {
            throw new EveningRuleException(sprintf('Il nome della pizza può avere al massimo %d caratteri.', self::MAX_PIZZA_NAME_LENGTH));
        }

        if (null === $price) {
            return [$person, $pizzaName, null];
        }
        try {
            $priceCents = PriceParser::parseOptionalCents($price);
        } catch (InvalidPriceException $e) {
            throw new EveningRuleException($e->getMessage(), 0, $e);
        }
        if (null === $priceCents && $evening->hasBill()) {
            throw new EveningRuleException('Con il conto inserito ogni pizza deve avere un prezzo.');
        }

        return [$person, $pizzaName, $priceCents];
    }

    private function countPizzas(Evening $evening): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(p.id) FROM App\Entity\Pizza p JOIN p.eater e WHERE e.evening = :evening')
            ->setParameter('evening', $evening)
            ->getSingleScalarResult();
    }
}
