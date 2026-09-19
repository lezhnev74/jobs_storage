# lezhnev74/jobs

[![QA](https://github.com/lezhnev74/jobs_storage/actions/workflows/qa.yml/badge.svg)](https://github.com/lezhnev74/jobs_storage/actions/workflows/qa.yml)

Concurrency-safe persistent job storage for PHP: workers in named pools claim batches of jobs under a
time-bounded lease, work on them and settle each outcome explicitly. Storage-agnostic core with PostgreSQL
and MySQL drivers included; other backends plug in as drivers.

Design: see [`dev_docs/overview.md`](dev_docs/overview.md), [`dev_docs/dedup.md`](dev_docs/dedup.md),
[`dev_docs/postgres.md`](dev_docs/postgres.md) and [`dev_docs/mysql.md`](dev_docs/mysql.md).

## Requirements

- PHP 8.2+
- One of the bundled drivers: PostgreSQL 14+ (`ext-pdo_pgsql`) or MySQL 8+ (`ext-pdo_mysql`)

## Your own job types

A producer can hand `push` a bare `NewJob`, or any object that describes itself as one. `DescribesJob` is the only
seam for user code - your classes stay yours and never extend a library class:

```php
final readonly class SendInvoice implements DescribesJob
{
    public function __construct(private int $invoiceId, private JobId $id) {}

    public function toJob(): NewJob
    {
        return new NewJob(
            id: $this->id,                              // stable per instance: push stays idempotent
            name: new JobName('billing.invoice.send'),   // explicit, never derived from the class name
            pool: new PoolName('emails'),
            payload: ['invoice' => $this->invoiceId],
        );
    }
}

$report = $queue->push(new SendInvoice(42, JobId::new()), $someNewJob);  // both shapes, one batch
```

For one-off pushes where idempotency across retries is not needed, `NewJob::make()` mints the id and takes plain
strings: `NewJob::make('billing.invoice.send', 'emails', ['invoice' => 42])`. `availableAt` defaults to now; pass
it explicitly to schedule.

`toJob()` is called exactly once per push and must be cheap, side-effect free and deterministic - a fresh `JobId`
per call would defeat retry idempotency, and a payload that varies per call (a timestamp, a nonce) would defeat
deduplication. There is deliberately no reverse direction (`fromJob`): rehydrating a
stored job needs payload versioning and a name-to-class map, both application decisions. The library hands back a
`Job` with a `payload` array and stops there.

## Deduplication

`push` is idempotent on two independent identities, and never throws on either:

```php
$report = $queue->push($a, $b);

$report->isClean();                // nothing was dropped
$report->wasDeduplicated($b->id);  // this job was not stored
```

- **`JobId`** - re-pushing an existing id is a no-op forever, so a lost reply is safe to retry.
- **dedup key** - within one pool, only one *non-terminal* row may hold a given key. A colliding push is dropped,
  never merged into the stored row; once that row completes or is discarded, the key is free and the same work
  inserts as a fresh row. This is what makes a producer that re-derives the same job on every tick safe.

Every `NewJob` carries a key - there is no "no dedup" mode. It defaults to a `sha256` over the canonical JSON of
`[pool, name, payload]` (`availableAt` is deliberately excluded: "the same work, maybe sooner" must still
deduplicate), or set your own with `$job->dedup('invoice:2026-09:acme')`. A deliberate duplicate is expressed by
making the jobs actually differ. `Job::$dedupKey` reads the stored key back.

A dropped push tells you *that* something already holds the key; `find` tells you what, and the query filter reads the
history of every run of it:

```php
$key = DedupKey::fromString('invoice:2026-09:acme');

$holder = $monitor->find(new PoolName('emails'), $key);  // the live row, or null if the key is free
$holder?->state();                                       // scheduled / claimable / claimed
$holder?->availableAt;                                   // when it runs

$runs = $monitor->read($query->dedupKeys($key));         // every run, finished ones included
```

`find` sees non-terminal rows only - the scope of the constraint, so at most one row can match - while `dedupKeys()`
spans every state. Deciding whether work is already queued is `find(...) !== null`.

Details, the per-backend index trick and the operator backfill: [`dev_docs/dedup.md`](dev_docs/dedup.md).

**Breaking, if you are upgrading:** `push` returned `void` and now returns `PushReport`; the `jobs` table gains a
`NOT NULL dedup_key` column and a unique index, so an existing table needs the backfill from that note before the new
schema applies. The library never runs migrations - apply it yourself.

## The worker loop

`claim` returns one lease token covering the batch; `settle` reports per id which acks landed. The models join the
two, so no consumer has to filter ids by hand:

```php
$claimed = $queue->claim($q, $me, $ttl);          // token minted even for an empty batch

foreach ($claimed->jobs as $job) {
    $settlements[] = Settlement::complete($job);  // or fail / reschedule / discard / release
}

$report = $queue->settle($claimed->token, ...$settlements);

$lost = $claimed->stale($report);      // fenced out - another worker owns these now, drop them
$todo = $claimed->unsettled($report);  // in no report - never attempted, carry into the next round
```

Stale jobs are dropped, never re-settled: the token is dead, so a retry under it silently no-ops forever.
`unsettled` means "not reported", which includes jobs the worker never attempted - not "failed". Both are also
available as pure filters on `Job` (`Job::only`, `except`, `unreported`, `stale`) for any `list<Job>`, and
`AckReport::merge()` collapses several settle rounds made under one token.

## Layout

```
src/
  Contract/          public API: JobProducer, JobWorker, JobQueue, JobReader, JobJanitor, JobMonitor, DescribesJob
  Model/             Job, ClaimedJobs, Settlement, AckReport, PushReport, value types (JobId, DedupKey, PoolName, ...)
  Query/             ClaimQuery, JobQuery, State, Order, GroupBy
  Driver/
    Contract/        driver SPI: Driver (queue, monitor, schema) and Schema (ordered DDL)
    Support/         optional pure helpers drivers may reuse (report id diffing, LIKE escaping, settle dispatch)
    Postgres/        bundled PostgreSQL driver (one autocommit statement per call)
    Mysql/           bundled MySQL driver (short transactions: MySQL has no UPDATE ... RETURNING; nests on a SAVEPOINT inside a caller's transaction)
resources/schema/
  postgres/          schema SQL per driver
  mysql/
tests/Unit           no I/O
tests/Support/       test helpers + DriverConformanceTestCase (Conformance/ traits): the behavioral contract every driver must pass
tests/Integration/
  Postgres/          runs the conformance suite against PostgreSQL
  Mysql/             runs the conformance suite against MySQL
tools/               QA helpers (CRAP gate)
```

Drivers live in this package, not outside it: a new backend is added by PR, implementing `Driver/Contract` and
extending `Lezhnev74\Jobs\Tests\Support\DriverConformanceTestCase` in its integration tests, exactly like the
bundled drivers do. The conformance suite is test-only and is not shipped in installs. It drops and recreates the
schema through the SPI before every test - point it at a throwaway database. The schema is never applied by the
library at runtime; apply `resources/schema/*` manually in deployments.

## QA

Everything runs inside the dev image (`Dockerfile.dev`: PHP 8.5 - the newest this package supports - with
`pdo_pgsql`, `pdo_mysql`, `pcntl` and pcov), so the only host requirement is Docker. CI runs the same gate across
8.2, 8.3, 8.4 and 8.5; to reproduce an older job locally, rebuild the image against that version:
`./dev build --build-arg PHP_VERSION=8.2`.

```
./dev up                  # start Postgres + MySQL and wait for them
./dev composer install
./dev qa                  # cs + stan + tests with CRAP <= 6
./dev cs / cs:fix         # PSR-12 + PER-CS 2.0 (php-cs-fixer)
./dev stan                # PHPStan level 7 + strict rules
./dev test                # unit tests, no I/O
./dev test:pg / test:mysql   # the conformance suite against one backend
./dev test:integration    # both
./dev phpunit <args>      # anything else
```

Each maps to the matching `composer` script, so `composer qa` and friends work directly too if your host PHP has
the extensions. `./dev qa` runs the full suite for coverage, integration included: the CRAP gate is measured over
everything, since the drivers are behavioral code that only a real database exercises.
