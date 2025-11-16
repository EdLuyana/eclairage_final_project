<?php

namespace App\Repository;

use App\Entity\Reservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    /**
     * @return Reservation[]
     */
    public function findOpenForLocation(int $locationId): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.location = :location')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('location', $locationId)
            ->setParameter('statuses', [
                Reservation::STATUS_PENDING,
                Reservation::STATUS_CONFIRMED,
            ])
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
