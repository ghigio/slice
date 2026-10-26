<?php

namespace App\Repository;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
use App\Text\PreferredForm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pizza>
 */
class PizzaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pizza::class);
    }

    /**
     * Pizze già inserite nella serata da qualsiasi gruppo, una per nome normalizzato:
     * forma digitata più frequente (a parità, la più recente) e ultimo prezzo noto (null se mai inserito). Ordinate per nome.
     *
     * @return list<array{name: string, priceCents: ?int}>
     */
    public function suggestionsForEvening(Evening $evening): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.pizzaName, p.normalizedName, p.priceCents')
            ->join('p.eater', 'e')
            ->where('e.evening = :evening')
            ->setParameter('evening', $evening)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['normalizedName']]['forms'][] = $row['pizzaName'];
            $groups[$row['normalizedName']]['priceCents'] ??= null;
            if (null !== $row['priceCents']) {
                $groups[$row['normalizedName']]['priceCents'] = $row['priceCents'];
            }
        }

        $suggestions = [];
        foreach ($groups as $group) {
            $suggestions[] = ['name' => PreferredForm::pick($group['forms']), 'priceCents' => $group['priceCents']];
        }
        usort($suggestions, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $suggestions;
    }

    /**
     * Pizze senza prezzo della serata, raggruppate per nome normalizzato (nome mostrato come nella lista per il pizzaiolo).
     *
     * @return list<array{name: string, normalizedName: string, quantity: int}>
     */
    public function missingPricesForEvening(Evening $evening): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.pizzaName, p.normalizedName, p.priceCents')
            ->join('p.eater', 'e')
            ->where('e.evening = :evening')
            ->setParameter('evening', $evening)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['normalizedName']]['forms'][] = $row['pizzaName'];
            $groups[$row['normalizedName']]['missing'] = ($groups[$row['normalizedName']]['missing'] ?? 0) + (null === $row['priceCents'] ? 1 : 0);
        }

        $missing = [];
        foreach ($groups as $normalizedName => $group) {
            if ($group['missing'] > 0) {
                $missing[] = ['name' => PreferredForm::pick($group['forms']), 'normalizedName' => (string) $normalizedName, 'quantity' => $group['missing']];
            }
        }
        usort($missing, fn ($a, $b) => strcmp($a['normalizedName'], $b['normalizedName']));

        return $missing;
    }

    /** @return list<string> persone già usate nel gruppo, in ordine alfabetico */
    public function personsForEater(Eater $eater): array
    {
        return array_column($this->createQueryBuilder('p')
            ->select('DISTINCT p.person')
            ->where('p.eater = :eater')
            ->setParameter('eater', $eater)
            ->orderBy('p.person', 'ASC')
            ->getQuery()
            ->getArrayResult(), 'person');
    }

    /** @return list<Pizza> pizze della serata in ordine di inserimento, con il gruppo già caricato */
    public function findForEvening(Evening $evening): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('e')
            ->join('p.eater', 'e')
            ->where('e.evening = :evening')
            ->setParameter('evening', $evening)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
