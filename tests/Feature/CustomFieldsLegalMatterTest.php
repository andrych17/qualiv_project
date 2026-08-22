<?php

namespace Tests\Feature;

use App\Modules\CustomFields\Models\FieldDef;
use App\Modules\CustomFields\Models\FieldValue;
use App\Modules\Legal\Models\Matter;
use App\Modules\SysConfig\Models\ConfigConst;
use App\Modules\SysConfig\Models\ConfigSnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class CustomFieldsLegalMatterTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_custom_fields_and_urgent_logic_on_create(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            ConfigConst::query()->updateOrCreate(
                ['const_group' => 'LEGAL', 'group_code' => 'MATTER_PREFIX'],
                ['seq' => 1, 'str1' => 'A', 'note1' => 'prefix'],
            );
            ConfigConst::query()->updateOrCreate(
                ['const_group' => 'LEGAL', 'group_code' => 'URGENT_SETS_PENDING'],
                ['seq' => 2, 'num1' => 1, 'note1' => 'on'],
            );

            ConfigSnum::query()->updateOrCreate(
                ['code' => 'LEGAL_MATTER_LASTID'],
                [
                    'last_cnt' => 0,
                    'wrap_low' => 1,
                    'wrap_high' => 999999,
                    'step_cnt' => 1,
                    'descr' => 'Legal matter running number',
                    'status_code' => 'A',
                ],
            );

            FieldDef::query()->create([
                'entity_type' => 'legal_matter',
                'code' => 'court_register',
                'label' => 'Court register',
                'field_type' => 'text',
                'is_required' => true,
                'seq' => 1,
                'status' => 'active',
            ]);
            FieldDef::query()->create([
                'entity_type' => 'legal_matter',
                'code' => 'priority',
                'label' => 'Priority',
                'field_type' => 'select',
                'options' => [
                    ['label' => 'Normal', 'value' => 'normal'],
                    ['label' => 'Urgent', 'value' => 'urgent'],
                ],
                'is_required' => true,
                'seq' => 2,
                'status' => 'active',
            ]);
        });

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->post('/legal/matters', [
            'title' => 'Urgent Matter',
            'status' => 'open',
            'notes' => null,
            'custom_fields' => [
                'court_register' => 'PN.001',
                'priority' => 'urgent',
            ],
        ])->assertRedirect(route('legal.matters.index'));

        $tenant->run(function () {
            $matter = Matter::query()->where('title', 'Urgent Matter')->first();
            $this->assertNotNull($matter);
            $this->assertSame('on_hold', $matter->status);
            $this->assertStringStartsWith('A-', $matter->code);

            $values = FieldValue::query()
                ->where('entity_type', 'legal_matter')
                ->where('entity_id', $matter->id)
                ->pluck('value', 'field_def_id');

            $defs = FieldDef::query()->where('entity_type', 'legal_matter')->pluck('id', 'code');
            $this->assertSame('PN.001', $values[$defs['court_register']]);
            $this->assertSame('urgent', $values[$defs['priority']]);
        });
    }

    public function test_required_custom_field_rejects_create(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            FieldDef::query()->create([
                'entity_type' => 'legal_matter',
                'code' => 'court_register',
                'label' => 'Court register',
                'field_type' => 'text',
                'is_required' => true,
                'seq' => 1,
                'status' => 'active',
            ]);
        });

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->from('/legal/matters/create')
            ->post('/legal/matters', [
                'code' => 'MATTER-X',
                'title' => 'Missing CF',
                'status' => 'open',
                'custom_fields' => [],
            ])
            ->assertRedirect('/legal/matters/create')
            ->assertSessionHasErrors('custom_fields.court_register');
    }
}
