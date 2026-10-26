<?php

namespace App\Evening\Exception;

use App\Enum\EveningStatus;

final class InvalidTransitionException extends EveningRuleException
{
    public function __construct(EveningStatus $status)
    {
        parent::__construct(sprintf('Operazione non consentita nello stato «%s».', $status->label()));
    }
}
