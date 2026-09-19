-- Applied manually by operators or by the test suite; the library never applies
-- it at runtime. Statements are semicolon-terminated and in dependency order so
-- a loader can split on statement boundaries. Requires MySQL 8.0.19+ with
-- InnoDB. Same column set and order as the PostgreSQL table.

CREATE TABLE IF NOT EXISTS jobs (
    id                      BINARY(16)   NOT NULL,                                -- client-generated UUIDv7, UUID_TO_BIN without swap
    available_at            DATETIME(6)  NOT NULL,                                -- all DATETIME(6) values are UTC (session time_zone = '+00:00')
    consumed_till           DATETIME(6)  NULL,
    completed_at            DATETIME(6)  NULL,
    discarded_at            DATETIME(6)  NULL,
    last_abandoned_at       DATETIME(6)  NULL,
    last_stale_ack_at       DATETIME(6)  NULL,
    created_at              DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at              DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    lease_token             BINARY(16)   NULL,                                    -- store-generated, one per claim event
    attempts                INT          NOT NULL DEFAULT 0,
    consecutive_failures    INT          NOT NULL DEFAULT 0,
    consecutive_reschedules INT          NOT NULL DEFAULT 0,
    abandoned_count         INT          NOT NULL DEFAULT 0,
    stale_ack_count         INT          NOT NULL DEFAULT 0,
    pool                    VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    name                    VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    dedup_key               VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,  -- business identity: sha256 of the content, or caller-supplied
    -- InnoDB has no partial index: a terminal row nulls its slot, and NULLs
    -- never collide in a unique index, so the row leaves the constraint the
    -- moment it settles. STORED keeps the index maintenance semantics obvious.
    -- utf8mb4_bin is PAD SPACE: DedupKey trims, so keys never differ only by
    -- trailing whitespace.
    dedup_slot              VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
                            GENERATED ALWAYS AS (IF(completed_at IS NULL AND discarded_at IS NULL, dedup_key, NULL)) STORED,
    consumed_by             VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    last_abandoned_by       VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    last_stale_ack_by       VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    payload                 JSON         NOT NULL DEFAULT (JSON_OBJECT()),        -- not indexed
    last_fail_reason        JSON         NULL,
    last_reschedule_reason  JSON         NULL,

    CONSTRAINT jobs_pk PRIMARY KEY (id),
    CONSTRAINT jobs_one_terminal CHECK (completed_at IS NULL OR discarded_at IS NULL),
    -- token present <=> unsettled lease - a terminal row never carries a token
    CONSTRAINT jobs_lease_shape CHECK (
        (lease_token IS NULL) = (consumed_till IS NULL)
        AND (lease_token IS NULL OR (completed_at IS NULL AND discarded_at IS NULL))
    ),

    -- claim path: the terminal markers precede available_at so the IS NULL
    -- equalities keep terminal rows out of the scanned range - InnoDB has no
    -- partial index
    INDEX jobs_claim_idx (pool, completed_at, discarded_at, available_at),

    -- dedup: the same work may be queued only once per pool while in flight
    UNIQUE KEY jobs_dedup_uk (pool, dedup_slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
