-- =============================================================================
-- DMS module — schema + example seed data
-- Source spec: app/Modules/DMS/DMS_SPECS.md (see that file for full rationale)
-- Target DB:   a single TENANT DB (e.g. `tenant_002`), schema "DMS" — every
--              table lives inside one tenant's own database (CLAUDE.md §4/§7A),
--              no tenant_id column anywhere.
--
-- Documents are versioned and immutable-in-storage: a re-upload always creates a
-- new `document_versions` row, never overwrites one (§3C/§4). `documents` and
-- `document_versions` reference each other (current-version pointer <-> owning
-- document), so `current_version_id`'s FK is added via ALTER TABLE after
-- `document_versions` exists, same technique used for CENTRAL_SPECS.sql's
-- extension of the pre-existing `tenants` table.
--
-- `subject_type`/`subject_id` on `documents` is a plain informational column,
-- never a FK — the same seam used by CRM's svc_cases/hd_tickets and WNE's
-- workflow instances (§5): DMS cannot reach into another module's schema.
-- Custom metadata piggybacks on the existing `CUSTOMFIELDS` schema/mechanism
-- (entity_type = 'dms_document') rather than a DMS-specific one — no metadata
-- table is defined here (§4).
--
-- This file is a reference/planning script, not a Laravel migration — port each
-- section below into `database/migrations/tenant/*.php` per CLAUDE.md §5 build
-- order (DMS comes right after WNE, before CRM).
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A)

-- Quoted, same convention as "PROJECTS"/"AIINSIGHT"/"ACCOUNTING"/"CRM"/"CUSTOMFIELDS".
CREATE SCHEMA IF NOT EXISTS "DMS";

-- -----------------------------------------------------------------------------
-- §3D / §3F / §4. Lookups — doc types and retention policies come before
-- folders/documents since both are referenced by them.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "DMS".doc_types (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(50) NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "DMS".retention_policies (
    id                      BIGSERIAL PRIMARY KEY,
    doc_type_id             BIGINT NOT NULL REFERENCES "DMS".doc_types (id),
    retention_period_days   INTEGER NOT NULL CHECK (retention_period_days > 0),
    action_on_expiry        VARCHAR(15) NOT NULL CHECK (action_on_expiry IN ('notify_only', 'archive', 'delete')),
    legal_hold_overridable  BOOLEAN NOT NULL DEFAULT TRUE, -- whether a legal_hold flag can block this policy's scheduled action
    is_active               BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE INDEX IF NOT EXISTS idx_dms_retention_policies_doc_type ON "DMS".retention_policies (doc_type_id);

-- -----------------------------------------------------------------------------
-- §3D / §4. Folders — self-referencing tree, standalone browsing
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "DMS".folders (
    id                              BIGSERIAL PRIMARY KEY,
    parent_folder_id                BIGINT REFERENCES "DMS".folders (id),
    name                             VARCHAR(150) NOT NULL,
    default_doc_type_id               BIGINT REFERENCES "DMS".doc_types (id),
    default_retention_policy_id         BIGINT REFERENCES "DMS".retention_policies (id),
    access_flag                           VARCHAR(10) NOT NULL DEFAULT 'tenant'
                                              CHECK (access_flag IN ('private', 'team', 'tenant')), -- enforced at query time, not just UI-hidden (§3A rule)
    created_at                               TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (id <> parent_folder_id)
);

CREATE INDEX IF NOT EXISTS idx_dms_folders_parent ON "DMS".folders (parent_folder_id);

-- -----------------------------------------------------------------------------
-- §3B / §4. Documents — current-version pointer, lifecycle, owning module ref
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "DMS".documents (
    id                      BIGSERIAL PRIMARY KEY,
    uuid                    UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE, -- used in the R2 object key path (§4)
    folder_id               BIGINT REFERENCES "DMS".folders (id),          -- nullable: embedded-only documents need no folder
    doc_type_id             BIGINT REFERENCES "DMS".doc_types (id),
    title                   VARCHAR(255) NOT NULL,
    description              TEXT,
    subject_type               VARCHAR(100),           -- owning module reference, e.g. 'legal.case_hdrs' — NOT a FK (§5)
    subject_id                 VARCHAR(100),
    status                       VARCHAR(10) NOT NULL DEFAULT 'draft'
                                     CHECK (status IN ('draft', 'active', 'archived', 'expired', 'purged')),
    current_version_id            BIGINT,               -- FK added below via ALTER TABLE, after document_versions exists
    effective_date                  DATE,
    expiry_date                       DATE,
    retention_policy_id                 BIGINT REFERENCES "DMS".retention_policies (id),
    legal_hold                           BOOLEAN NOT NULL DEFAULT FALSE, -- overrides any scheduled retention action (§3F)
    extracted_text                         TEXT,        -- nullable; populated later by OCR (Future Version, §5 MVP scope note)
    search_vector                            TSVECTOR,  -- maintained by trigger below, not a generated column (tags live in a join table)
    created_at                                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                                   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_dms_documents_folder ON "DMS".documents (folder_id, status);
CREATE INDEX IF NOT EXISTS idx_dms_documents_subject ON "DMS".documents (subject_type, subject_id);
CREATE INDEX IF NOT EXISTS idx_dms_documents_search ON "DMS".documents USING GIN (search_vector);
CREATE INDEX IF NOT EXISTS idx_dms_documents_expiry ON "DMS".documents (expiry_date) WHERE status = 'active' AND NOT legal_hold;

-- -----------------------------------------------------------------------------
-- §3B / §3C / §4. Document versions — immutable, never overwritten in storage
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "DMS".document_versions (
    id                  BIGSERIAL PRIMARY KEY,
    document_id         BIGINT NOT NULL REFERENCES "DMS".documents (id) ON DELETE CASCADE,
    version_no          INTEGER NOT NULL CHECK (version_no > 0),
    original_filename    VARCHAR(255) NOT NULL,
    checksum_sha256        CHAR(64) NOT NULL,           -- captured from day one, for integrity + future dedupe (§5)
    storage_key               VARCHAR(500) NOT NULL,    -- tenant_{id}/DMS/{module}/{yyyy}/{mm}/{uuid}/v{n}.{ext}, see §4
    file_size_bytes              BIGINT NOT NULL CHECK (file_size_bytes >= 0),
    mime_type                       VARCHAR(100) NOT NULL,
    version_note                      VARCHAR(255),
    uploaded_by                         BIGINT REFERENCES users (id),
    uploaded_at                           TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (document_id, version_no)
);

CREATE INDEX IF NOT EXISTS idx_dms_document_versions_document ON "DMS".document_versions (document_id, version_no);

ALTER TABLE "DMS".documents
    ADD CONSTRAINT fk_dms_documents_current_version
        FOREIGN KEY (current_version_id) REFERENCES "DMS".document_versions (id);

-- -----------------------------------------------------------------------------
-- §4. Tags (free-tag MVP, §3B) and the document<->tag join
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "DMS".tags (
    id      BIGSERIAL PRIMARY KEY,
    name    VARCHAR(50) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS "DMS".document_tags (
    document_id BIGINT NOT NULL REFERENCES "DMS".documents (id) ON DELETE CASCADE,
    tag_id      BIGINT NOT NULL REFERENCES "DMS".tags (id) ON DELETE CASCADE,
    PRIMARY KEY (document_id, tag_id)
);

-- -----------------------------------------------------------------------------
-- §3H / §4. Object relations — explicit types; version_of is implicit via
-- document_versions and deliberately not one of these (§3H)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "DMS".document_relations (
    id                      BIGSERIAL PRIMARY KEY,
    source_document_id      BIGINT NOT NULL REFERENCES "DMS".documents (id),
    target_document_id      BIGINT NOT NULL REFERENCES "DMS".documents (id),
    relation_type            VARCHAR(15) NOT NULL
                                 CHECK (relation_type IN ('amendment_of', 'supersedes', 'attachment_of', 'related_to')),
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (source_document_id <> target_document_id)
);

CREATE INDEX IF NOT EXISTS idx_dms_document_relations_source ON "DMS".document_relations (source_document_id);
CREATE INDEX IF NOT EXISTS idx_dms_document_relations_target ON "DMS".document_relations (target_document_id);

-- -----------------------------------------------------------------------------
-- §3I / §4. Audit trail — append-only, no update/delete at the app layer
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "DMS".access_logs (
    id                      BIGSERIAL PRIMARY KEY,
    document_id              BIGINT NOT NULL REFERENCES "DMS".documents (id),
    document_version_id       BIGINT REFERENCES "DMS".document_versions (id), -- set for version-specific actions
    action                       VARCHAR(20) NOT NULL CHECK (action IN (
                                     'upload', 'view', 'download', 'edit_metadata', 'version_upload',
                                     'restore', 'delete', 'permission_change', 'hold_applied', 'hold_released'
                                 )),
    actor_id                        BIGINT REFERENCES users (id),
    ip_address                         VARCHAR(45),     -- optional (§3I)
    created_at                           TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_dms_access_logs_document ON "DMS".access_logs (document_id, created_at);

-- -----------------------------------------------------------------------------
-- §3E. Full-text search — search_vector maintained by trigger (title + current
-- version's filename + description + tags + extracted_text), since tags live
-- in a join table and can't feed a plain GENERATED column. Custom-field
-- metadata (via CUSTOMFIELDS) is deliberately NOT folded in here — reaching
-- into another schema from a trigger is more machinery than an MVP reference
-- script warrants; note it as a documented gap, not a silent omission.
-- -----------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION "DMS".refresh_document_search_vector(p_document_id BIGINT) RETURNS void AS $$
BEGIN
    UPDATE "DMS".documents d
    SET search_vector =
        setweight(to_tsvector('simple', coalesce(d.title, '')), 'A') ||
        setweight(to_tsvector('simple', coalesce((
            SELECT dv.original_filename FROM "DMS".document_versions dv WHERE dv.id = d.current_version_id
        ), '')), 'A') ||
        setweight(to_tsvector('simple', coalesce(d.description, '')), 'B') ||
        setweight(to_tsvector('simple', coalesce((
            SELECT string_agg(t.name, ' ') FROM "DMS".document_tags dt JOIN "DMS".tags t ON t.id = dt.tag_id WHERE dt.document_id = d.id
        ), '')), 'B') ||
        setweight(to_tsvector('simple', coalesce(d.extracted_text, '')), 'C')
    WHERE d.id = p_document_id;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION "DMS".documents_search_vector_trigger() RETURNS trigger AS $$
BEGIN
    PERFORM "DMS".refresh_document_search_vector(NEW.id);
    RETURN NULL; -- AFTER trigger, return value ignored
END;
$$ LANGUAGE plpgsql;

-- Fires only on title/description/extracted_text/current_version_id changes —
-- never on a search_vector-only UPDATE — so the AFTER-trigger UPDATE above
-- cannot recurse into itself.
DROP TRIGGER IF EXISTS trg_dms_documents_search_vector ON "DMS".documents;
CREATE TRIGGER trg_dms_documents_search_vector
    AFTER INSERT OR UPDATE OF title, description, extracted_text, current_version_id ON "DMS".documents
    FOR EACH ROW EXECUTE FUNCTION "DMS".documents_search_vector_trigger();

CREATE OR REPLACE FUNCTION "DMS".document_tags_search_vector_trigger() RETURNS trigger AS $$
BEGIN
    PERFORM "DMS".refresh_document_search_vector(COALESCE(NEW.document_id, OLD.document_id));
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_dms_document_tags_search_vector ON "DMS".document_tags;
CREATE TRIGGER trg_dms_document_tags_search_vector
    AFTER INSERT OR DELETE ON "DMS".document_tags
    FOR EACH ROW EXECUTE FUNCTION "DMS".document_tags_search_vector_trigger();

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
-- A Legal case's document folder with a filing (two versions, so the
-- current_version_id pointer and version history both have something to show),
-- an attached exhibit related to it, tags, a retention policy with legal-hold
-- awareness, and an audit trail. subject_type/subject_id reuses the same
-- 'legal.case_hdrs' / '4821' example CRM_SPECS.sql's After Sales case uses, so
-- both scripts describe the same hypothetical Legal case consistently.
--
-- Assumes Laravel's own `users` table already has the demo accounts from
-- database/seeders/DatabaseSeeder.php (admin@nusaevo.com, staff@nusaevo.com).
-- Written with plain subqueries and data-modifying CTEs only — no psql \gset —
-- so it runs through any Postgres client.
-- =============================================================================

BEGIN;

INSERT INTO "DMS".doc_types (code, name) VALUES
    ('CASE_FILING', 'Court Filing'),
    ('CONTRACT', 'Contract'),
    ('CORRESPONDENCE', 'Correspondence');

INSERT INTO "DMS".retention_policies (doc_type_id, retention_period_days, action_on_expiry, legal_hold_overridable)
SELECT id, 3650, 'notify_only', TRUE FROM "DMS".doc_types WHERE code = 'CASE_FILING'
UNION ALL
SELECT id, 2555, 'archive', TRUE FROM "DMS".doc_types WHERE code = 'CONTRACT';

WITH root AS (
    INSERT INTO "DMS".folders (name, access_flag, default_doc_type_id, default_retention_policy_id)
    SELECT 'Legal Documents', 'team', dt.id, rp.id
    FROM "DMS".doc_types dt
    JOIN "DMS".retention_policies rp ON rp.doc_type_id = dt.id
    WHERE dt.code = 'CASE_FILING'
    RETURNING id
)
INSERT INTO "DMS".folders (parent_folder_id, name, access_flag, default_doc_type_id)
SELECT root.id, 'Case A-001', 'team', (SELECT id FROM "DMS".doc_types WHERE code = 'CASE_FILING')
FROM root;

INSERT INTO "DMS".tags (name) VALUES ('urgent'), ('confidential'), ('draft-review');

-- The main filing document, uploaded once, then re-uploaded as v2 (a correction).
--
-- Split into two statements deliberately: PostgreSQL's data-modifying CTEs all
-- share ONE snapshot taken at the start of the statement, so a later CTE that
-- UPDATEs "DMS".documents cannot see a row an EARLIER CTE just INSERTed into
-- that same table within the same statement (RETURNING-based tuple-passing
-- still works fine for feeding values into other inserts, as v1/v2 below show —
-- it's specifically a target-table UPDATE/DELETE scan that can't see it). The
-- fix is the standard one: commit the document insert as its own statement
-- first, so the follow-up statement's fresh snapshot sees it normally.
INSERT INTO "DMS".documents (folder_id, doc_type_id, title, description, subject_type, subject_id, status, retention_policy_id)
SELECT f.id, dt.id, 'Gugatan - Case A-001', 'Initial complaint filing for case A-001.',
       'legal.case_hdrs', '4821', 'active', rp.id
FROM "DMS".folders f
JOIN "DMS".doc_types dt ON dt.code = 'CASE_FILING'
JOIN "DMS".retention_policies rp ON rp.doc_type_id = dt.id
WHERE f.name = 'Case A-001';

WITH doc AS (
    SELECT id, uuid FROM "DMS".documents WHERE title = 'Gugatan - Case A-001'
), v1 AS (
    INSERT INTO "DMS".document_versions (document_id, version_no, original_filename, checksum_sha256, storage_key, file_size_bytes, mime_type, version_note, uploaded_by)
    SELECT doc.id, 1, 'gugatan-a001-v1.pdf',
           'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
           'tenant_002/DMS/LEGAL/2026/08/' || doc.uuid || '/v1.pdf',
           245760, 'application/pdf', 'Initial upload',
           (SELECT id FROM users WHERE email = 'staff@nusaevo.com')
    FROM doc
    RETURNING id, document_id
), v2 AS (
    INSERT INTO "DMS".document_versions (document_id, version_no, original_filename, checksum_sha256, storage_key, file_size_bytes, mime_type, version_note, uploaded_by)
    SELECT doc.id, 2, 'gugatan-a001-v2-corrected.pdf',
           'f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5',
           'tenant_002/DMS/LEGAL/2026/08/' || doc.uuid || '/v2.pdf',
           248320, 'application/pdf', 'Corrected filing date typo',
           (SELECT id FROM users WHERE email = 'staff@nusaevo.com')
    FROM doc
    RETURNING id, document_id
), set_current AS (
    UPDATE "DMS".documents d
    SET current_version_id = v2.id
    FROM v2
    WHERE d.id = v2.document_id
    RETURNING d.id
), tag_link AS (
    INSERT INTO "DMS".document_tags (document_id, tag_id)
    SELECT set_current.id, t.id FROM set_current, "DMS".tags t WHERE t.name IN ('urgent', 'confidential')
    RETURNING document_id
)
INSERT INTO "DMS".access_logs (document_id, document_version_id, action, actor_id)
SELECT v1.document_id, v1.id, 'upload', (SELECT id FROM users WHERE email = 'staff@nusaevo.com') FROM v1
UNION ALL
SELECT v2.document_id, v2.id, 'version_upload', (SELECT id FROM users WHERE email = 'staff@nusaevo.com') FROM v2
UNION ALL
SELECT v2.document_id, v2.id, 'view', (SELECT id FROM users WHERE email = 'admin@nusaevo.com') FROM v2
WHERE EXISTS (SELECT 1 FROM tag_link);

-- A supporting exhibit, related to the main filing (same split-statement fix)
INSERT INTO "DMS".documents (folder_id, doc_type_id, title, description, subject_type, subject_id, status)
SELECT f.id, dt.id, 'Lampiran Bukti 1', 'Supporting exhibit for the A-001 filing.', 'legal.case_hdrs', '4821', 'active'
FROM "DMS".folders f, "DMS".doc_types dt
WHERE f.name = 'Case A-001' AND dt.code = 'CASE_FILING';

WITH exhibit AS (
    SELECT id, uuid FROM "DMS".documents WHERE title = 'Lampiran Bukti 1'
), exhibit_version AS (
    INSERT INTO "DMS".document_versions (document_id, version_no, original_filename, checksum_sha256, storage_key, file_size_bytes, mime_type, uploaded_by)
    SELECT exhibit.id, 1, 'lampiran-bukti-1.pdf',
           '0011223344556677889900112233445566778899001122334455667788990a',
           'tenant_002/DMS/LEGAL/2026/08/' || exhibit.uuid || '/v1.pdf',
           102400, 'application/pdf',
           (SELECT id FROM users WHERE email = 'staff@nusaevo.com')
    FROM exhibit
    RETURNING id, document_id
)
UPDATE "DMS".documents d
SET current_version_id = exhibit_version.id
FROM exhibit_version
WHERE d.id = exhibit_version.document_id;

INSERT INTO "DMS".document_relations (source_document_id, target_document_id, relation_type)
SELECT exhibit.id, main.id, 'attachment_of'
FROM "DMS".documents exhibit, "DMS".documents main
WHERE exhibit.title = 'Lampiran Bukti 1' AND main.title = 'Gugatan - Case A-001';

COMMIT;
