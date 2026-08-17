-- =============================================================================
-- Legal module — schema + example seed data
-- Source spec: app/Modules/Legal/LEGAL_SPECS.md (see that file for full
-- rationale)
-- Target DB:   a single TENANT DB (e.g. `tenant_002`), schema "LEGAL" — every
--              table lives inside one tenant's own database (CLAUDE.md §4/§7A),
--              no tenant_id column anywhere.
--
-- This is the platform's first VERTICAL module (not Core) — it depends on CRM,
-- DMS, and Schedule (Vertical -> Core, the only allowed direction, §5), and
-- those Core modules have zero knowledge of LEGAL.*. `matters.partner_id`,
-- `deed_parties.partner_id`, and `land_objects.current_owner_partner_id` are
-- all real cross-schema FKs into "CRM".partners(id) — §5 explicitly confirms
-- this direction is safe ("Vertical depending on Core"), unlike the
-- deliberately-unenforced subject_type/subject_id pointers used elsewhere.
-- `field_visits.schedule_item_id` is likewise a real FK into
-- "SCHEDULE".sched_items(id), per §4's explicit "(FK SCHEDULE.sched_items)".
--
-- Legalization and Waarmerking (§3E) get NO dedicated table — §4's storage
-- list has none, and §2's own framing ("lighter-weight than a full deed...
-- same numbering mechanism as 3C/3F, just a different book") only makes sense
-- if they are rows in LEGAL.deeds too (deed_type_id -> a 'legalisasi'/
-- 'waarmerking' deed type), just with a lighter set of populated fields. Same
-- reasoning applies to Wasiat (§3D): it's a deed row PLUS a 1:1 `LEGAL.wills`
-- extension row, not a parallel deed-like table.
--
-- Deed immutability and protocol-ledger integrity (§5) are the two places this
-- module is "a legal, not just a data-hygiene, requirement" — `deeds` gets an
-- app-layer-immutable convention (corrections are a new `amends_deed_id`-
-- linked deed, never an edit) while `protocol_entries` gets the same real
-- BEFORE UPDATE OR DELETE trigger INVENTORY_SPECS.sql uses for stock_ledger —
-- "no update/delete at the app layer" given actual DB teeth here, since a
-- Notaris is personally liable for this specific ledger.
--
-- This file is a reference/planning script, not a Laravel migration — port
-- each section below into `database/migrations/tenant/*.php` per the suggested
-- build order in §5 (matters/deeds -> party linkage -> protocol numbering ->
-- land object/due diligence -> PPAT deeds -> tax engine -> BPN tracking ->
-- wills/legalization/waarmerking -> field operations).
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto; -- gen_random_uuid() for external-facing ids (CLAUDE.md §7A)

-- Quoted, same convention as "PROJECTS"/"AIINSIGHT"/"ACCOUNTING"/"CRM"/"DMS"/"HCM"/"INVENTORY"/"CUSTOMFIELDS".
CREATE SCHEMA IF NOT EXISTS "LEGAL";

-- -----------------------------------------------------------------------------
-- §4. Master / lookup tables
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "LEGAL".deed_types (
    id                              BIGSERIAL PRIMARY KEY,
    code                            VARCHAR(30) NOT NULL UNIQUE,
    name                            VARCHAR(150) NOT NULL,
    category                        VARCHAR(10) NOT NULL CHECK (category IN ('notary', 'ppat')),
    requires_tax                    BOOLEAN NOT NULL DEFAULT FALSE,
    requires_bpn_registration       BOOLEAN NOT NULL DEFAULT FALSE,
    default_protocol_book_type      VARCHAR(20) CHECK (default_protocol_book_type IN
                                         ('repertorium', 'legalisasi', 'waarmerking', 'protes', 'daftar_wasiat', 'lain_lain')),
    is_active                       BOOLEAN NOT NULL DEFAULT TRUE
);

-- Tenant-editable, mirrors CRM.partner_role_types' pattern (§4)
CREATE TABLE IF NOT EXISTS "LEGAL".party_role_types (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(30) NOT NULL UNIQUE,              -- penghadap, pihak_pertama, pihak_kedua, saksi, kuasa, ahli_waris, ...
    name        VARCHAR(100) NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS "LEGAL".field_visit_types (
    id                  BIGSERIAL PRIMARY KEY,
    code                VARCHAR(30) NOT NULL UNIQUE,       -- site_survey, bpn_office_visit, document_pickup, signing_witness, other
    name                VARCHAR(100) NOT NULL,
    default_checklist   JSONB,                             -- tenant-configurable checklist template (§3M)
    is_active           BOOLEAN NOT NULL DEFAULT TRUE
);

-- -----------------------------------------------------------------------------
-- §3H / §4. Land Object Registry — created before matters/deeds since a PPAT
-- deed optionally references one.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "LEGAL".land_objects (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    certificate_type            VARCHAR(15) NOT NULL CHECK (certificate_type IN ('SHM', 'HGB', 'HGU', 'HAK_PAKAI', 'other')),
    certificate_number          VARCHAR(50) NOT NULL,
    nib                         VARCHAR(50),               -- Nomor Identifikasi Bidang
    address                     TEXT,
    area_m2                     NUMERIC(12, 2),
    njop_reference              NUMERIC(18, 2),
    current_owner_partner_id    BIGINT REFERENCES "CRM".partners (id), -- informational — the certificate is the source of truth, not this field (§3H); still a real FK per §5
    status                      VARCHAR(15) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'in_transaction', 'transferred', 'disputed')),
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (certificate_type, certificate_number)
);

-- -----------------------------------------------------------------------------
-- §3B / §4. Matters (Engagements)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "LEGAL".matters (
    id                          BIGSERIAL PRIMARY KEY,
    uuid                        UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    title                       VARCHAR(255) NOT NULL,
    matter_type                 VARCHAR(100),              -- free lookup: "Property Purchase," "Company Incorporation," ...
    partner_id                  BIGINT NOT NULL REFERENCES "CRM".partners (id), -- primary client
    assigned_to                 BIGINT REFERENCES users (id),
    status                      VARCHAR(12) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'in_progress', 'on_hold', 'closed')),
    opened_at                   DATE NOT NULL DEFAULT CURRENT_DATE,
    target_close_at             DATE,
    converted_from_lead_id      BIGINT REFERENCES "CRM".leads (id), -- optional origin link (§3B)
    notes                       TEXT,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_legal_matters_partner ON "LEGAL".matters (partner_id, status);

-- -----------------------------------------------------------------------------
-- §3C / §3D / §3E / §3G / §4. Deeds — the unified model spanning Notary and
-- PPAT practices, including Wills, Legalization, and Waarmerking (see header).
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "LEGAL".deeds (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    matter_id           BIGINT REFERENCES "LEGAL".matters (id), -- nullable: some deeds are standalone (§3C)
    deed_type_id        BIGINT NOT NULL REFERENCES "LEGAL".deed_types (id),
    category            VARCHAR(10) NOT NULL CHECK (category IN ('notary', 'ppat')), -- denormalized from deed_type for fast filtering
    land_object_id      BIGINT REFERENCES "LEGAL".land_objects (id), -- PPAT land deeds only (§3G)
    deed_number         VARCHAR(50),                      -- assigned on signing (§3C)
    status              VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'ready_for_signing', 'signed', 'archived')),
    signing_date        DATE,
    minuta_reference    VARCHAR(100),
    summary             TEXT,
    amends_deed_id      BIGINT REFERENCES "LEGAL".deeds (id), -- corrections are a new deed referencing the original, never an edit (§5)
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (id <> amends_deed_id),
    CHECK ((status = 'signed') = (signing_date IS NOT NULL)),
    CHECK (category <> 'ppat' OR land_object_id IS NOT NULL) -- a PPAT deed always references a land object
);

CREATE INDEX IF NOT EXISTS idx_legal_deeds_matter ON "LEGAL".deeds (matter_id, status);
CREATE INDEX IF NOT EXISTS idx_legal_deeds_land_object ON "LEGAL".deeds (land_object_id) WHERE land_object_id IS NOT NULL;

-- Deed immutability (§5): once a deed reaches 'signed', it becomes read-only at
-- the application layer. A DB-level trigger isn't used here (unlike
-- protocol_entries below) because a deed legitimately transitions through
-- earlier statuses first — the immutability is conditional on state, not
-- absolute from creation, so it's enforced in DeedService, not a blanket
-- BEFORE UPDATE trigger.

CREATE TABLE IF NOT EXISTS "LEGAL".deed_parties (
    id                  BIGSERIAL PRIMARY KEY,
    deed_id             BIGINT NOT NULL REFERENCES "LEGAL".deeds (id),
    partner_id          BIGINT NOT NULL REFERENCES "CRM".partners (id),
    role_type_id        BIGINT NOT NULL REFERENCES "LEGAL".party_role_types (id),
    -- Identity SNAPSHOT, not a live reference (§3J/§5) — deliberately duplicates
    -- data already on "CRM".partners, captured as of signing time so a later
    -- CRM edit never silently changes a signed deed's record of who appeared.
    identity_snapshot   JSONB NOT NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_legal_deed_parties_deed ON "LEGAL".deed_parties (deed_id);

CREATE TABLE IF NOT EXISTS "LEGAL".wills (
    id                  BIGSERIAL PRIMARY KEY,
    deed_id             BIGINT NOT NULL UNIQUE REFERENCES "LEGAL".deeds (id), -- 1:1 extension, category=notary/deed_type=wasiat
    testator_partner_id BIGINT NOT NULL REFERENCES "CRM".partners (id),
    dpw_reg_number      VARCHAR(50),
    dpw_registered_at   DATE,
    status              VARCHAR(15) NOT NULL DEFAULT 'drafted'
                            CHECK (status IN ('drafted', 'dpw_registered', 'active', 'opened', 'revoked'))
);

-- -----------------------------------------------------------------------------
-- §3I / §4. Land Due Diligence
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "LEGAL".due_diligence_checks (
    id                  BIGSERIAL PRIMARY KEY,
    land_object_id      BIGINT NOT NULL REFERENCES "LEGAL".land_objects (id),
    check_type          VARCHAR(20) NOT NULL CHECK (check_type IN ('sertifikat_validity', 'pbb_payment_status', 'blokir_sengketa', 'zona_nilai_tanah')),
    status              VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'clear', 'flagged')),
    checked_by          BIGINT REFERENCES users (id),
    checked_at          TIMESTAMPTZ,
    result_notes        TEXT,
    override_reason     TEXT,                              -- required (app-enforced) if a flagged check is overridden to allow signing (§3I)
    overridden_by       BIGINT REFERENCES users (id),
    overridden_at       TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_legal_due_diligence_checks_land_object ON "LEGAL".due_diligence_checks (land_object_id, status);

-- -----------------------------------------------------------------------------
-- §3K / §4. Tax Tracking Engine — the client's own PPh Final / BPHTB, never an
-- Accounting journal (§3K rules — this is deliberately unrelated to
-- ACCOUNTING.tax_codes/withholding_types).
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "LEGAL".deed_taxes (
    id                      BIGSERIAL PRIMARY KEY,
    deed_id                 BIGINT NOT NULL REFERENCES "LEGAL".deeds (id),
    tax_type                VARCHAR(10) NOT NULL CHECK (tax_type IN ('pph_final', 'bphtb')),
    taxpayer_partner_id     BIGINT NOT NULL REFERENCES "CRM".partners (id), -- seller for PPh, buyer for BPHTB
    base_amount             NUMERIC(18, 2) NOT NULL,      -- transaction value
    njop_amount             NUMERIC(18, 2),               -- captured alongside base_amount so the higher figure is transparent, not assumed
    rate                    NUMERIC(5, 2) NOT NULL,       -- tenant-configurable (2.5% PPh Final / 5% BPHTB by default)
    npoptkp_applied         NUMERIC(18, 2),                -- BPHTB only; a local-government figure, tenant-configurable
    computed_amount         NUMERIC(18, 2) NOT NULL,
    billing_code            VARCHAR(50),                   -- Kode Billing/Coretax (PPh) or SSPD reference (BPHTB)
    ntpn                    VARCHAR(50),                   -- Nomor Transaksi Penerimaan Negara — proof of payment
    status                  VARCHAR(20) NOT NULL DEFAULT 'pending'
                                CHECK (status IN ('pending', 'billing_code_issued', 'paid', 'validated')), -- only 'validated' satisfies the signing gate (§3K)
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_legal_deed_taxes_deed ON "LEGAL".deed_taxes (deed_id, status);

-- -----------------------------------------------------------------------------
-- §3L / §4. BPN Registration Tracking — a checklist/status log, not a live
-- integration (§5 explicit scope boundary).
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "LEGAL".bpn_submissions (
    id                      BIGSERIAL PRIMARY KEY,
    deed_id                 BIGINT NOT NULL REFERENCES "LEGAL".deeds (id),
    submission_type         VARCHAR(100) NOT NULL,        -- balik nama, APHT/HT-el registration, split/merge, ... (free text, no fixed lookup in §4)
    submitted_at            DATE,
    tracking_number         VARCHAR(100),
    pnbp_amount             NUMERIC(18, 2),                -- formula-assisted: (nilai_tanah / 1000) + 50000, editable (§3L)
    status                  VARCHAR(12) NOT NULL DEFAULT 'prepared'
                                CHECK (status IN ('prepared', 'submitted', 'in_process', 'completed', 'rejected')),
    completed_at            DATE,
    resubmission_of_id      BIGINT REFERENCES "LEGAL".bpn_submissions (id), -- rejection -> new row, never an edit-in-place (§3L)
    rejection_reason        TEXT,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (id <> resubmission_of_id),
    CHECK (status <> 'rejected' OR rejection_reason IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_legal_bpn_submissions_deed ON "LEGAL".bpn_submissions (deed_id, status);

-- -----------------------------------------------------------------------------
-- §3F / §4. Notary Protocol — the ledger-of-ledgers a Notaris is personally
-- liable for.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "LEGAL".protocol_books (
    id                  BIGSERIAL PRIMARY KEY,
    book_type           VARCHAR(20) NOT NULL
                            CHECK (book_type IN ('repertorium', 'legalisasi', 'waarmerking', 'protes', 'daftar_wasiat', 'lain_lain')),
    year                SMALLINT NOT NULL,
    volume              INTEGER NOT NULL DEFAULT 1,
    notary_user_id      BIGINT NOT NULL REFERENCES users (id),
    status              VARCHAR(15) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'closed', 'handed_over')),
    opened_at           DATE NOT NULL DEFAULT CURRENT_DATE,
    closed_at           DATE,
    handed_over_to      VARCHAR(150),                     -- successor notary or MPD — free text, not necessarily a platform user
    handed_over_at      DATE,
    UNIQUE (book_type, year, volume)
);

CREATE TABLE IF NOT EXISTS "LEGAL".protocol_entries (
    id                  BIGSERIAL PRIMARY KEY,
    book_id             BIGINT NOT NULL REFERENCES "LEGAL".protocol_books (id),
    deed_id             BIGINT REFERENCES "LEGAL".deeds (id), -- nullable: a protes/lain_lain entry may have no deed row behind it
    sequence_number     INTEGER NOT NULL,                 -- gap-free within (book_id, year) — assigned inside the signing transaction, row-locked on protocol_books (§5)
    entry_date          DATE NOT NULL DEFAULT CURRENT_DATE,
    notes               VARCHAR(255),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (book_id, sequence_number)
);

CREATE INDEX IF NOT EXISTS idx_legal_protocol_entries_deed ON "LEGAL".protocol_entries (deed_id) WHERE deed_id IS NOT NULL;

-- Protocol ledger integrity (§5/§3F): "No update or delete permitted... at the
-- app layer, same audit-integrity rule as DMS.access_logs" — given real teeth
-- here, matching INVENTORY_SPECS.sql's stock_ledger trigger, since the stakes
-- (professional-conduct liability) are at least as high.
CREATE OR REPLACE FUNCTION "LEGAL".prevent_protocol_entry_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'LEGAL.protocol_entries is append-only — the Notary Protocol ledger may never be updated or deleted (LEGAL_SPECS.md §3F/§5)';
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_legal_protocol_entries_immutable ON "LEGAL".protocol_entries;
CREATE TRIGGER trg_legal_protocol_entries_immutable
    BEFORE UPDATE OR DELETE ON "LEGAL".protocol_entries
    FOR EACH ROW EXECUTE FUNCTION "LEGAL".prevent_protocol_entry_mutation();

-- -----------------------------------------------------------------------------
-- §3M / §4. Field Operations (Mobile)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "LEGAL".field_visits (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE, -- referenced by the versioned mobile API (§5)
    matter_id           BIGINT REFERENCES "LEGAL".matters (id),
    land_object_id      BIGINT REFERENCES "LEGAL".land_objects (id),
    deed_id             BIGINT REFERENCES "LEGAL".deeds (id),
    visit_type_id       BIGINT NOT NULL REFERENCES "LEGAL".field_visit_types (id),
    assigned_to         BIGINT REFERENCES users (id),
    schedule_item_id    BIGINT REFERENCES "SCHEDULE".sched_items (id), -- real FK per §4's explicit "(FK SCHEDULE.sched_items)"
    status              VARCHAR(12) NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled', 'checked_in', 'completed')),
    checked_in_at       TIMESTAMPTZ,
    gps_lat             NUMERIC(9, 6),
    gps_lng             NUMERIC(9, 6),
    checklist_result    JSONB,
    notes               TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_legal_field_visits_assigned ON "LEGAL".field_visits (assigned_to, status);

COMMIT;

-- =============================================================================
-- EXAMPLE SEED DATA
-- One matter (a property purchase), a land parcel that clears due diligence, a
-- PPAT AJB deed whose two taxes both reach `validated` (satisfying the signing
-- gate) with a completed BPN submission, a standalone Notary deed, a Wasiat
-- with its DPW registration, protocol numbering across two book types
-- (repertorium + daftar_wasiat), and one completed field visit.
--
-- Partner references reuse the same client@example-corp.com /
-- vendor@office-rentals.example rows CRM_SPECS.sql seeds (vendor plays the
-- land seller here, client the buyer) — run CRM_SPECS.sql first if starting
-- from a bare tenant DB. Assumes "SCHEDULE".sched_items and Laravel's own
-- `users` table already exist too (see the stub setup this file's own
-- validation used).
--
-- Header-creating and dependent statements are kept SEPARATE top-level
-- statements throughout (see DMS_SPECS.sql's header note) to avoid
-- PostgreSQL's same-statement, same-snapshot limitation on data-modifying
-- CTEs.
-- =============================================================================

BEGIN;

INSERT INTO "LEGAL".deed_types (code, name, category, requires_tax, requires_bpn_registration, default_protocol_book_type) VALUES
    ('AKTA_PERJANJIAN', 'Akta Perjanjian', 'notary', FALSE, FALSE, 'repertorium'),
    ('WASIAT', 'Akta Wasiat', 'notary', FALSE, FALSE, 'daftar_wasiat'),
    ('LEGALISASI', 'Legalisasi', 'notary', FALSE, FALSE, 'legalisasi'),
    ('WAARMERKING', 'Waarmerking', 'notary', FALSE, FALSE, 'waarmerking'),
    ('AJB', 'Akta Jual Beli', 'ppat', TRUE, TRUE, 'repertorium');

INSERT INTO "LEGAL".party_role_types (code, name) VALUES
    ('penghadap', 'Penghadap'), ('pihak_pertama', 'Pihak Pertama'), ('pihak_kedua', 'Pihak Kedua'),
    ('saksi', 'Saksi'), ('kuasa', 'Kuasa'), ('ahli_waris', 'Ahli Waris');

INSERT INTO "LEGAL".field_visit_types (code, name, default_checklist) VALUES
    ('site_survey', 'Site Survey', '["Cek batas tanah", "Foto lokasi", "Cek akses jalan"]'),
    ('bpn_office_visit', 'BPN Office Visit', '["Ambil nomor antrian", "Verifikasi berkas", "Ambil tanda terima"]');

INSERT INTO "LEGAL".land_objects (certificate_type, certificate_number, nib, address, area_m2, njop_reference, current_owner_partner_id, status)
SELECT 'SHM', 'SHM-12.34.56.78.90001', '12345678901', 'Jl. Merdeka No. 10, Jakarta', 250, 950000000, partner.id, 'in_transaction'
FROM "CRM".partners partner WHERE partner.email = 'vendor@office-rentals.example';

INSERT INTO "LEGAL".matters (title, matter_type, partner_id, assigned_to, status)
SELECT 'Jual Beli Tanah - Jl. Merdeka', 'Property Purchase', partner.id, (SELECT id FROM users WHERE email = 'admin@nusaevo.com'), 'in_progress'
FROM "CRM".partners partner WHERE partner.email = 'client@example-corp.com';

-- --- Due diligence on the land parcel, all clear -----------------------------

INSERT INTO "LEGAL".due_diligence_checks (land_object_id, check_type, status, checked_by, checked_at, result_notes)
SELECT lo.id, v.check_type, 'clear', (SELECT id FROM users WHERE email = 'staff@nusaevo.com'), now(), v.notes
FROM "LEGAL".land_objects lo, (VALUES
    ('sertifikat_validity', 'SKPT valid, no encumbrance flag.'),
    ('pbb_payment_status', 'PBB paid through current year.'),
    ('blokir_sengketa', 'No blokir or sengketa found.'),
    ('zona_nilai_tanah', 'ZNT reference matches NJOP on file.')
) AS v(check_type, notes)
WHERE lo.certificate_number = 'SHM-12.34.56.78.90001';

-- --- Deed 1: standalone Notary deed (Akta Perjanjian) ------------------------

INSERT INTO "LEGAL".deeds (deed_type_id, category, status, signing_date, minuta_reference, summary)
SELECT id, 'notary', 'signed', '2026-08-10', 'MIN-2026-001', 'Perjanjian kerja sama operasional.'
FROM "LEGAL".deed_types WHERE code = 'AKTA_PERJANJIAN';

INSERT INTO "LEGAL".deed_parties (deed_id, partner_id, role_type_id, identity_snapshot)
SELECT d.id, partner.id, rt.id,
       jsonb_build_object('name', partner.name, 'nik', '3171000000000001', 'address', 'Jl. Sudirman No. 1, Jakarta')
FROM "LEGAL".deeds d, "CRM".partners partner, "LEGAL".party_role_types rt
WHERE d.minuta_reference = 'MIN-2026-001' AND partner.email = 'client@example-corp.com' AND rt.code = 'pihak_pertama'
UNION ALL
SELECT d.id, partner.id, rt.id,
       jsonb_build_object('name', partner.name, 'nik', '3171000000000002', 'address', 'Jl. Rasuna Said No. 2, Jakarta')
FROM "LEGAL".deeds d, "CRM".partners partner, "LEGAL".party_role_types rt
WHERE d.minuta_reference = 'MIN-2026-001' AND partner.email = 'vendor@office-rentals.example' AND rt.code = 'saksi';

-- --- Deed 2: PPAT AJB deed, tied to the matter + land object -----------------

INSERT INTO "LEGAL".deeds (matter_id, deed_type_id, category, land_object_id, status, signing_date, minuta_reference, summary)
SELECT m.id, dt.id, 'ppat', lo.id, 'signed', '2026-08-20', 'MIN-2026-002', 'Jual beli SHM-12.34.56.78.90001.'
FROM "LEGAL".matters m, "LEGAL".deed_types dt, "LEGAL".land_objects lo
WHERE m.title = 'Jual Beli Tanah - Jl. Merdeka' AND dt.code = 'AJB' AND lo.certificate_number = 'SHM-12.34.56.78.90001';

INSERT INTO "LEGAL".deed_parties (deed_id, partner_id, role_type_id, identity_snapshot)
SELECT d.id, partner.id, rt.id,
       jsonb_build_object('name', partner.name, 'nik', '3171000000000002', 'address', 'Jl. Rasuna Said No. 2, Jakarta')
FROM "LEGAL".deeds d, "CRM".partners partner, "LEGAL".party_role_types rt
WHERE d.minuta_reference = 'MIN-2026-002' AND partner.email = 'vendor@office-rentals.example' AND rt.code = 'pihak_pertama' -- seller
UNION ALL
SELECT d.id, partner.id, rt.id,
       jsonb_build_object('name', partner.name, 'nik', '3171000000000001', 'address', 'Jl. Sudirman No. 1, Jakarta')
FROM "LEGAL".deeds d, "CRM".partners partner, "LEGAL".party_role_types rt
WHERE d.minuta_reference = 'MIN-2026-002' AND partner.email = 'client@example-corp.com' AND rt.code = 'pihak_kedua'; -- buyer

-- Both mandatory taxes, already validated (satisfies the §3G signing gate)
INSERT INTO "LEGAL".deed_taxes (deed_id, tax_type, taxpayer_partner_id, base_amount, njop_amount, rate, computed_amount, billing_code, ntpn, status)
SELECT d.id, 'pph_final', partner.id, 1000000000, 950000000, 2.5, 25000000, 'BILLING-PPH-0001', 'NTPN-0001', 'validated'
FROM "LEGAL".deeds d, "CRM".partners partner
WHERE d.minuta_reference = 'MIN-2026-002' AND partner.email = 'vendor@office-rentals.example';

INSERT INTO "LEGAL".deed_taxes (deed_id, tax_type, taxpayer_partner_id, base_amount, njop_amount, rate, npoptkp_applied, computed_amount, billing_code, ntpn, status)
SELECT d.id, 'bphtb', partner.id, 1000000000, 950000000, 5.0, 80000000, 46000000, 'SSPD-BPHTB-0001', 'NTPN-0002', 'validated'
FROM "LEGAL".deeds d, "CRM".partners partner
WHERE d.minuta_reference = 'MIN-2026-002' AND partner.email = 'client@example-corp.com';

-- Post-signing BPN registration, already completed
INSERT INTO "LEGAL".bpn_submissions (deed_id, submission_type, submitted_at, tracking_number, pnbp_amount, status, completed_at)
SELECT id, 'Balik Nama', '2026-08-21', 'BPN-TRK-0001', (1000000000 / 1000) + 50000, 'completed', '2026-09-05'
FROM "LEGAL".deeds WHERE minuta_reference = 'MIN-2026-002';

-- --- Deed 3: Wasiat, with its DPW registration -------------------------------

INSERT INTO "LEGAL".deeds (deed_type_id, category, status, signing_date, minuta_reference, summary)
SELECT id, 'notary', 'signed', '2026-08-12', 'MIN-2026-003', 'Wasiat untuk ahli waris.'
FROM "LEGAL".deed_types WHERE code = 'WASIAT';

INSERT INTO "LEGAL".wills (deed_id, testator_partner_id, dpw_reg_number, dpw_registered_at, status)
SELECT d.id, partner.id, 'DPW-2026-0001', '2026-08-15', 'dpw_registered'
FROM "LEGAL".deeds d, "CRM".partners partner
WHERE d.minuta_reference = 'MIN-2026-003' AND partner.email = 'client@example-corp.com';

-- --- Protocol numbering across two book types --------------------------------

INSERT INTO "LEGAL".protocol_books (book_type, year, volume, notary_user_id) VALUES
    ('repertorium', 2026, 1, (SELECT id FROM users WHERE email = 'admin@nusaevo.com')),
    ('daftar_wasiat', 2026, 1, (SELECT id FROM users WHERE email = 'admin@nusaevo.com'));

INSERT INTO "LEGAL".protocol_entries (book_id, deed_id, sequence_number, entry_date)
SELECT b.id, d.id, 1, d.signing_date
FROM "LEGAL".protocol_books b, "LEGAL".deeds d
WHERE b.book_type = 'repertorium' AND b.year = 2026 AND d.minuta_reference = 'MIN-2026-001'
UNION ALL
SELECT b.id, d.id, 2, d.signing_date
FROM "LEGAL".protocol_books b, "LEGAL".deeds d
WHERE b.book_type = 'repertorium' AND b.year = 2026 AND d.minuta_reference = 'MIN-2026-002'
UNION ALL
SELECT b.id, d.id, 1, d.signing_date
FROM "LEGAL".protocol_books b, "LEGAL".deeds d
WHERE b.book_type = 'daftar_wasiat' AND b.year = 2026 AND d.minuta_reference = 'MIN-2026-003';

-- --- Field visit: site survey on the land parcel, completed ------------------

INSERT INTO "SCHEDULE".sched_items (title) VALUES ('Site survey - Jl. Merdeka');

INSERT INTO "LEGAL".field_visits (matter_id, land_object_id, visit_type_id, assigned_to, schedule_item_id, status, checked_in_at, gps_lat, gps_lng, checklist_result, notes)
SELECT m.id, lo.id, vt.id, (SELECT id FROM users WHERE email = 'staff@nusaevo.com'), si.id,
       'completed', '2026-08-03 09:15:00+07', -6.208763, 106.845599,
       '{"Cek batas tanah": true, "Foto lokasi": true, "Cek akses jalan": true}',
       'No dispute found on-site; boundary markers intact.'
FROM "LEGAL".matters m, "LEGAL".land_objects lo, "LEGAL".field_visit_types vt, "SCHEDULE".sched_items si
WHERE m.title = 'Jual Beli Tanah - Jl. Merdeka' AND lo.certificate_number = 'SHM-12.34.56.78.90001'
  AND vt.code = 'site_survey' AND si.title = 'Site survey - Jl. Merdeka';

COMMIT;
