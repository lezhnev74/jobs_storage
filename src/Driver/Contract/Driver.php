<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Contract;

use Lezhnev74\Jobs\Contract\JobMonitor;
use Lezhnev74\Jobs\Contract\JobQueue;

/**
 * The storage backend seam, deliberately thin: a driver is a full implementation of the public contracts and this
 * interface only standardizes how to obtain them and how to install their schema. Nothing below the public contracts
 * is abstracted because backends differ in call structure, not just SQL spelling.
 *
 * The real contract is behavioral - a driver qualifies by passing the bundled conformance suite
 * (`tests/Support/DriverConformanceTestCase`). Drivers live in this package; new ones are added by PR.
 */
interface Driver
{
    public function queue(): JobQueue;

    public function monitor(): JobMonitor;

    /** Never applied by the library at runtime - see `Schema`. */
    public function schema(): Schema;
}
