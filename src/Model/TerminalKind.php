<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

enum TerminalKind
{
    case Completed;
    case Discarded;
}
