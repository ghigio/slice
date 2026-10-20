<?php

namespace App\Entity;

use App\Repository\EaterRepository;
use App\Security\TokenGenerator;
use App\Text\NameNormalizer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Gruppo (famiglia) che ordina tramite il proprio link segreto; nell'interfaccia si chiama «gruppo».
 */
#[ORM\Entity(repositoryClass: EaterRepository::class)]
#[ORM\Table(name: 'eaters')]
#[ORM\UniqueConstraint(name: 'uniq_eaters_evening_name', columns: ['evening_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
class Eater
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Evening::class, inversedBy: 'eaters')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Evening $evening;

    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    #[ORM\Column]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reimbursedAt = null;

    /** @var Collection<int, Pizza> */
    #[ORM\OneToMany(targetEntity: Pizza::class, mappedBy: 'eater', cascade: ['persist'])]
    #[ORM\OrderBy(['person' => 'ASC', 'id' => 'ASC'])]
    private Collection $pizzas;

    public function __construct(Evening $evening, string $name)
    {
        $this->evening = $evening;
        $this->token = TokenGenerator::generate();
        $this->pizzas = new ArrayCollection();
        $this->setName($name);
        $evening->addEater($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvening(): Evening
    {
        return $this->evening;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    /** Il link precedente smette di funzionare. */
    public function regenerateToken(): void
    {
        $this->token = TokenGenerator::generate();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = NameNormalizer::clean($name);
    }

    public function getReimbursedAt(): ?\DateTimeImmutable
    {
        return $this->reimbursedAt;
    }

    public function setReimbursedAt(?\DateTimeImmutable $reimbursedAt): void
    {
        $this->reimbursedAt = $reimbursedAt;
    }

    /** @return Collection<int, Pizza> */
    public function getPizzas(): Collection
    {
        return $this->pizzas;
    }

    /** @internal Chiamato dal costruttore di Pizza. */
    public function addPizza(Pizza $pizza): void
    {
        if (!$this->pizzas->contains($pizza)) {
            $this->pizzas->add($pizza);
        }
    }
}
