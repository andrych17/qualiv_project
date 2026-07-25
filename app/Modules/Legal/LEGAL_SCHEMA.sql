-- =====================================================================
-- LEGAL Module — Notary & PPAT Practice Management
-- Schema DDL — tenant database, schema "LEGAL"
-- Ref: LEGAL_SPECS.md §4
--
-- Conventions (per CLAUDE.md §7):
--   - One schema per module inside each tenant DB. No tenant_id column —
--     isolation is the database boundary (DB-per-tenant).
--   - BIGSERIAL for internal PK/FK. UUID for external-facing references.
--   - Cross-schema FK only Vertical -> Core (LEGAL -> CRM / SCHEDULE),
--     never the reverse.
-- Run with: psql -v ON_ERROR_STOP=1 -f LEGAL_SCHEMA.sql
-- =====================================================================

CREATE SCHEMA IF NOT EXISTS "LEGAL";
SET search_path TO "LEGAL", public;

-- ---------------------------------------------------------------------
-- Shared helper: updated_at auto-touch trigger (reused by every table)
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION "LEGAL".touch_updated_at() RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- =====================================================================
-- 1. LOOKUP / MASTER TABLES
-- =====================================================================

-- ---------------------------------------------------------------------
-- deed_types — extensible per §2 (no table-per-deed-type; type-specific
-- fields live in CUSTOMFIELDS instead).
-- ---------------------------------------------------------------------
CREATE TABLE "LEGAL".deed_types (
    id                          BIGSERIAL PRIMARY KEY,
    code                        VARCHAR(50)  NOT NULL UNIQUE,
    name                        VARCHAR(150) NOT NULL,
    category                    VARCHAR(20)  NOT NULL
        CHECK (category IN ('notary', 'ppat')),
    requires_tax                BOOLEAN NOT NULL DEFAULT FALSE,
    requires_bpn_registration   BOOLEAN NOT NULL DEFAULT FALSE,
    default_protocol_book_type  VARCHAR(30) NOT NULL DEFAULT 'repertorium'
        CHECK (default_protocol_book_type IN
            ('repertorium', 'legalisasi', 'waarmerking', 'protes', 'daftar_wasiat', 'lain_lain')),
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "LEGAL".deed_types IS
    'Extensible lookup for both Notary and PPAT act types. Type-specific fields belong in CUSTOMFIELDS, not new columns/tables.';

CREATE TRIGGER trg_deed_types_touch BEFORE UPDATE ON "LEGAL".deed_types
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".touch_updated_at();

-- ---------------------------------------------------------------------
-- party_role_types — tenant-editable, mirrors CRM.partner_role_types
-- ---------------------------------------------------------------------
CREATE TABLE "LEGAL".party_role_types (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(50)  NOT NULL UNIQUE,
    name        VARCHAR(150) NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TRIGGER trg_party_role_types_touch BEFORE UPDATE ON "LEGAL".party_role_types
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".touch_updated_at();

-- ---------------------------------------------------------------------
-- field_visit_types — lookup with a configurable default checklist
-- ---------------------------------------------------------------------
CREATE TABLE "LEGAL".field_visit_types (
    id                  BIGSERIAL PRIMARY KEY,
    code                VARCHAR(50)  NOT NULL UNIQUE,
    name                VARCHAR(150) NOT NULL,
    default_checklist   JSONB NOT NULL DEFAULT '[]',
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TRIGGER trg_field_visit_types_touch BEFORE UPDATE ON "LEGAL".field_visit_types
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".touch_updated_at();

-- =====================================================================
-- 2. MATTERS
-- =====================================================================
CREATE TABLE "LEGAL".matters (
    id                      BIGSERIAL PRIMARY KEY,
    uuid                    UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    title                   VARCHAR(255) NOT NULL,
    matter_type             VARCHAR(100) NOT NULL,
    partner_id              BIGINT NOT NULL REFERENCES "CRM".partners(id),
    assigned_to             BIGINT,               -- internal user id (auth/users table, out of module scope)
    status                  VARCHAR(20) NOT NULL DEFAULT 'open'
        CHECK (status IN ('open', 'in_progress', 'on_hold', 'closed')),
    opened_at               DATE NOT NULL DEFAULT CURRENT_DATE,
    target_close_at         DATE,
    converted_from_lead_id  BIGINT REFERENCES "CRM".leads(id),
    notes                   TEXT,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "LEGAL".matters IS
    'Client-facing engagement grouping one or more deeds. Vertical -> Core FK into CRM.partners / CRM.leads.';

CREATE INDEX idx_matters_partner ON "LEGAL".matters(partner_id);
CREATE INDEX idx_matters_status  ON "LEGAL".matters(status);

CREATE TRIGGER trg_matters_touch BEFORE UPDATE ON "LEGAL".matters
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".touch_updated_at();

-- =====================================================================
-- 3. LAND OBJECTS (referenced by deeds + due diligence)
-- =====================================================================
CREATE TABLE "LEGAL".land_objects (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    certificate_type    VARCHAR(20) NOT NULL
        CHECK (certificate_type IN ('SHM', 'HGB', 'HGU', 'Hak_Pakai', 'other')),
    certificate_number  VARCHAR(100) NOT NULL,
    nib                 VARCHAR(100),             -- Nomor Identifikasi Bidang
    address             TEXT NOT NULL,
    area_m2             NUMERIC(14,2),
    njop_reference      NUMERIC(18,2),            -- Nilai Jual Objek Pajak reference value
    current_owner_id    BIGINT REFERENCES "CRM".partners(id),  -- informational only; certificate is source of truth
    status              VARCHAR(20) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'in_transaction', 'transferred', 'disputed')),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (certificate_type, certificate_number)
);

CREATE INDEX idx_land_objects_owner  ON "LEGAL".land_objects(current_owner_id);
CREATE INDEX idx_land_objects_status ON "LEGAL".land_objects(status);

CREATE TRIGGER trg_land_objects_touch BEFORE UPDATE ON "LEGAL".land_objects
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".touch_updated_at();

-- =====================================================================
-- 4. DEEDS (unified Notary + PPAT act model)
-- =====================================================================
CREATE TABLE "LEGAL".deeds (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    matter_id           BIGINT REFERENCES "LEGAL".matters(id),   -- nullable: some deeds stand alone
    deed_type_id        BIGINT NOT NULL REFERENCES "LEGAL".deed_types(id),
    category            VARCHAR(20) NOT NULL
        CHECK (category IN ('notary', 'ppat')),
    land_object_id      BIGINT REFERENCES "LEGAL".land_objects(id),  -- required in practice for PPAT deeds; enforced at app layer per deed_type
    deed_number         VARCHAR(100),             -- assigned on signing, from the active protocol book volume
    status              VARCHAR(20) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'ready_for_signing', 'signed', 'archived')),
    signing_date        DATE,
    minuta_reference    VARCHAR(150),
    transaction_value   NUMERIC(18,2),            -- PPAT deeds
    summary             TEXT,
    amends_deed_id       BIGINT REFERENCES "LEGAL".deeds(id),   -- non-destructive correction path, see §5
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "LEGAL".deeds IS
    'Unified header for all Notary Practice and PPAT Practice acts (general deeds, wasiat, legalisasi, waarmerking, AJB, hibah, etc.), discriminated by deed_types.category/code. Immutable once signed — see trg_deeds_immutability.';
COMMENT ON COLUMN "LEGAL".deeds.amends_deed_id IS
    'Corrections to a signed deed are new deeds referencing the original, never an edit-in-place.';

CREATE INDEX idx_deeds_matter      ON "LEGAL".deeds(matter_id);
CREATE INDEX idx_deeds_type        ON "LEGAL".deeds(deed_type_id);
CREATE INDEX idx_deeds_land_object ON "LEGAL".deeds(land_object_id);
CREATE INDEX idx_deeds_status      ON "LEGAL".deeds(status);

CREATE TRIGGER trg_deeds_touch BEFORE UPDATE ON "LEGAL".deeds
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".touch_updated_at();

-- Deed immutability: once signed, lock content columns. Only status
-- (signed -> archived) and deed_number/minuta assignment-on-signing are
-- allowed to change afterward. See LEGAL_SPECS.md §5.
CREATE OR REPLACE FUNCTION "LEGAL".enforce_deed_immutability() RETURNS TRIGGER AS $$
BEGIN
    IF OLD.status = 'signed' THEN
        IF NEW.status NOT IN ('signed', 'archived') THEN
            RAISE EXCEPTION 'Signed deeds cannot change status to %; use an amending deed instead.', NEW.status;
        END IF;
        IF NEW.matter_id            IS DISTINCT FROM OLD.matter_id
        OR NEW.deed_type_id         IS DISTINCT FROM OLD.deed_type_id
        OR NEW.category             IS DISTINCT FROM OLD.category
        OR NEW.land_object_id       IS DISTINCT FROM OLD.land_object_id
        OR NEW.signing_date         IS DISTINCT FROM OLD.signing_date
        OR NEW.transaction_value    IS DISTINCT FROM OLD.transaction_value
        OR NEW.summary              IS DISTINCT FROM OLD.summary
        THEN
            RAISE EXCEPTION 'Signed deed content is immutable (deed id %). Create an amending deed instead.', OLD.id;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_deeds_immutability BEFORE UPDATE ON "LEGAL".deeds
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".enforce_deed_immutability();

-- =====================================================================
-- 5. DEED PARTIES — identity snapshot at signing time (§3J)
-- =====================================================================
CREATE TABLE "LEGAL".deed_parties (
    id                  BIGSERIAL PRIMARY KEY,
    deed_id             BIGINT NOT NULL REFERENCES "LEGAL".deeds(id) ON DELETE CASCADE,
    partner_id          BIGINT NOT NULL REFERENCES "CRM".partners(id),
    role_type_id        BIGINT NOT NULL REFERENCES "LEGAL".party_role_types(id),
    identity_snapshot   JSONB NOT NULL DEFAULT '{}',  -- name, ID number/NIK, address, etc. as of signing
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON COLUMN "LEGAL".deed_parties.identity_snapshot IS
    'Authoritative for this deed forever, even if CRM.partners is later corrected. Never re-derive from the live partner record after signing.';

CREATE INDEX idx_deed_parties_deed    ON "LEGAL".deed_parties(deed_id);
CREATE INDEX idx_deed_parties_partner ON "LEGAL".deed_parties(partner_id);

CREATE TRIGGER trg_deed_parties_touch BEFORE UPDATE ON "LEGAL".deed_parties
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".touch_updated_at();

-- =====================================================================
-- 6. WILLS — extends deeds where deed_type = wasiat (§3D)
-- =====================================================================
CREATE TABLE "LEGAL".wills (
    id                  BIGSERIAL PRIMARY KEY,
    deed_id             BIGINT NOT NULL UNIQUE REFERENCES "LEGAL".deeds(id) ON DELETE CASCADE,
    testator_id         BIGINT NOT NULL REFERENCES "CRM".partners(id),
    dpw_reg_number      VARCHAR(100),             -- Daftar Pusat Wasiat registration number (AHU)
    dpw_registered_at   DATE,
    status              VARCHAR(20) NOT NULL DEFAULT 'drafted'
        CHECK (status IN ('drafted', 'dpw_registered', 'active', 'opened', 'revoked')),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "LEGAL".wills IS
    'DPW (Daftar Pusat Wasiat) registration tracking. A signed will not yet dpw_registered past the tenant grace period should surface as a warning/danger on the dashboard.';

CREATE INDEX idx_wills_testator ON "LEGAL".wills(testator_id);
CREATE INDEX idx_wills_status   ON "LEGAL".wills(status);

CREATE TRIGGER trg_wills_touch BEFORE UPDATE ON "LEGAL".wills
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".touch_updated_at();

-- =====================================================================
-- 7. DUE DILIGENCE CHECKS (§3I)
-- =====================================================================
CREATE TABLE "LEGAL".due_diligence_checks (
    id              BIGSERIAL PRIMARY KEY,
    land_object_id  BIGINT NOT NULL REFERENCES "LEGAL".land_objects(id) ON DELETE CASCADE,
    check_type      VARCHAR(30) NOT NULL
        CHECK (check_type IN ('sertifikat_validity', 'pbb_payment_status', 'blokir_sengketa', 'zona_nilai_tanah')),
    status          VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'clear', 'flagged')),
    checked_by      BIGINT,                       -- internal user id
    checked_at      TIMESTAMPTZ,
    result_notes    TEXT,
    override_reason TEXT,                         -- required if a flagged check is overridden to allow signing
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_ddc_land_object ON "LEGAL".due_diligence_checks(land_object_id);
CREATE INDEX idx_ddc_status      ON "LEGAL".due_diligence_checks(status);

CREATE TRIGGER trg_ddc_touch BEFORE UPDATE ON "LEGAL".due_diligence_checks
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".touch_updated_at();

-- =====================================================================
-- 8. DEED TAXES — PPh Final / BPHTB tracking (§3K)
-- =====================================================================
CREATE TABLE "LEGAL".deed_taxes (
    id                  BIGSERIAL PRIMARY KEY,
    deed_id             BIGINT NOT NULL REFERENCES "LEGAL".deeds(id) ON DELETE CASCADE,
    tax_type            VARCHAR(20) NOT NULL
        CHECK (tax_type IN ('pph_final', 'bphtb')),
    taxpayer_partner_id BIGINT NOT NULL REFERENCES "CRM".partners(id),
    transaction_amount  NUMERIC(18,2) NOT NULL,   -- reported transaction value
    njop_amount         NUMERIC(18,2),            -- NJOP reference, kept alongside so "whichever is higher" is transparent
    base_amount         NUMERIC(18,2) NOT NULL,   -- the higher of the two above; basis for the rate
    rate_percent        NUMERIC(5,3) NOT NULL,    -- 2.5 for PPh Final, 5 for BPHTB (tenant-configurable, regulation can change)
    npoptkp_applied     NUMERIC(18,2) NOT NULL DEFAULT 0,  -- BPHTB only; local-government figure
    computed_amount     NUMERIC(18,2) NOT NULL,
    billing_code        VARCHAR(100),             -- Kode Billing (Coretax) for PPh, or SSPD reference for BPHTB
    ntpn                VARCHAR(100),             -- Nomor Transaksi Penerimaan Negara, proof of payment
    status               VARCHAR(25) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'billing_code_issued', 'paid', 'validated')),
    paid_at             DATE,
    validated_at        DATE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "LEGAL".deed_taxes IS
    'Tracks PPh Final (seller, PP 34/2016) and BPHTB (buyer, UU 28/2009) status through to validated, which gates PPAT deed signing. Tracks status only — Coretax/Bapenda remain the systems of record, this module does not file or remit.';

CREATE INDEX idx_deed_taxes_deed   ON "LEGAL".deed_taxes(deed_id);
CREATE INDEX idx_deed_taxes_status ON "LEGAL".deed_taxes(status);

CREATE TRIGGER trg_deed_taxes_touch BEFORE UPDATE ON "LEGAL".deed_taxes
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".touch_updated_at();

-- =====================================================================
-- 9. BPN SUBMISSIONS (§3L)
-- =====================================================================
CREATE TABLE "LEGAL".bpn_submissions (
    id                  BIGSERIAL PRIMARY KEY,
    deed_id             BIGINT NOT NULL REFERENCES "LEGAL".deeds(id) ON DELETE CASCADE,
    submission_type     VARCHAR(30) NOT NULL
        CHECK (submission_type IN ('balik_nama', 'apht_registration', 'ht_elektronik', 'split', 'merge', 'other')),
    submitted_at        DATE,
    tracking_number     VARCHAR(100),
    pnbp_amount         NUMERIC(18,2),            -- PNBP fee: (nilai_tanah / 1000) + 50000, editable for local variation
    status              VARCHAR(20) NOT NULL DEFAULT 'prepared'
        CHECK (status IN ('prepared', 'submitted', 'in_process', 'completed', 'rejected')),
    rejection_reason    TEXT,
    completed_at        DATE,
    resubmission_of_id  BIGINT REFERENCES "LEGAL".bpn_submissions(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "LEGAL".bpn_submissions IS
    'Manually-updated tracker for post-signing land registry actions. No live BPN API at this scale — see LEGAL_SPECS.md §5 scope note. Rejections create a new row via resubmission_of_id, never an edit-in-place.';

CREATE INDEX idx_bpn_submissions_deed   ON "LEGAL".bpn_submissions(deed_id);
CREATE INDEX idx_bpn_submissions_status ON "LEGAL".bpn_submissions(status);

CREATE TRIGGER trg_bpn_submissions_touch BEFORE UPDATE ON "LEGAL".bpn_submissions
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".touch_updated_at();

-- =====================================================================
-- 10. NOTARY PROTOCOL (§3F)
-- =====================================================================
CREATE TABLE "LEGAL".protocol_books (
    id              BIGSERIAL PRIMARY KEY,
    book_type       VARCHAR(30) NOT NULL
        CHECK (book_type IN ('repertorium', 'legalisasi', 'waarmerking', 'protes', 'daftar_wasiat', 'lain_lain')),
    year            INT NOT NULL,
    volume          INT NOT NULL DEFAULT 1,
    notary_id       BIGINT NOT NULL,             -- internal user id (the notary of record)
    status          VARCHAR(20) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'closed', 'handed_over')),
    opened_at       DATE NOT NULL DEFAULT CURRENT_DATE,
    closed_at       DATE,
    handed_over_to  VARCHAR(255),                -- successor notary or MPD (Majelis Pengawas Daerah)
    handed_over_at  DATE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (book_type, year, volume, notary_id)
);
COMMENT ON TABLE "LEGAL".protocol_books IS
    'The statutory ledger-of-ledgers (UU 2/2014). One row per book type x year x volume x notary.';

CREATE INDEX idx_protocol_books_active
    ON "LEGAL".protocol_books(book_type, notary_id)
    WHERE status = 'active';

CREATE TRIGGER trg_protocol_books_touch BEFORE UPDATE ON "LEGAL".protocol_books
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".touch_updated_at();

CREATE TABLE "LEGAL".protocol_entries (
    id                  BIGSERIAL PRIMARY KEY,
    book_id             BIGINT NOT NULL REFERENCES "LEGAL".protocol_books(id),
    deed_id             BIGINT REFERENCES "LEGAL".deeds(id),
    sequence_number     INT,                      -- auto-assigned gap-free per book, see trigger below
    entry_date          DATE NOT NULL DEFAULT CURRENT_DATE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (book_id, sequence_number)
);
COMMENT ON TABLE "LEGAL".protocol_entries IS
    'Append-only ledger. No UPDATE/DELETE permitted — enforced by trigger, not just app-layer convention, same discipline as DMS.access_logs.';

CREATE INDEX idx_protocol_entries_deed ON "LEGAL".protocol_entries(deed_id);

-- Gap-free auto-numbering per (book_id): lock the parent book row to
-- serialize concurrent signings, then take MAX(sequence_number)+1.
CREATE OR REPLACE FUNCTION "LEGAL".assign_protocol_sequence() RETURNS TRIGGER AS $$
DECLARE
    next_seq INT;
BEGIN
    IF NEW.sequence_number IS NULL THEN
        PERFORM 1 FROM "LEGAL".protocol_books WHERE id = NEW.book_id FOR UPDATE;
        SELECT COALESCE(MAX(sequence_number), 0) + 1 INTO next_seq
            FROM "LEGAL".protocol_entries WHERE book_id = NEW.book_id;
        NEW.sequence_number := next_seq;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_assign_protocol_sequence
    BEFORE INSERT ON "LEGAL".protocol_entries
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".assign_protocol_sequence();

-- Append-only enforcement: block UPDATE and DELETE outright.
CREATE OR REPLACE FUNCTION "LEGAL".block_protocol_entry_mutation() RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'protocol_entries is append-only: % is not permitted (entry id %).', TG_OP, OLD.id;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_protocol_entries_no_update
    BEFORE UPDATE ON "LEGAL".protocol_entries
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".block_protocol_entry_mutation();

CREATE TRIGGER trg_protocol_entries_no_delete
    BEFORE DELETE ON "LEGAL".protocol_entries
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".block_protocol_entry_mutation();

-- =====================================================================
-- 11. FIELD VISITS (§3M) — the mobile field-operator workflow
-- =====================================================================
CREATE TABLE "LEGAL".field_visits (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,  -- external-facing: used by the mobile API
    matter_id           BIGINT REFERENCES "LEGAL".matters(id),
    land_object_id      BIGINT REFERENCES "LEGAL".land_objects(id),
    deed_id             BIGINT REFERENCES "LEGAL".deeds(id),
    visit_type_id       BIGINT NOT NULL REFERENCES "LEGAL".field_visit_types(id),
    assigned_to         BIGINT NOT NULL,          -- internal user id (field operator)
    schedule_item_id    BIGINT REFERENCES "SCHEDULE".sched_items(id),
    status              VARCHAR(20) NOT NULL DEFAULT 'scheduled'
        CHECK (status IN ('scheduled', 'checked_in', 'completed', 'cancelled')),
    checked_in_at       TIMESTAMPTZ,
    gps_lat             NUMERIC(10,7),
    gps_lng             NUMERIC(10,7),
    checklist_result    JSONB NOT NULL DEFAULT '[]',
    notes               TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (matter_id IS NOT NULL OR land_object_id IS NOT NULL OR deed_id IS NOT NULL)
);
COMMENT ON TABLE "LEGAL".field_visits IS
    'Mobile field-operator workflow. schedule_item_id is Vertical -> Core FK into SCHEDULE.sched_items, reusing Schedule''s calendar rather than building a second one.';

CREATE INDEX idx_field_visits_matter      ON "LEGAL".field_visits(matter_id);
CREATE INDEX idx_field_visits_land_object ON "LEGAL".field_visits(land_object_id);
CREATE INDEX idx_field_visits_deed        ON "LEGAL".field_visits(deed_id);
CREATE INDEX idx_field_visits_assigned    ON "LEGAL".field_visits(assigned_to);
CREATE INDEX idx_field_visits_status      ON "LEGAL".field_visits(status);

CREATE TRIGGER trg_field_visits_touch BEFORE UPDATE ON "LEGAL".field_visits
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".touch_updated_at();

-- =====================================================================
-- End of LEGAL schema
-- =====================================================================
