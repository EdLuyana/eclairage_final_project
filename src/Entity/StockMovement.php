<?php

namespace App\Entity;

use App\Repository\StockMovementRepository;
use App\Entity\Product;
use App\Entity\Size;
use App\Entity\Location;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StockMovementRepository::class)]
class StockMovement
{
    /**
     * Types de mouvement standardisés
     */
    public const TYPE_SALE             = 'SALE';
    public const TYPE_RETURN           = 'RETURN';
    public const TYPE_REASSORT         = 'REASSORT';
    public const TYPE_TRANSFER_OUT     = 'TRANSFER_OUT';
    public const TYPE_TRANSFER_IN      = 'TRANSFER_IN';
    public const TYPE_RESERVATION_OUT  = 'RESERVATION_OUT';
    public const TYPE_RESERVATION_IN   = 'RESERVATION_IN';
    public const TYPE_MANUAL_DECREMENT = 'MANUAL_DECREMENT';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private ?string $type = null;

    #[ORM\Column]
    private int $quantity = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $originalPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $finalPrice = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $discountPercent = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $discountLabel = null;

    #[ORM\Column]
    private bool $isDiscounted = false;

    #[ORM\ManyToOne(inversedBy: 'stockMovements')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\ManyToOne(inversedBy: 'stockMovements')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Size $size = null;

    #[ORM\ManyToOne(inversedBy: 'stockMovements')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Location $location = null;

    #[ORM\ManyToOne(inversedBy: 'stockMovements')]
    private ?User $user = null;

    public function __construct()
    {
        $this->createdAt    = new \DateTimeImmutable();
        $this->quantity     = 0;
        $this->isDiscounted = false;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

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

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getOriginalPrice(): ?string
    {
        return $this->originalPrice;
    }

    public function setOriginalPrice(?string $originalPrice): static
    {
        $this->originalPrice = $originalPrice;

        return $this;
    }

    public function getFinalPrice(): ?string
    {
        return $this->finalPrice;
    }

    public function setFinalPrice(?string $finalPrice): static
    {
        $this->finalPrice = $finalPrice;

        return $this;
    }

    public function getDiscountPercent(): ?int
    {
        return $this->discountPercent;
    }

    public function setDiscountPercent(?int $discountPercent): static
    {
        $this->discountPercent = $discountPercent;

        return $this;
    }

    public function getDiscountLabel(): ?string
    {
        return $this->discountLabel;
    }

    public function setDiscountLabel(?string $discountLabel): static
    {
        $this->discountLabel = $discountLabel;

        return $this;
    }

    public function isDiscounted(): bool
    {
        return $this->isDiscounted;
    }

    public function setIsDiscounted(bool $isDiscounted): static
    {
        $this->isDiscounted = $isDiscounted;

        return $this;
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function __toString(): string
    {
        $productRef = $this->product?->getReference() ?? 'N/A';
        $sizeName   = $this->size?->getName() ?? 'N/A';
        $location   = $this->location?->getName() ?? 'N/A';
        $type       = $this->type ?? 'UNKNOWN';

        return sprintf('%s %s - %s (%d) @ %s', $type, $productRef, $sizeName, $this->quantity, $location);
    }
}
