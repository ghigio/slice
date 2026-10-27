<?php

namespace App\Twig\Components;

use App\Entity\Evening;
use App\Evening\PizzaList as PizzaListModel;
use App\Repository\PizzaRepository;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Lista per il pizzaiolo nella pagina admin: per pizza (da copiare) o per gruppo, con avviso sui prezzi incoerenti.
 */
#[AsLiveComponent]
final class PizzaList
{
    use DefaultActionTrait;

    #[LiveProp]
    public Evening $evening;

    /** «pizzas» oppure «eaters». */
    #[LiveProp(writable: true)]
    public string $view = 'pizzas';

    private ?PizzaListModel $list = null;

    public function __construct(private readonly PizzaRepository $pizzas)
    {
    }

    /** Pizze unite dall'organizzatore: la lista si aggiorna subito, senza aspettare il polling. */
    #[LiveListener('pizzasMerged')]
    public function refresh(): void
    {
        $this->list = null;
    }

    public function getList(): PizzaListModel
    {
        return $this->list ??= PizzaListModel::fromPizzas($this->pizzas->findForEvening($this->evening));
    }
}
