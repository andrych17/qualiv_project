<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §3E Search Engine (MVP: keyword). `search_vector` was deliberately deferred out of the
 * original DMS migration (§3A's own scope note: "extracted_text yes, search_vector no... the
 * trigger machinery is §3E") — this is that additive follow-up, ported from DMS_SPECS.sql §"Full
 * text search" with no changes: title/current-version-filename weighted 'A', description/tags
 * 'B', extracted_text (still unpopulated until OCR ships) 'C'.
 *
 * A trigger, not a generated column, because Postgres GENERATED ALWAYS AS columns can't read
 * another table (current version's filename lives in document_versions, tags in document_tags
 * via a join table) — same reasoning DMS_SPECS.sql documents for its own reference schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE "DMS".documents ADD COLUMN search_vector tsvector');
        DB::statement('CREATE INDEX idx_dms_documents_search ON "DMS".documents USING GIN (search_vector)');

        DB::unprepared(<<<'SQL'
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
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION "DMS".documents_search_vector_trigger() RETURNS trigger AS $$
            BEGIN
                PERFORM "DMS".refresh_document_search_vector(NEW.id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // Fires only on title/description/extracted_text/current_version_id changes — never on
        // a search_vector-only UPDATE — so the AFTER-trigger UPDATE above cannot recurse.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_dms_documents_search_vector
                AFTER INSERT OR UPDATE OF title, description, extracted_text, current_version_id ON "DMS".documents
                FOR EACH ROW EXECUTE FUNCTION "DMS".documents_search_vector_trigger();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION "DMS".document_tags_search_vector_trigger() RETURNS trigger AS $$
            BEGIN
                PERFORM "DMS".refresh_document_search_vector(COALESCE(NEW.document_id, OLD.document_id));
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_dms_document_tags_search_vector
                AFTER INSERT OR DELETE ON "DMS".document_tags
                FOR EACH ROW EXECUTE FUNCTION "DMS".document_tags_search_vector_trigger();
        SQL);

        // Backfill — every document that already existed before this migration ran gets indexed
        // immediately rather than waiting for its next title/description/version/tag change.
        DB::statement('SELECT "DMS".refresh_document_search_vector(id) FROM "DMS".documents');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_dms_document_tags_search_vector ON "DMS".document_tags');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_dms_documents_search_vector ON "DMS".documents');
        DB::unprepared('DROP FUNCTION IF EXISTS "DMS".document_tags_search_vector_trigger()');
        DB::unprepared('DROP FUNCTION IF EXISTS "DMS".documents_search_vector_trigger()');
        DB::unprepared('DROP FUNCTION IF EXISTS "DMS".refresh_document_search_vector(BIGINT)');
        DB::statement('DROP INDEX IF EXISTS "DMS".idx_dms_documents_search');
        DB::statement('ALTER TABLE "DMS".documents DROP COLUMN IF EXISTS search_vector');
    }
};
