<?php

namespace App\Service;

use App\Entity\Location;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class CurrentLocationService
{
    private const SESSION_KEY = 'current_location_id';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $em
    ) {}

    public function setLocation(Location $location): void
    {
        $this->requestStack
            ->getSession()
            ->set(self::SESSION_KEY, $location->getId());
    }

    public function getLocation(): ?Location
    {
        $session = $this->requestStack->getSession();

        if (!$session->has(self::SESSION_KEY)) {
            return null;
        }

        $id = $session->get(self::SESSION_KEY);

        return $this->em->getRepository(Location::class)->find($id);
    }

    public function clear(): void
    {
        $this->requestStack
            ->getSession()
            ->remove(self::SESSION_KEY);
    }
}
