<?php

namespace App\Jobs;

enum BackupOutcome
{
    case Created;
    case AlreadyDone;
    case NotSqlite;
}
