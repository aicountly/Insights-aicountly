-- ---------------------------------------------------------------------------
-- Aicountly Insights — core schema
--
-- WHAT IS HERE: this product's own configuration. Dashboards, the widgets on
-- them, their layouts, who they are shared with, the KPIs a firm has defined,
-- saved reports, the review state of a business exception, and a little audit.
--
-- WHAT IS DELIBERATELY NOT HERE, and must never be added:
--   * an invoice, a receipt, a voucher or a ledger    -> Smart Books owns them
--   * a stock balance, a valuation or an item master  -> Inventory owns them
--   * a customer, supplier or party master            -> Books / Contacts own them
--   * a company, branch or financial year master      -> Manage owns them
--   * a copy of ANY business figure, cached or not    -> read it live, per request
--
-- That last one is the whole architecture. Insights renders numbers it fetched
-- on the request that drew the screen. A table here holding "last month's
-- revenue" would be a second, quietly diverging answer to a question Books
-- already answers, and reconciling the two would become somebody's job.
--
-- Every row is scoped by cmp_id — the company id Manage owns — and nothing is
-- ever read without it.
-- ---------------------------------------------------------------------------

-- --------------------------------------------------------------------------
-- Permissions — this product's own, layered over the portal identity.
--
-- These govern what a person may DO in Insights (build, publish, share,
-- export). They grant no business data: every figure is fetched from the
-- owning product with the viewer's own session, so Books and Inventory decide
-- what they may see. That separation is what makes sharing a dashboard safe.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS insights_permission_profiles (
    profile_id   BIGSERIAL PRIMARY KEY,
    cmp_id       BIGINT       NOT NULL,
    profile_name TEXT         NOT NULL,
    description  TEXT,
    -- A JSON array of permission codes from Permissions::CATALOG.
    permissions  JSONB        NOT NULL DEFAULT '[]'::jsonb,
    is_system    BOOLEAN      NOT NULL DEFAULT FALSE,
    is_active    BOOLEAN      NOT NULL DEFAULT TRUE,
    created_by   TEXT,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, profile_name)
);

CREATE INDEX IF NOT EXISTS idx_insights_profiles_cmp ON insights_permission_profiles (cmp_id) WHERE is_active;

CREATE TABLE IF NOT EXISTS insights_permission_assignments (
    assignment_id BIGSERIAL PRIMARY KEY,
    cmp_id        BIGINT      NOT NULL,
    -- The portal uuid. NOT a foreign key: the user master is my.aicountly.com's.
    user_uuid     TEXT        NOT NULL,
    profile_id    BIGINT      NOT NULL REFERENCES insights_permission_profiles (profile_id) ON DELETE CASCADE,
    assigned_by   TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, user_uuid, profile_id)
);

CREATE INDEX IF NOT EXISTS idx_insights_assignments_lookup
    ON insights_permission_assignments (cmp_id, user_uuid);

-- --------------------------------------------------------------------------
-- Per-user preferences. Display only — locale, number style, default period.
-- Nothing here changes what anybody may see.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS insights_user_preferences (
    preference_id BIGSERIAL PRIMARY KEY,
    cmp_id        BIGINT      NOT NULL,
    user_uuid     TEXT        NOT NULL,
    -- Validated against a fixed shape in SettingsService before it is written.
    preferences   JSONB       NOT NULL DEFAULT '{}'::jsonb,
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, user_uuid)
);

-- --------------------------------------------------------------------------
-- Company-level settings for Insights.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS insights_company_settings (
    cmp_id       BIGINT      PRIMARY KEY,
    settings     JSONB       NOT NULL DEFAULT '{}'::jsonb,
    updated_by   TEXT,
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- --------------------------------------------------------------------------
-- Audit — who did what to a dashboard, a KPI or a share.
--
-- Deliberately thin. It records ACTIONS ON CONFIGURATION, never the business
-- data somebody looked at: an audit row holding last month's revenue would be
-- exactly the copy of source data the rest of this schema refuses.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS insights_audit_events (
    event_id    BIGSERIAL PRIMARY KEY,
    cmp_id      BIGINT      NOT NULL,
    user_uuid   TEXT        NOT NULL,
    action      TEXT        NOT NULL,
    object_type TEXT        NOT NULL,
    object_id   TEXT,
    -- Small, bounded, and never a business figure.
    detail      JSONB       NOT NULL DEFAULT '{}'::jsonb,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_insights_audit_cmp_time
    ON insights_audit_events (cmp_id, created_at DESC);

-- --------------------------------------------------------------------------
-- AI usage — counts and outcomes, never content.
--
-- No prompt, no answer, no customer name, no figure. What is kept is what an
-- administrator needs to see a budget being spent and a provider failing.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS insights_ai_usage (
    usage_id      BIGSERIAL PRIMARY KEY,
    cmp_id        BIGINT      NOT NULL,
    user_uuid     TEXT        NOT NULL,
    feature       TEXT        NOT NULL,
    outcome       TEXT        NOT NULL CHECK (outcome IN ('success', 'error', 'blocked', 'rules_only')),
    error_code    TEXT,
    latency_ms    INTEGER,
    prompt_tokens INTEGER,
    output_tokens INTEGER,
    model         TEXT,
    provider      TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_insights_ai_usage_budget
    ON insights_ai_usage (cmp_id, created_at DESC);

-- Applied-migration bookkeeping lives in insights_sql_migrations, created by
-- bin/migrate.php itself.
