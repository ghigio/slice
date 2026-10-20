<?php

namespace App\Repository;

use App\Entity\Evening;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Evening>
 */
class EveningRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evening::class);
    }

    public function findOneByAdminToken(string $adminToken): ?Evening
    {
        return $this->findOneBy(['adminToken' => $adminToken]);
    }
}
