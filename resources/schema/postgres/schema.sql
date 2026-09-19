-- Applied manually by operators or by the test suite; the library never applies
-- it at runtime. Statements are semicolon-terminated and in dependency order so
-- a loader can split on statement boundaries. Requires PostgreSQL 14+.

CREATE TABLE jobs (
    -- fixed-width columns first: no alignment padding between them
    id                      uuid        NOT NULL,                  -- client-generated UUIDv7
    available_at            timestamptz NOT NULL,
    consumed_till           timestamptz,
    completed_at            timestamptz,
    discarded_at            timestamptz,
    last_abandoned_at       timestamptz,
    last_stale_ack_at       timestamptz,
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now(),
    lease_token             uuid,                                  -- store-generated, one per claim event
    attempts                integer     NOT NULL DEFAULT 0,
    consecutive_failures    integer     NOT NULL DEFAULT 0,
    consecutive_reschedules integer     NOT NULL DEFAULT 0,
    abandoned_count         integer     NOT NULL DEFAULT 0,
    stale_ack_count         integer     NOT NULL DEFAULT 0,
    -- variable-width columns last
    pool                    text        NOT NULL COLLATE "C",
    name                    text        NOT NULL COLLATE "C",
    dedup_key               text        NOT NULL COLLATE "C",     -- business identity: sha256 of the content, or caller-supplied
    consumed_by             text,
    last_abandoned_by       text,
    last_stale_ack_by       text,
    payload                 jsonb       NOT NULL DEFAULT '{}',     -- not indexed
    last_fail_reason        jsonb,
    last_reschedule_reason  jsonb,

    CONSTRAINT jobs_pk PRIMARY KEY (id),
    CONSTRAINT jobs_one_terminal CHECK (completed_at IS NULL OR discarded_at IS NULL),
    -- token present <=> unsettled lease - a terminal row never carries a token
    CONSTRAINT jobs_lease_shape CHECK (
        (lease_token IS NULL) = (consumed_till IS NULL)
        AND (lease_token IS NULL OR (completed_at IS NULL AND discarded_at IS NULL))
    )
) WITH (fillfactor = 80);

ALTER TABLE jobs SET (
    autovacuum_vacuum_scale_factor  = 0.01,
    autovacuum_vacuum_threshold     = 1000,
    autovacuum_analyze_scale_factor = 0.02
);

-- claim path: per-pool ordered scan over non-terminal rows only
CREATE INDEX jobs_claim_idx ON jobs (pool, available_at)
    WHERE completed_at IS NULL AND discarded_at IS NULL;

-- dedup: the same work may be queued only once per pool, but only while it is
-- in flight - a terminal row drops out of the constraint so recurring work can
-- be pushed again
CREATE UNIQUE INDEX jobs_dedup_uk ON jobs (pool, dedup_key)
    WHERE completed_at IS NULL AND discarded_at IS NULL;
