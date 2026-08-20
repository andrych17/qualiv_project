<?php

namespace Tests\Feature;

use App\Modules\CustomFields\Models\FieldDef;
use App\Modules\CustomFields\Models\FieldDefAuditLog;
use App\Modules\CustomFields\Models\FieldValue;
use App\Modules\CustomFields\Services\CustomFieldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class CustomFieldDefCrudTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_crud_field_defs_and_audit(): void
    {
        $tenant = $this->provisionTenant();

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/config/fields')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Config/Fields/Index'));

        $this->post('/config/fields', [
            'entity_type' => 'legal_case',
            'module_code' => 'LEGAL',
            'code' => 'court_register',
            'label' => 'Court Register',
            'field_type' => 'text',
            'is_required' => true,
            'seq' => 10,
        ])->assertRedirect(route('config.fields.index'));

        $id = null;
        $tenant->run(function () use (&$id) {
            $def = FieldDef::query()->where('code', 'court_register')->first();
            $this->assertNotNull($def);
            $this->assertSame('LEGAL', $def->module_code);
            $id = $def->id;
            $this->assertTrue(
                FieldDefAuditLog::query()->where('field_def_id', $id)->where('action', 'created')->exists()
            );
        });

        $this->put('/config/fields/'.$id, [
            'entity_type' => 'legal_case',
            'module_code' => 'LEGAL',
            'code' => 'court_register',
            'label' => 'Court Register No.',
            'field_type' => 'text',
            'is_required' => true,
            'seq' => 10,
            'status' => 'active',
        ])->assertRedirect(route('config.fields.index'));

        $this->delete('/config/fields/'.$id)
            ->assertRedirect(route('config.fields.index'));

        $tenant->run(function () {
            $def = FieldDef::query()->where('code', 'court_register')->first();
            $this->assertSame('inactive', $def->status);
            $this->assertTrue(
                FieldDefAuditLog::query()->where('field_def_id', $def->id)->where('action', 'deactivated')->exists()
            );
        });
    }

    public function test_sync_deletes_emptied_values(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $def = FieldDef::query()->create([
                'entity_type' => 'legal_case',
                'code' => 'notes_cf',
                'label' => 'Notes',
                'field_type' => 'text',
                'is_required' => false,
                'seq' => 1,
                'status' => 'active',
            ]);

            $svc = app(CustomFieldService::class);
            $svc->sync('legal_case', 99, ['notes_cf' => 'hello']);
            $this->assertSame('hello', FieldValue::query()->where('field_def_id', $def->id)->value('value'));

            $svc->sync('legal_case', 99, ['notes_cf' => null]);
            $this->assertFalse(FieldValue::query()->where('field_def_id', $def->id)->exists());
        });
    }
}
