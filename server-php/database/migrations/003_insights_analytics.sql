-- ---------------------------------------------------------------------------
-- Aicountly Insights — custom KPIs, saved reports and exception review
--
-- All three follow the same rule as the dashboards: a DEFINITION is stored, a
-- RESULT never is. A saved report holds its filters and its column choice, not
-- last quarter's figures; an anomaly review holds the reviewer's decision, not
-- a copy of the transactions it was about.
-- ---------------------------------------------------------------------------

-- --------------------------------------------------------------------------
-- Custom KPIs.
--
-- `formula` is the text a user typed. It is parsed into a validated tree by
-- Metrics\Expression before it is stored, and `ast` keeps that tree so the
-- evaluator never re-parses user text at query time. NOTHING HERE IS EVER
-- EXECUTED AS CODE — not as PHP, not as SQL, not in the browser.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS insights_metric_definitions (
    metric_id       BIGSERIAL PRIMARY KEY,
    public_id       TEXT        NOT NULL UNIQUE,
    cmp_id          BIGINT      NOT NULL,
    -- The id the rest of the product refers to it by: custom.<slug>
    metric_key      TEXT        NOT NULL,
    label           TEXT        NOT NULL,
    definition_text TEXT        NOT NULL DEFAULT '',
    formula         TEXT        NOT NULL,
    -- The parsed, validated tree. Regenerated whenever `formula` changes.
    ast             JSONB       NOT NULL,
    -- Metric ids the formula reads, for dependency checks and impact analysis.
    depends_on      JSONB       NOT NULL DEFAULT '[]'::jsonb,
    unit            TEXT        NOT NULL DEFAULT 'currency',
    precision       SMALLINT    NOT NULL DEFAULT 2,
    better_when     TEXT        NOT NULL DEFAULT 'up' CHECK (better_when IN ('up', 'down', 'neutral')),
    accounting_basis TEXT       NOT NULL DEFAULT 'derived',
    grains          JSONB       NOT NULL DEFAULT '[]'::jsonb,
    formula_version TEXT        NOT NULL DEFAULT '1.0.0',
    is_active       BOOLEAN     NOT NULL DEFAULT TRUE,
    created_by      TEXT        NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, metric_key)
);

CREATE INDEX IF NOT EXISTS idx_insights_metrics_cmp
    ON insights_metric_definitions (cmp_id) WHERE is_active;

-- --------------------------------------------------------------------------
-- Saved reports. A definition: which metrics, which dimension, which period,
-- which filters, which output formats. Never a replicated dataset.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS insights_report_definitions (
    report_id   BIGSERIAL PRIMARY KEY,
    public_id   TEXT        NOT NULL UNIQUE,
    cmp_id      BIGINT      NOT NULL,
    owner_uuid  TEXT        NOT NULL,
    title       TEXT        NOT NULL,
    description TEXT        NOT NULL DEFAULT '',
    -- {metrics: [...], dimension, grain, period: {...}, filters: {...}, formats: [...]}
    config      JSONB       NOT NULL DEFAULT '{}'::jsonb,
    visibility  TEXT        NOT NULL DEFAULT 'private'
                CHECK (visibility IN ('private', 'team', 'organisation')),
    is_archived BOOLEAN     NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by  TEXT
);

CREATE INDEX IF NOT EXISTS idx_insights_reports_cmp
    ON insights_report_definitions (cmp_id, owner_uuid) WHERE NOT is_archived;

-- --------------------------------------------------------------------------
-- Anomaly review.
--
-- An exception is DETECTED LIVE, from figures fetched on the request that drew
-- the screen. What is stored is a person's decision about it — acknowledged,
-- dismissed with a reason, reopened — keyed by a fingerprint of the rule and
-- the thing it fired on, so the same exception is recognised next time without
-- a copy of the transaction behind it.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS insights_anomaly_reviews (
    review_id     BIGSERIAL PRIMARY KEY,
    public_id     TEXT        NOT NULL UNIQUE,
    cmp_id        BIGINT      NOT NULL,
    -- rule id + subject + period, hashed. Stable across runs, reveals nothing.
    fingerprint   TEXT        NOT NULL,
    rule_id       TEXT        NOT NULL,
    subject_label TEXT        NOT NULL DEFAULT '',
    status        TEXT        NOT NULL DEFAULT 'open'
                  CHECK (status IN ('open', 'acknowledged', 'dismissed')),
    reason        TEXT        NOT NULL DEFAULT '',
    reviewed_by   TEXT,
    reviewed_at   TIMESTAMPTZ,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, fingerprint)
);

CREATE INDEX IF NOT EXISTS idx_insights_anomaly_cmp_status
    ON insights_anomaly_reviews (cmp_id, status);

-- --------------------------------------------------------------------------
-- Review notes — the conversation about an exception.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS insights_anomaly_notes (
    note_id    BIGSERIAL PRIMARY KEY,
    review_id  BIGINT      NOT NULL REFERENCES insights_anomaly_reviews (review_id) ON DELETE CASCADE,
    cmp_id     BIGINT      NOT NULL,
    author_uuid TEXT       NOT NULL,
    note       TEXT        NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_insights_anomaly_notes_review
    ON insights_anomaly_notes (review_id, created_at);

-- --------------------------------------------------------------------------
-- AI proposals.
--
-- A proposal is a dashboard configuration the model produced, held so the user
-- can review it and then Apply. It expires: an unapplied proposal is a draft,
-- not a record, and keeping them forever would accumulate a pile of
-- half-answered questions nobody will revisit.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS insights_ai_proposals (
    proposal_id  BIGSERIAL PRIMARY KEY,
    public_id    TEXT        NOT NULL UNIQUE,
    cmp_id       BIGINT      NOT NULL,
    user_uuid    TEXT        NOT NULL,
    kind         TEXT        NOT NULL CHECK (kind IN ('create_dashboard', 'edit_dashboard', 'add_widget')),
    -- The dashboard this edits, when it is an edit.
    dashboard_id BIGINT      REFERENCES insights_dashboards (dashboard_id) ON DELETE CASCADE,
    -- The question, kept so the review screen can show what was asked.
    prompt       TEXT        NOT NULL DEFAULT '',
    -- The schema-valid configuration the model produced. Validated by
    -- WidgetSchema before it was ever stored.
    proposal     JSONB       NOT NULL,
    status       TEXT        NOT NULL DEFAULT 'pending'
                 CHECK (status IN ('pending', 'applied', 'discarded', 'expired')),
    applied_at   TIMESTAMPTZ,
    expires_at   TIMESTAMPTZ NOT NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_insights_proposals_user
    ON insights_ai_proposals (cmp_id, user_uuid, status);
