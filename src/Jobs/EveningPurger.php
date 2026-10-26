<?php

namespace App\Jobs;

use App\Entity\Evening;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cancella le serate 90 giorni dopo la loro data (gruppi e pizze a cascata nel database).
 */
final class EveningPurger
{
    public const int RETENTION_DAYS = 90;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return int numero di serate cancellate */
    public function purge(\DateTimeImmutable $today): int
    {
        $limit = $today->setTime(0, 0)->modify(sprintf('-%d days', self::RETENTION_DAYS));

        return $this->em->createQueryBuilder()
            ->delete(Evening::class, 'e')
            ->where('e.date <= :limit')
            ->setParameter('limit', $limit, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->execute();
    }
}
