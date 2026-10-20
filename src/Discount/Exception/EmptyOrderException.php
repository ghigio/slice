<?php

namespace App\Discount\Exception;

/** Nessuna pizza ordinata: il conto non si può inserire. */
final class EmptyOrderException extends \DomainException
{
}
