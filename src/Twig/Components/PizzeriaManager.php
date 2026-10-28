<?php

namespace App\Twig\Components;

use App\Entity\Pizzeria;
use App\Pizzeria\Exception\CatalogRuleException;
use App\Pizzeria\PizzeriaCatalog;
use App\Repository\PizzeriaRepository;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Elenco delle pizzerie (area di gestione): aggiunta, rinomina ed eliminazione.
 */
#[AsLiveComponent(route: 'gestione_live_component')]
final class PizzeriaManager
{
    use AdminOnlyTrait;
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $newName = '';

    #[LiveProp(writable: true)]
    public ?int $editingId = null;

    #[LiveProp(writable: true)]
    public string $editingName = '';

    public ?string $error = null;
    public ?string $notice = null;

    public function __construct(
        private readonly PizzeriaCatalog $catalog,
        private readonly PizzeriaRepository $pizzerias,
    ) {
    }

    #[LiveAction]
    public function add(): void
    {
        $this->apply(function () {
            $pizzeria = $this->catalog->addPizzeria($this->newName);
            $this->newName = '';

            return sprintf('Pizzeria «%s» aggiunta.', $pizzeria->getName());
        });
    }

    #[LiveAction]
    public function startEdit(#[LiveArg] int $pizzeriaId): void
    {
        $pizzeria = $this->pizzerias->find($pizzeriaId);
        if (null !== $pizzeria) {
            $this->editingId = $pizzeriaId;
            $this->editingName = $pizzeria->getName();
        }
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editingName = '';
    }

    #[LiveAction]
    public function rename(): void
    {
        if (null === $this->editingId) {
            return;
        }
        $this->apply(function () {
            $this->catalog->renamePizzeria($this->pizzeria($this->editingId), $this->editingName);
            $this->cancelEdit();

            return 'Pizzeria rinominata.';
        });
    }

    #[LiveAction]
    public function delete(#[LiveArg] int $pizzeriaId): void
    {
        $this->apply(function () use ($pizzeriaId) {
            $this->catalog->deletePizzeria($this->pizzeria($pizzeriaId));

            return 'Pizzeria eliminata.';
        });
    }

    /** @return list<Pizzeria> */
    public function getPizzerias(): array
    {
        return $this->pizzerias->findAllSorted();
    }

    public function countEvenings(Pizzeria $pizzeria): int
    {
        return $this->catalog->countEvenings($pizzeria);
    }

    private function pizzeria(int $id): Pizzeria
    {
        return $this->pizzerias->find($id) ?? throw new CatalogRuleException('Pizzeria non trovata.');
    }

    /** @param callable(): string $operation restituisce il messaggio di conferma */
    private function apply(callable $operation): void
    {
        try {
            $this->notice = $operation();
        } catch (CatalogRuleException $e) {
            $this->error = $e->getMessage();
        }
    }
}
