<?php

namespace App\Repository;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Entity\Pizza;
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
     * forma digitata più frequente (a parità, la più recente) e ultimo prezzo usato. Ordinate per nome.
     *
     * @return list<array{name: string, priceCents: int}>
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

        // Per ogni nome normalizzato: conteggio e ultima posizione di ogni forma digitata, ultimo prezzo.
        $groups = [];
        foreach ($rows as $position => $row) {
            $key = $row['normalizedName'];
            $form = $row['pizzaName'];
            $groups[$key]['count'][$form] = ($groups[$key]['count'][$form] ?? 0) + 1;
            $groups[$key]['lastSeen'][$form] = $position;
            $groups[$key]['priceCents'] = $row['priceCents'];
        }

        $suggestions = [];
        foreach ($groups as $group) {
            $forms = array_keys($group['count']);
            usort($forms, fn ($a, $b) => [$group['count'][$b], $group['lastSeen'][$b]] <=> [$group['count'][$a], $group['lastSeen'][$a]]);
            $suggestions[] = ['name' => (string) $forms[0], 'priceCents' => $group['priceCents']];
        }
        usort($suggestions, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $suggestions;
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
}
