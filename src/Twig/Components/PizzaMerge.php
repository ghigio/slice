<?php

namespace App\Twig\Components;

use App\Entity\Evening;
use App\Evening\Exception\EveningRuleException;
use App\Evening\PizzaList as PizzaListModel;
use App\Evening\PizzaOrdering;
use App\Repository\PizzaRepository;
use App\Text\SimilarNames;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Unione delle pizze (pagina admin): possibili doppioni da unire in un clic e unione libera tra due pizze qualsiasi.
 */
#[AsLiveComponent]
final class PizzaMerge
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public Evening $evening;

    /** Nome normalizzato della pizza da unire. */
    #[LiveProp(writable: true)]
    public string $from = '';

    /** Nome normalizzato della pizza di destinazione. */
    #[LiveProp(writable: true)]
    public string $into = '';

    public ?string $error = null;
    public ?string $notice = null;

    private ?array $names = null;

    public function __construct(
        private readonly PizzaOrdering $ordering,
        private readonly PizzaRepository $pizzas,
    ) {
    }

    #[LiveAction]
    public function merge(#[LiveArg] string $from, #[LiveArg] string $into): void
    {
        try {
            $merged = $this->ordering->mergePizzas($this->evening, $from, $into);
            $this->names = null;
            $this->from = $this->into = '';
            $this->notice = sprintf('%d %s in «%s».', $merged, 1 === $merged ? 'pizza unita' : 'pizze unite', $this->nameOf($into));
            $this->emit('pizzasMerged');
        } catch (EveningRuleException $e) {
            $this->error = $e->getMessage();
        }
    }

    #[LiveAction]
    public function mergeSelected(): void
    {
        $this->merge($this->from, $this->into);
    }

    /** @return list<array{name: string, normalizedName: string, quantity: int}> */
    public function getNames(): array
    {
        return $this->names ??= PizzaListModel::fromPizzas($this->pizzas->findForEvening($this->evening))->names();
    }

    /**
     * Coppie di pizze con nomi simili, probabili doppioni.
     *
     * @return list<array{array{name: string, normalizedName: string, quantity: int}, array{name: string, normalizedName: string, quantity: int}}>
     */
    public function getDuplicates(): array
    {
        $names = $this->getNames();
        $byName = array_column($names, null, 'name');

        return array_map(
            fn (array $pair) => [$byName[$pair[0]], $byName[$pair[1]]],
            SimilarNames::pairs(array_column($names, 'name')),
        );
    }

    private function nameOf(string $normalizedName): string
    {
        foreach ($this->getNames() as $line) {
            if ($line['normalizedName'] === $normalizedName) {
                return $line['name'];
            }
        }

        return $normalizedName;
    }
}
