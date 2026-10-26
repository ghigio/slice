<?php

namespace App\Enum;

enum EveningStatus: string
{
    case OrdersOpen = 'orders_open';
    case OrdersClosed = 'orders_closed';
    case BillEntered = 'bill_entered';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::OrdersOpen => 'Ordini aperti',
            self::OrdersClosed => 'Ordini chiusi',
            self::BillEntered => 'Conto inserito',
            self::Archived => 'Archiviata',
        };
    }
}
