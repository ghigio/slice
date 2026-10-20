<?php

namespace App\Repository;

use App\Entity\Eater;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Eater>
 */
class EaterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Eater::class);
    }

    public function findOneByToken(string $token): ?Eater
    {
        return $this->findOneBy(['token' => $token]);
    }
}
