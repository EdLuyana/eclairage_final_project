<?php

namespace App\Entity;

use App\Repository\ReservationRepository;
use App\Entity\Product;
use App\Entity\Size;
use App\Entity\Location;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
class Reservation
{
    public const STATUS_PENDING   = 'PENDING';   // Demande créée, produit pas encore mis de côté
    public const STATUS_CONFIRMED = 'CONFIRMED'; // Produit mis de côté
    public const STATUS_COMPLETED = 'COMPLETED'; // Client venu, vente effectuée
    public const STATUS_CANCELLED = 'CANCELLED'; // Annulée
    public const STATUS_EXPIRED   = 'EXPIRED';   // Optionnel si tu veux gérer les dates limites

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    private ?Product $product = null;

    #[ORM\ManyToOne(targetEntity: Size::class)]
    private ?Size $size = null;

    /**
     * Magasin où se trouve physiquement le produit (là où il sera mis en réserve).
     */
    #[ORM\ManyToOne(targetEntity: Location::class)]
    private ?Location $location = null;

    /**
     * Magasin qui a demandé la réservation (celui où la vendeuse a pris la demande).
     */
    #[ORM\ManyToOne(targetEntity: Location::class)]
    private ?Location $requestedByLocation = null;

    #[ORM\Column(type: 'integer')]
    private int $quantity = 1;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $createdBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customerName = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $customerPhone = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->status = self::STATUS_PENDING;
        $this->quantity = 1;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getSize(): ?Size
    {
        return $this->size;
    }

    public function setSize(?Size $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function setLocation(?Location $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getRequestedByLocation(): ?Location
    {
        return $this->requestedByLocation;
    }

    public function setRequestedByLocation(?Location $requestedByLocation): static
    {
        $this->requestedByLocation = $requestedByLocation;

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = max(1, $quantity);

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCustomerName(): ?string
    {
        return $this->customerName;
    }

    public function setCustomerName(?string $customerName): static
    {
        $this->customerName = $customerName;

        return $this;
    }

    public function getCustomerPhone(): ?string
    {
        return $this->customerPhone;
    }

    public function setCustomerPhone(?string $customerPhone): static
    {
        $this->customerPhone = $customerPhone;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }
}
