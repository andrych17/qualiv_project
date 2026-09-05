<?php

namespace Tests\Feature\DMS;

use App\Models\User;
use App\Modules\DMS\Models\AccessLog;
use App\Modules\DMS\Models\Document;
use App\Modules\DMS\Models\Folder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpDMS;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** Model relation/accessor coverage for paths no controller directly exercises: Status Rail edge cases, folder relations, isAccessibleTo() branches. */
class FacadeAndModelTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpDMS;
    use SetsUpTenant;

    public function test_rail_attribute_covers_every_branch(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $tenant->run(function () {
            $onHold = $this->makeDocument(['legal_hold' => true]);
            $this->assertSame('danger', $onHold->rail);

            $expired = $this->makeDocument(['status' => Document::STATUS_EXPIRED]);
            $this->assertSame('danger', $expired->rail);

            $purged = $this->makeDocument(['status' => Document::STATUS_PURGED]);
            $this->assertSame('danger', $purged->rail);

            $archived = $this->makeDocument(['status' => Document::STATUS_ARCHIVED]);
            $this->assertSame('neutral', $archived->rail);

            $expiringSoon = $this->makeDocument(['expiry_date' => now()->addDays(5)->toDateString()]);
            $this->assertSame('warning', $expiringSoon->rail);

            $active = $this->makeDocument(['status' => Document::STATUS_ACTIVE]);
            $this->assertSame('success', $active->rail);

            $draft = $this->makeDocument(['status' => Document::STATUS_DRAFT]);
            $this->assertSame('neutral', $draft->rail);
        });
    }

    public function test_is_accessible_to_covers_null_folder_missing_folder_and_flags(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $tenant->run(function () {
            $adminId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $otherId = User::factory()->create()->id;

            $noFolder = $this->makeDocument();
            $this->assertTrue($noFolder->isAccessibleTo($otherId));

            $tenantFolder = $this->makeFolder('Tenant Wide', ['access_flag' => Folder::ACCESS_TENANT]);
            $tenantDoc = $this->makeDocument(['folder_id' => $tenantFolder->id]);
            $this->assertTrue($tenantDoc->isAccessibleTo($otherId));

            $privateFolder = $this->makeFolder('Private', ['access_flag' => Folder::ACCESS_PRIVATE, 'created_by' => $adminId]);
            $privateDoc = $this->makeDocument(['folder_id' => $privateFolder->id]);
            $this->assertTrue($privateDoc->isAccessibleTo($adminId));
            $this->assertFalse($privateDoc->isAccessibleTo($otherId));

            // A document referencing a folder_id that no longer resolves (edge case, not
            // reachable via the FK in normal use) still defaults to accessible.
            $orphan = $this->makeDocument();
            $orphan->folder_id = 999999;
            $this->assertTrue($orphan->isAccessibleTo($otherId));
        });
    }

    public function test_folder_relations(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $tenant->run(function () {
            $adminId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $docType = $this->makeDocType();
            $policy = $this->makeRetentionPolicy($docType);
            $parent = $this->makeFolder('Parent');
            $child = $this->makeFolder('Child', [
                'parent_folder_id' => $parent->id,
                'default_doc_type_id' => $docType->id,
                'default_retention_policy_id' => $policy->id,
                'created_by' => $adminId,
            ]);

            $this->assertSame($parent->id, $child->parent->id);
            $this->assertTrue($parent->children->contains('id', $child->id));
            $this->assertSame($docType->id, $child->defaultDocType->id);
            $this->assertSame($policy->id, $child->defaultRetentionPolicy->id);
            $this->assertSame($adminId, $child->createdBy->id);

            $document = $this->makeDocument(['folder_id' => $child->id]);
            $this->assertTrue($child->documents->contains('id', $document->id));
        });
    }

    public function test_retention_policy_and_doc_type_relations(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $tenant->run(function () {
            $docType = $this->makeDocType();
            $policy = $this->makeRetentionPolicy($docType);

            $this->assertSame($docType->id, $policy->docType->id);
        });
    }

    public function test_tag_documents_relation(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $tenant->run(function () {
            $tag = $this->makeTag('confidential');
            $document = $this->makeDocument();
            $document->tags()->sync([$tag->id]);

            $this->assertTrue($tag->fresh()->documents->contains('id', $document->id));
        });
    }

    public function test_access_log_version_relation(): void
    {
        $tenant = $this->loginAsDmsAdmin();

        $tenant->run(function () {
            $document = $this->makeDocument();
            $log = AccessLog::record([
                'document_id' => $document->id,
                'document_version_id' => $document->current_version_id,
                'action' => AccessLog::ACTION_VIEW,
            ]);

            $this->assertSame($document->current_version_id, $log->version->id);
        });
    }
}
