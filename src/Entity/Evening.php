<?php

namespace App\Entity;

use App\Enum\DiscountMode;
use App\Enum\EveningStatus;
use App\Evening\Exception\InvalidTransitionException;
use App\Repository\EveningRepository;
use App\Security\TokenGenerator;
use App\Text\NameNormalizer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Serata: creata dall'organizzatore e gestita tramite il link admin.
 */
#[ORM\Entity(repositoryClass: EveningRepository::class)]
#[ORM\Table(name: 'evenings')]
#[ORM\HasLifecycleCallbacks]
class Evening
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $adminToken;

    #[ORM\Column]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $pizzeria = null;

    #[ORM\Column]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $organizerName;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $refundNotes = null;

    #[ORM\Column(length: 20, enumType: EveningStatus::class)]
    private EveningStatus $status = EveningStatus::OrdersOpen;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $paidTotalCents = null;

    #[ORM\Column(length: 20, enumType: DiscountMode::class)]
    private DiscountMode $discountMode = DiscountMode::Proportional;

    #[ORM\ManyToOne(targetEntity: Eater::class)]
    #[ORM\JoinColumn(name: 'organizer_eater_id', nullable: true, onDelete: 'SET NULL')]
    private ?Eater $organizerEater = null;

    /** @var Collection<int, Eater> */
    #[ORM\OneToMany(targetEntity: Eater::class, mappedBy: 'evening', cascade: ['persist'])]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $eaters;

    public function __construct(string $name, \DateTimeImmutable $date, string $organizerName, ?string $pizzeria = null, ?string $refundNotes = null)
    {
        $this->adminToken = TokenGenerator::generate();
        $this->eaters = new ArrayCollection();
        $this->setName($name);
        $this->setDate($date);
        $this->setOrganizerName($organizerName);
        $this->setPizzeria($pizzeria);
        $this->setRefundNotes($refundNotes);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAdminToken(): string
    {
        return $this->adminToken;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = NameNormalizer::clean($name);
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): void
    {
        $this->date = $date;
    }

    public function getPizzeria(): ?string
    {
        return $this->pizzeria;
    }

    public function setPizzeria(?string $pizzeria): void
    {
        $pizzeria = null === $pizzeria ? '' : NameNormalizer::clean($pizzeria);
        $this->pizzeria = '' === $pizzeria ? null : $pizzeria;
    }

    public function getOrganizerName(): string
    {
        return $this->organizerName;
    }

    public function setOrganizerName(string $organizerName): void
    {
        $this->organizerName = NameNormalizer::clean($organizerName);
    }

    public function getRefundNotes(): ?string
    {
        return $this->refundNotes;
    }

    public function setRefundNotes(?string $refundNotes): void
    {
        $refundNotes = null === $refundNotes ? '' : trim($refundNotes);
        $this->refundNotes = '' === $refundNotes ? null : $refundNotes;
    }

    public function getStatus(): EveningStatus
    {
        return $this->status;
    }

    public function setStatus(EveningStatus $status): void
    {
        $this->status = $status;
    }

    /** Ordini aperti → Ordini chiusi: i gruppi non possono più modificare le pizze. */
    public function closeOrders(): void
    {
        $this->transition(EveningStatus::OrdersOpen, EveningStatus::OrdersClosed);
    }

    /** Ordini chiusi → Ordini aperti. */
    public function reopenOrders(): void
    {
        $this->transition(EveningStatus::OrdersClosed, EveningStatus::OrdersOpen);
    }

    /** Conto inserito → Archiviata: tutto in sola lettura, salvo i rimborsi. */
    public function archive(): void
    {
        $this->transition(EveningStatus::BillEntered, EveningStatus::Archived);
    }

    /** Archiviata → Conto inserito («Riapri»). */
    public function unarchive(): void
    {
        $this->transition(EveningStatus::Archived, EveningStatus::BillEntered);
    }

    public function isArchived(): bool
    {
        return EveningStatus::Archived === $this->status;
    }

    /** Gruppi, link e pizze si gestiscono in ogni stato tranne Archiviata. */
    public function canManageEaters(): bool
    {
        return !$this->isArchived();
    }

    private function transition(EveningStatus $from, EveningStatus $to): void
    {
        if ($from !== $this->status) {
            throw new InvalidTransitionException($this->status);
        }
        $this->status = $to;
    }

    public function getPaidTotalCents(): ?int
    {
        return $this->paidTotalCents;
    }

    public function setPaidTotalCents(?int $paidTotalCents): void
    {
        $this->paidTotalCents = $paidTotalCents;
    }

    public function getDiscountMode(): DiscountMode
    {
        return $this->discountMode;
    }

    public function setDiscountMode(DiscountMode $discountMode): void
    {
        $this->discountMode = $discountMode;
    }

    public function getOrganizerEater(): ?Eater
    {
        return $this->organizerEater;
    }

    public function setOrganizerEater(?Eater $organizerEater): void
    {
        $this->organizerEater = $organizerEater;
    }

    /** @return Collection<int, Eater> */
    public function getEaters(): Collection
    {
        return $this->eaters;
    }

    public function removeEater(Eater $eater): void
    {
        $this->eaters->removeElement($eater);
        if ($this->organizerEater === $eater) {
            $this->organizerEater = null;
        }
    }

    /** @internal Chiamato dal costruttore di Eater. */
    public function addEater(Eater $eater): void
    {
        if (!$this->eaters->contains($eater)) {
            $this->eaters->add($eater);
        }
    }
}
