<?php

namespace App\Enum;

enum EveningStatus: string
{
    case OrdersOpen = 'orders_open';
    case OrdersClosed = 'orders_closed';
    case BillEntered = 'bill_entered';
    case Archived = 'archived';
}
