-- =====================================================================
-- DMS — Document Management System
-- Schema DDL (PostgreSQL)
--
-- Assumption: PostgreSQL, consistent with wne_schema.sql (CREATE SCHEMA
-- used as a real namespace object). Follows the same tenant-DB-per-
-- tenant model — this schema lives inside each tenant's database.
--
-- Naming:
--   - Master tables   : single word              (folders, doc_types, tags, retention_policies)
--   - Transaction tbl : 2 parts, domain_ prefix   (document_versions, document_relations, document_tags)
--
-- Multi-tenancy: tenant_id is a plain bigint column (no FK), same
-- convention as WNE. Enforce isolation via app-level global scope.
--
-- MVP scope note: extracted_text (OCR) and embedding (semantic search)
-- columns are included now, nullable, so the Future Version work
-- (OCR + pgvector) is an additive migration, not a breaking one. See
-- DMS_SPECS.md §5.
-- =====================================================================

CREATE SCHEMA IF NOT EXISTS "DMS";
SET search_path TO "DMS";

-- =====================================================================
-- MASTER TABLES
-- =====================================================================

-- Tenant-defined document types (Contract, Court Filing, PO Attachment, ...)
CREATE TABLE "DMS".doc_types (
    id                      BIGSERIAL PRIMARY KEY,
    tenant_id               BIGINT       NOT NULL,
    code                    VARCHAR(50)  NOT NULL,
    name                    VARCHAR(150) NOT NULL,
    description             TEXT,
    default_retention_policy_id BIGINT,             -- FK added below, after retention_policies exists
    is_active               BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, code)
);
CREATE INDEX idx_doc_types_tenant ON "DMS".doc_types(tenant_id);

-- Retention rules per tenant, optionally scoped to a doc_type
CREATE TABLE "DMS".retention_policies (
    id                      BIGSERIAL PRIMARY KEY,
    tenant_id               BIGINT       NOT NULL,
    doc_type_id             BIGINT       REFERENCES "DMS".doc_types(id),  -- NULL = tenant-wide default
    name                    VARCHAR(150) NOT NULL,
    retention_period_days   INT          NOT NULL,
    action_on_expiry        VARCHAR(20)  NOT NULL DEFAULT 'notify_only', -- notify_only, archive, delete
    legal_hold_overridable  BOOLEAN      NOT NULL DEFAULT TRUE,
    is_active               BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_retention_policies_tenant ON "DMS".retention_policies(tenant_id, doc_type_id);

ALTER TABLE "DMS".doc_types
    ADD CONSTRAINT fk_doc_types_default_retention
    FOREIGN KEY (default_retention_policy_id) REFERENCES "DMS".retention_policies(id);

-- Folder tree — used for standalone library browsing and default policy inheritance
CREATE TABLE "DMS".folders (
    id                      BIGSERIAL PRIMARY KEY,
    tenant_id               BIGINT       NOT NULL,
    parent_id               BIGINT       REFERENCES "DMS".folders(id),
    name                    VARCHAR(150) NOT NULL,
    access_level            VARCHAR(20)  NOT NULL DEFAULT 'tenant',  -- private, team, tenant
    default_doc_type_id     BIGINT       REFERENCES "DMS".doc_types(id),
    default_retention_policy_id BIGINT   REFERENCES "DMS".retention_policies(id),
    created_by              BIGINT,
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    deleted_at              TIMESTAMPTZ
);
CREATE INDEX idx_folders_tenant_parent ON "DMS".folders(tenant_id, parent_id);

-- Free-tag vocabulary, tenant scoped
CREATE TABLE "DMS".tags (
    id                      BIGSERIAL PRIMARY KEY,
    tenant_id               BIGINT       NOT NULL,
    code                    VARCHAR(50)  NOT NULL,
    name                    VARCHAR(100) NOT NULL,
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, code)
);
CREATE INDEX idx_tags_tenant ON "DMS".tags(tenant_id);

-- =====================================================================
-- TRANSACTION TABLES — DOCUMENT DOMAIN (document_*)
-- =====================================================================

-- Document header. subject_type/subject_id = polymorphic link to any owning
-- module record (e.g. Legal Case, Purchase Order). Both NULL = standalone
-- document living only in the folder tree.
CREATE TABLE "DMS".documents (
    id                      BIGSERIAL PRIMARY KEY,
    tenant_id               BIGINT       NOT NULL,
    folder_id               BIGINT       REFERENCES "DMS".folders(id),
    doc_type_id             BIGINT       REFERENCES "DMS".doc_types(id),
    subject_type            VARCHAR(150),                       -- owning module's model class, nullable
    subject_id              BIGINT,                             -- nullable
    title                   VARCHAR(255) NOT NULL,
    description              TEXT,
    current_version_id      BIGINT,                             -- FK added after document_versions exists
    status                   VARCHAR(20)  NOT NULL DEFAULT 'draft', -- draft, active, archived, expired, purged
    legal_hold               BOOLEAN      NOT NULL DEFAULT FALSE,
    retention_policy_id      BIGINT       REFERENCES "DMS".retention_policies(id),
    effective_date            DATE,
    expiry_date                DATE,
    search_text                TEXT,                            -- app-maintained: filename + tags + extracted_text, feeds search_vector
    search_vector               TSVECTOR GENERATED ALWAYS AS (
                                    setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                                    setweight(to_tsvector('english', coalesce(description, '')), 'B') ||
                                    setweight(to_tsvector('english', coalesce(search_text, '')), 'C')
                                 ) STORED,
    created_by                BIGINT,
    updated_by                BIGINT,
    created_at                TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ  NOT NULL DEFAULT now(),
    deleted_at                TIMESTAMPTZ
);
CREATE INDEX idx_documents_tenant_status ON "DMS".documents(tenant_id, status);
CREATE INDEX idx_documents_subject ON "DMS".documents(tenant_id, subject_type, subject_id);
CREATE INDEX idx_documents_folder ON "DMS".documents(folder_id);
CREATE INDEX idx_documents_legal_hold ON "DMS".documents(tenant_id, legal_hold) WHERE legal_hold = TRUE;
CREATE INDEX idx_documents_expiry ON "DMS".documents(tenant_id, expiry_date) WHERE expiry_date IS NOT NULL;
CREATE INDEX idx_documents_search_vector ON "DMS".documents USING GIN(search_vector);

-- Immutable version history. Never overwritten; storage_key always points
-- to a distinct object in R2. extracted_text/embedding are Future Version
-- (OCR / semantic search) columns, nullable until that pipeline exists.
CREATE TABLE "DMS".document_versions (
    id                      BIGSERIAL PRIMARY KEY,
    document_id             BIGINT       NOT NULL REFERENCES "DMS".documents(id) ON DELETE CASCADE,
    version_no               INT          NOT NULL,
    storage_key               VARCHAR(500) NOT NULL,            -- tenant_{id}/DMS/{module}/{yyyy}/{mm}/{uuid}/v{n}.ext
    file_name                  VARCHAR(255) NOT NULL,
    mime_type                   VARCHAR(150),
    size_bytes                   BIGINT,
    checksum_sha256                CHAR(64),
    extracted_text                  TEXT,                       -- Future Version: populated by OCR pipeline
    -- embedding                    VECTOR(1536),                -- Future Version: pgvector, add via migration when semantic search ships
    upload_note                      TEXT,
    uploaded_by                       BIGINT       NOT NULL,
    created_at                         TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at                         TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (document_id, version_no)
);
CREATE INDEX idx_document_versions_document ON "DMS".document_versions(document_id);
CREATE INDEX idx_document_versions_checksum ON "DMS".document_versions(checksum_sha256);

ALTER TABLE "DMS".documents
    ADD CONSTRAINT fk_documents_current_version
    FOREIGN KEY (current_version_id) REFERENCES "DMS".document_versions(id);

-- Document-to-document relations (amendment, supersession, attachment grouping, ...)
CREATE TABLE "DMS".document_relations (
    id                      BIGSERIAL PRIMARY KEY,
    source_document_id       BIGINT       NOT NULL REFERENCES "DMS".documents(id) ON DELETE CASCADE,
    target_document_id        BIGINT       NOT NULL REFERENCES "DMS".documents(id) ON DELETE CASCADE,
    relation_type               VARCHAR(30)  NOT NULL,           -- amendment_of, supersedes, attachment_of, related_to
    created_by                    BIGINT,
    created_at                     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CHECK (source_document_id <> target_document_id)
);
CREATE INDEX idx_document_relations_source ON "DMS".document_relations(source_document_id);
CREATE INDEX idx_document_relations_target ON "DMS".document_relations(target_document_id);

-- Document <-> tag junction
CREATE TABLE "DMS".document_tags (
    id                      BIGSERIAL PRIMARY KEY,
    document_id              BIGINT       NOT NULL REFERENCES "DMS".documents(id) ON DELETE CASCADE,
    tag_id                     BIGINT       NOT NULL REFERENCES "DMS".tags(id) ON DELETE CASCADE,
    created_at                  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (document_id, tag_id)
);
CREATE INDEX idx_document_tags_tag ON "DMS".document_tags(tag_id);

-- Immutable audit trail. No update/delete permitted at the app layer.
CREATE TABLE "DMS".access_logs (
    id                      BIGSERIAL PRIMARY KEY,
    tenant_id                BIGINT       NOT NULL,
    document_id               BIGINT       NOT NULL REFERENCES "DMS".documents(id) ON DELETE CASCADE,
    document_version_id        BIGINT       REFERENCES "DMS".document_versions(id),
    actor_id                     BIGINT       NOT NULL,
    action                         VARCHAR(30)  NOT NULL,       -- upload, view, download, edit_metadata,
                                                                  -- version_upload, restore, delete,
                                                                  -- permission_change, hold_applied, hold_released
    ip_address                     VARCHAR(45),
    metadata                        JSONB        NOT NULL DEFAULT '{}',
    created_at                       TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_access_logs_document ON "DMS".access_logs(document_id, created_at);
CREATE INDEX idx_access_logs_tenant ON "DMS".access_logs(tenant_id, action, created_at);

-- =====================================================================
-- SEED — minimal master data to bootstrap the module
-- (tenant-specific doc_types/tags/policies are seeded in the sample
-- data script, not here — this file only holds schema-level bootstrap)
-- =====================================================================

-- No tenant-agnostic master data needed for DMS (unlike WNE's global
-- channels/actions) — doc_types, tags, and retention_policies are all
-- tenant-defined vocabulary. See dms_dummy_data.sql.
