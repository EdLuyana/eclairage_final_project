<?php

namespace App\Repository;

use App\Entity\LabelPrintState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LabelPrintState>
 */
class LabelPrintStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LabelPrintState::class);
    }

    /**
     * Retourne l’unique enregistrement LabelPrintState.
     * Le crée automatiquement s'il n'existe pas encore.
     */
    public function getSingleton(): LabelPrintState
    {
        $state = $this->createQueryBuilder('s')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$state) {
            $state = new LabelPrintState();
            $state->setLastPosition(0);

            $em = $this->getEntityManager();
            $em->persist($state);
            $em->flush();
        }

        return $state;
    }
}
