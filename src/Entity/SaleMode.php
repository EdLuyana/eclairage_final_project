<?php

namespace App\Entity;

use App\Repository\SaleModeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SaleModeRepository::class)]
class SaleMode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $discountEnabled = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isDiscountEnabled(): bool
    {
        $now = new \DateTimeImmutable();

        if ($this->startsAt && $now < $this->startsAt) {
            return false;
        }

        if ($this->endsAt && $now > $this->endsAt) {
            return false;
        }

        return $this->discountEnabled;
    }

    public function setDiscountEnabled(bool $discountEnabled): self
    {
        $this->discountEnabled = $discountEnabled;

        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): self
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(?\DateTimeImmutable $endsAt): self
    {
        $this->endsAt = $endsAt;

        return $this;
    }
}
