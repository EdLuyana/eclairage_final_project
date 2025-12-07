<?php

namespace App\Entity;

use App\Repository\LabelPrintStateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LabelPrintStateRepository::class)]
class LabelPrintState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Position de la prochaine case libre sur la feuille.
     * Valeur entre 0 et 55 (56 cases par page).
     */
    #[ORM\Column(type: 'integer')]
    private int $lastPosition = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLastPosition(): int
    {
        return $this->lastPosition;
    }

    public function setLastPosition(int $pos): self
    {
        $this->lastPosition = $pos;
        return $this;
    }
}
