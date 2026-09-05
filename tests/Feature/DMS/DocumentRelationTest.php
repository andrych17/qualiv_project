<?php

namespace Tests\Feature\DMS;

use App\Modules\DMS\Models\Document;
use App\Modules\DMS\Models\DocumentRelation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpDMS;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3H Object Relation Engine — link/unlink documents, self-relation and duplicate guards, inverse-label read side. */
class DocumentRelationTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpDMS;
    use SetsUpTenant;

    public function test_admin_can_add_and_remove_a_relation(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        [$sourceId, $targetId] = [null, null];
        $tenant->run(function () use (&$sourceId, &$targetId) {
            $sourceId = $this->makeDocument(['title' => 'Amendment'])->id;
            $targetId = $this->makeDocument(['title' => 'Original Contract'])->id;
        });

        $this->post("/dms/documents/{$sourceId}/relations", [
            'target_document_id' => $targetId,
            'relation_type' => DocumentRelation::TYPE_AMENDMENT_OF,
        ])->assertRedirect();

        $relationId = null;
        $tenant->run(function () use (&$relationId, $sourceId, $targetId) {
            $relation = DocumentRelation::query()->where('source_document_id', $sourceId)->first();
            $this->assertSame($targetId, $relation->target_document_id);
            $this->assertSame(DocumentRelation::TYPE_AMENDMENT_OF, $relation->relation_type);
            $relationId = $relation->id;
        });

        $this->delete("/dms/documents/{$sourceId}/relations/{$relationId}")->assertRedirect();
        $tenant->run(function () use ($relationId) {
            $this->assertNull(DocumentRelation::query()->find($relationId));
        });
    }

    public function test_relation_rejects_self_reference_invalid_target_and_duplicate(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        [$sourceId, $targetId] = [null, null];
        $tenant->run(function () use (&$sourceId, &$targetId) {
            $sourceId = $this->makeDocument()->id;
            $targetId = $this->makeDocument()->id;
        });

        $this->post("/dms/documents/{$sourceId}/relations", [
            'target_document_id' => $sourceId,
            'relation_type' => DocumentRelation::TYPE_RELATED_TO,
        ])->assertSessionHasErrors(['target_document_id']);

        $this->post("/dms/documents/{$sourceId}/relations", [
            'target_document_id' => 999999,
            'relation_type' => DocumentRelation::TYPE_RELATED_TO,
        ])->assertSessionHasErrors(['target_document_id']);

        $this->post("/dms/documents/{$sourceId}/relations", [
            'target_document_id' => $targetId,
            'relation_type' => DocumentRelation::TYPE_SUPERSEDES,
        ])->assertRedirect();

        $this->post("/dms/documents/{$sourceId}/relations", [
            'target_document_id' => $targetId,
            'relation_type' => DocumentRelation::TYPE_SUPERSEDES,
        ])->assertSessionHasErrors(['target_document_id']);
    }

    public function test_relation_rejects_invalid_relation_type(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $sourceId = null;
        $tenant->run(function () use (&$sourceId) {
            $sourceId = $this->makeDocument()->id;
        });

        $this->post("/dms/documents/{$sourceId}/relations", [
            'target_document_id' => $sourceId,
            'relation_type' => 'not_a_real_type',
        ])->assertSessionHasErrors(['relation_type']);
    }

    public function test_destroy_relation_rejects_a_relation_not_belonging_to_the_document(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        [$sourceId, $targetId, $unrelatedId, $relationId] = [null, null, null, null];
        $tenant->run(function () use (&$sourceId, &$targetId, &$unrelatedId, &$relationId) {
            $sourceId = $this->makeDocument()->id;
            $targetId = $this->makeDocument()->id;
            $unrelatedId = $this->makeDocument()->id;
            $relationId = DocumentRelation::query()->create([
                'source_document_id' => $sourceId,
                'target_document_id' => $targetId,
                'relation_type' => DocumentRelation::TYPE_RELATED_TO,
            ])->id;
        });

        $this->delete("/dms/documents/{$unrelatedId}/relations/{$relationId}")->assertNotFound();

        // A document on the target side of the relation can still remove it.
        $this->delete("/dms/documents/{$targetId}/relations/{$relationId}")->assertRedirect();
    }

    public function test_show_merges_relations_from_both_directions_with_inverse_labels(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        [$mainId, $amendmentId, $originalId] = [null, null, null];
        $tenant->run(function () use (&$mainId, &$amendmentId, &$originalId) {
            $main = $this->makeDocument(['title' => 'Main Doc']);
            $mainId = $main->id;
            $amendmentId = $this->makeDocument(['title' => 'Its Amendment'])->id;
            $originalId = $this->makeDocument(['title' => 'Its Original'])->id;

            // main -> amendment: main "supersedes" amendment (main is the source).
            DocumentRelation::query()->create([
                'source_document_id' => $mainId,
                'target_document_id' => $amendmentId,
                'relation_type' => DocumentRelation::TYPE_SUPERSEDES,
            ]);
            // original -> main: original "amendment_of" main (main is the target this time).
            DocumentRelation::query()->create([
                'source_document_id' => $originalId,
                'target_document_id' => $mainId,
                'relation_type' => DocumentRelation::TYPE_AMENDMENT_OF,
            ]);
        });

        $response = $this->get("/dms/documents/{$mainId}")->assertOk();
        $relations = $response->json('relations');

        $this->assertCount(2, $relations);
        $forward = collect($relations)->firstWhere('document_title', 'Its Amendment');
        $this->assertSame(DocumentRelation::TYPE_SUPERSEDES, $forward['relation_type']);

        $backward = collect($relations)->firstWhere('document_title', 'Its Original');
        $this->assertSame('amended_by', $backward['relation_type']);
    }

    public function test_show_inverts_supersedes_and_attachment_of_labels(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        [$mainId, $supersededById, $attachedById] = [null, null, null];
        $tenant->run(function () use (&$mainId, &$supersededById, &$attachedById) {
            $mainId = $this->makeDocument(['title' => 'Main Doc'])->id;
            $supersededById = $this->makeDocument(['title' => 'Newer Version'])->id;
            $attachedById = $this->makeDocument(['title' => 'Parent Record'])->id;

            // "Newer Version" supersedes "Main Doc" -> from Main's drawer this reads "superseded_by".
            DocumentRelation::query()->create([
                'source_document_id' => $supersededById,
                'target_document_id' => $mainId,
                'relation_type' => DocumentRelation::TYPE_SUPERSEDES,
            ]);
            // "Parent Record" has "Main Doc" as an attachment -> from Main's drawer this reads "has_attachment".
            DocumentRelation::query()->create([
                'source_document_id' => $attachedById,
                'target_document_id' => $mainId,
                'relation_type' => DocumentRelation::TYPE_ATTACHMENT_OF,
            ]);
        });

        $relations = $this->get("/dms/documents/{$mainId}")->assertOk()->json('relations');

        $this->assertSame('superseded_by', collect($relations)->firstWhere('document_title', 'Newer Version')['relation_type']);
        $this->assertSame('has_attachment', collect($relations)->firstWhere('document_title', 'Parent Record')['relation_type']);
    }
}
