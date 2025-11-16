<?php

namespace App\Repository;

use App\Entity\TransferRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TransferRequest>
 */
class TransferRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TransferRequest::class);
    }

    /**
     * @return TransferRequest[]
     */
    public function findOutgoingForLocation(int $locationId): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.fromLocation = :location')
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('location', $locationId)
            ->setParameter('statuses', [
                TransferRequest::STATUS_REQUESTED,
                TransferRequest::STATUS_PREPARED,
            ])
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
