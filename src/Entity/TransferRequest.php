<?php

namespace App\Entity;

use App\Repository\TransferRequestRepository;
use App\Entity\Product;
use App\Entity\Size;
use App\Entity\Location;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransferRequestRepository::class)]
class TransferRequest
{
    public const STATUS_REQUESTED = 'REQUESTED'; // Demande créée par le magasin du client
    public const STATUS_PREPARED  = 'PREPARED';  // Produit scanné / mis de côté dans le magasin source
    public const STATUS_COMPLETED = 'COMPLETED'; // Produit reçu et intégré au stock du magasin destination
    public const STATUS_CANCELLED = 'CANCELLED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    private ?Product $product = null;

    #[ORM\ManyToOne(targetEntity: Size::class)]
    private ?Size $size = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    private ?Location $fromLocation = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    private ?Location $toLocation = null;

    #[ORM\Column(type: 'integer')]
    private int $quantity = 1;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_REQUESTED;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $createdBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customerName = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $customerPhone = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->status = self::STATUS_REQUESTED;
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

    public function getFromLocation(): ?Location
    {
        return $this->fromLocation;
    }

    public function setFromLocation(?Location $fromLocation): static
    {
        $this->fromLocation = $fromLocation;

        return $this;
    }

    public function getToLocation(): ?Location
    {
        return $this->toLocation;
    }

    public function setToLocation(?Location $toLocation): static
    {
        $this->toLocation = $toLocation;

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
}
