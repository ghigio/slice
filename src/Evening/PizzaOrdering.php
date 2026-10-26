<?php

namespace App\Evening;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use App\Enum\EveningStatus;
use App\Evening\Exception\EveningRuleException;
use App\Money\InvalidPriceException;
use App\Money\PriceParser;
use App\Text\NameNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Inserimento, modifica ed eliminazione delle pizze di un gruppo, con le regole di stato verificate lato server.
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
        [$person, $pizzaName, $priceCents] = $this->validate($person, $pizzaName, $price);
        if ($this->countPizzas($evening) >= self::MAX_PIZZAS_PER_EVENING) {
            throw new EveningRuleException(sprintf('La serata ha raggiunto il limite di %d pizze.', self::MAX_PIZZAS_PER_EVENING));
        }

        $pizza = new Pizza($eater, $person, $pizzaName, $priceCents);
        $this->em->persist($pizza);
        $this->em->flush();

        return $pizza;
    }

    public function updatePizza(Eater $eater, int $pizzaId, string $person, string $pizzaName, string $price, bool $asOrganizer): void
    {
        $this->assertCanEdit($eater->getEvening(), $asOrganizer);
        $pizza = $this->pizza($eater, $pizzaId);
        [$person, $pizzaName, $priceCents] = $this->validate($person, $pizzaName, $price);

        $pizza->setPerson($person);
        $pizza->setPizzaName($pizzaName);
        $pizza->setPriceCents($priceCents);
        $this->em->flush();
    }

    public function deletePizza(Eater $eater, int $pizzaId, bool $asOrganizer): void
    {
        $this->assertCanEdit($eater->getEvening(), $asOrganizer);
        $pizza = $this->pizza($eater, $pizzaId);

        $eater->removePizza($pizza);
        $this->em->remove($pizza);
        $this->em->flush();
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

    private function pizza(Eater $eater, int $pizzaId): Pizza
    {
        foreach ($eater->getPizzas() as $pizza) {
            if ($pizza->getId() === $pizzaId) {
                return $pizza;
            }
        }

        throw new EveningRuleException('Pizza non trovata.');
    }

    /** @return array{string, string, int} */
    private function validate(string $person, string $pizzaName, string $price): array
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

        try {
            $priceCents = PriceParser::parseCents($price);
        } catch (InvalidPriceException $e) {
            throw new EveningRuleException($e->getMessage(), 0, $e);
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
