<?php

namespace App\Repository;

use App\Entity\Pizzeria;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pizzeria>
 */
class PizzeriaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pizzeria::class);
    }

    /** @return list<Pizzeria> in ordine alfabetico */
    public function findAllSorted(): array
    {
        return $this->findBy([], ['normalizedName' => 'ASC']);
    }
}
