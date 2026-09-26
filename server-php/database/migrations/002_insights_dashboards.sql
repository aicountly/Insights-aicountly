-- ---------------------------------------------------------------------------
-- Aicountly Insights — dashboards, widgets, revisions and sharing
--
-- A DASHBOARD IS A CONFIGURATION AND NOTHING ELSE. Its widgets name a metric,
-- a dimension, a grain and a filter; they never carry a value. Open a saved
-- dashboard a year from now and it asks the same questions of live products —
-- which is why restoring an old layout cannot restore old figures, and why a
-- template can ship with no sample data in it.
--
-- PUBLIC IDS. Dashboards travel in URLs and in other people's history, so every
-- row that appears in one carries an unguessable public_id alongside its serial
-- primary key. The API speaks only in public ids; the serial is for joins.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS insights_dashboards (
    dashboard_id   BIGSERIAL PRIMARY KEY,
    public_id      TEXT        NOT NULL UNIQUE,
    cmp_id         BIGINT      NOT NULL,
    owner_uuid     TEXT        NOT NULL,
    title          TEXT        NOT NULL,
    description    TEXT        NOT NULL DEFAULT '',
    -- 'private' | 'team' | 'organisation'. Never 'public': an anonymous link
    -- would hand a dashboard to somebody whose source permissions nobody can
    -- check, which is the one thing sharing here must not do.
    visibility     TEXT        NOT NULL DEFAULT 'private'
                   CHECK (visibility IN ('private', 'team', 'organisation')),
    tags           JSONB       NOT NULL DEFAULT '[]'::jsonb,
    -- The dashboard-wide filter: period preset, grain, branch, comparison.
    -- Validated against DashboardSchema before it is written.
    settings       JSONB       NOT NULL DEFAULT '{}'::jsonb,
    -- The published configuration, as a snapshot. NULL until first published.
    published_at   TIMESTAMPTZ,
    published_by   TEXT,
    -- Optimistic concurrency. Every save sends the version it read.
    revision       INTEGER     NOT NULL DEFAULT 1,
    -- Where a dashboard created from a template came from, for provenance.
    template_key   TEXT,
    is_archived    BOOLEAN     NOT NULL DEFAULT FALSE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by     TEXT
);

CREATE INDEX IF NOT EXISTS idx_insights_dashboards_cmp_owner
    ON insights_dashboards (cmp_id, owner_uuid) WHERE NOT is_archived;
CREATE INDEX IF NOT EXISTS idx_insights_dashboards_cmp_visibility
    ON insights_dashboards (cmp_id, visibility) WHERE NOT is_archived;
CREATE INDEX IF NOT EXISTS idx_insights_dashboards_updated
    ON insights_dashboards (cmp_id, updated_at DESC);

-- --------------------------------------------------------------------------
-- Widgets.
--
-- `layout` holds the coordinates PER BREAKPOINT — desktop (12 columns), tablet
-- and mobile — because a dashboard that only remembers one of them rearranges
-- itself the first time somebody opens it on a phone and then saves.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS insights_dashboard_widgets (
    widget_id    BIGSERIAL PRIMARY KEY,
    public_id    TEXT        NOT NULL UNIQUE,
    dashboard_id BIGINT      NOT NULL REFERENCES insights_dashboards (dashboard_id) ON DELETE CASCADE,
    cmp_id       BIGINT      NOT NULL,
    widget_type  TEXT        NOT NULL,
    title        TEXT        NOT NULL DEFAULT '',
    description  TEXT        NOT NULL DEFAULT '',
    -- {metric_id, dimension, grain, filters, comparison, chart, limit, targets…}
    -- There is NO free SQL, JavaScript, HTML or URL field here, and adding one
    -- would turn a shared dashboard into a way to run code in a colleague's
    -- browser. WidgetSchema rejects anything not in its allowlist.
    config       JSONB       NOT NULL DEFAULT '{}'::jsonb,
    -- {"desktop": {"x":0,"y":0,"w":4,"h":3}, "tablet": {...}, "mobile": {...}}
    layout       JSONB       NOT NULL DEFAULT '{}'::jsonb,
    position     INTEGER     NOT NULL DEFAULT 0,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_insights_widgets_dashboard
    ON insights_dashboard_widgets (dashboard_id, position);

-- --------------------------------------------------------------------------
-- Revisions.
--
-- A snapshot of the dashboard AND its widgets, taken on every save. Restoring
-- one restores the LAYOUT AND CONFIGURATION ONLY: the shares are not in the
-- snapshot, on purpose. Rolling a dashboard back to March must not re-admit
-- somebody who was removed in April.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS insights_dashboard_versions (
    version_id   BIGSERIAL PRIMARY KEY,
    public_id    TEXT        NOT NULL UNIQUE,
    dashboard_id BIGINT      NOT NULL REFERENCES insights_dashboards (dashboard_id) ON DELETE CASCADE,
    cmp_id       BIGINT      NOT NULL,
    revision     INTEGER     NOT NULL,
    label        TEXT        NOT NULL DEFAULT '',
    -- {dashboard: {...}, widgets: [...]}. No shares, no business data.
    snapshot     JSONB       NOT NULL,
    is_published BOOLEAN     NOT NULL DEFAULT FALSE,
    created_by   TEXT        NOT NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (dashboard_id, revision)
);

CREATE INDEX IF NOT EXISTS idx_insights_versions_dashboard
    ON insights_dashboard_versions (dashboard_id, revision DESC);

-- --------------------------------------------------------------------------
-- Shares.
--
-- A share grants access to a CONFIGURATION. It does not grant access to the
-- data the configuration asks for: every query, preview, AI request, drill-down
-- and export re-checks the VIEWER's own permissions in the owning product.
-- A restricted viewer opening a shared dashboard sees the layout and, where
-- their access does not reach, a panel that says so.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS insights_dashboard_shares (
    share_id     BIGSERIAL PRIMARY KEY,
    public_id    TEXT        NOT NULL UNIQUE,
    dashboard_id BIGINT      NOT NULL REFERENCES insights_dashboards (dashboard_id) ON DELETE CASCADE,
    cmp_id       BIGINT      NOT NULL,
    -- 'user' for a named colleague, 'company' for everyone in the tenant.
    subject_type TEXT        NOT NULL CHECK (subject_type IN ('user', 'company')),
    subject_id   TEXT        NOT NULL,
    permission   TEXT        NOT NULL CHECK (permission IN ('view', 'edit', 'manage')),
    granted_by   TEXT        NOT NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (dashboard_id, subject_type, subject_id)
);

CREATE INDEX IF NOT EXISTS idx_insights_shares_subject
    ON insights_dashboard_shares (cmp_id, subject_type, subject_id);

-- --------------------------------------------------------------------------
-- Favourites, so "my dashboards" can be ordered by what somebody actually uses.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS insights_dashboard_favourites (
    dashboard_id BIGINT      NOT NULL REFERENCES insights_dashboards (dashboard_id) ON DELETE CASCADE,
    cmp_id       BIGINT      NOT NULL,
    user_uuid    TEXT        NOT NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (dashboard_id, user_uuid)
);

CREATE INDEX IF NOT EXISTS idx_insights_favourites_user
    ON insights_dashboard_favourites (cmp_id, user_uuid);
