<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Query;

enum GroupBy
{
    case State;
    case Pool;
    case NamePrefix;
    case ReasonCode;
    case Worker;
}
