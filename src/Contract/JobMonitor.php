<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Contract;

/** Dashboards should receive only `JobReader`; this union is for tooling that legitimately observes and destroys. */
interface JobMonitor extends JobReader, JobJanitor {}
