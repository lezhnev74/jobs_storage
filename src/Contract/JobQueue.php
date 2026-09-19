<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Contract;

/**
 * Workers should receive only `JobWorker`, producers only `JobProducer`; this union exists for the code paths that
 * legitimately need both.
 */
interface JobQueue extends JobProducer, JobWorker {}
