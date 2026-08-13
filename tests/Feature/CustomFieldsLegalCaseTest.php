<?php

namespace Tests\Feature;

use App\Modules\Config\Models\ConfigConst;
use App\Modules\Config\Models\ConfigSnum;
use App\Modules\CustomFields\Models\FieldDef;
use App\Modules\CustomFields\Models\FieldValue;
use App\Modules\Legal\Models\LegalCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class CustomFieldsLegalCaseTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_custom_fields_and_urgent_logic_on_create(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            ConfigConst::query()->updateOrCreate(
                ['const_group' => 'LEGAL', 'group_code' => 'CASE_PREFIX'],
                ['seq' => 1, 'str1' => 'A', 'note1' => 'prefix'],
            );
            ConfigConst::query()->updateOrCreate(
                ['const_group' => 'LEGAL', 'group_code' => 'URGENT_SETS_PENDING'],
                ['seq' => 2, 'num1' => 1, 'note1' => 'on'],
            );

            ConfigSnum::query()->updateOrCreate(
                ['code' => 'LEGAL_CASE_LASTID'],
                [
                    'last_cnt' => 0,
                    'wrap_low' => 1,
                    'wrap_high' => 999999,
                    'step_cnt' => 1,
                    'descr' => 'Legal case running number',
                    'status_code' => 'A',
                ],
            );

            FieldDef::query()->create([
                'entity_type' => 'legal_case',
                'code' => 'court_register',
                'label' => 'Court register',
                'field_type' => 'text',
                'is_required' => true,
                'seq' => 1,
                'status' => 'active',
            ]);
            FieldDef::query()->create([
                'entity_type' => 'legal_case',
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

        $this->post('/legal/cases', [
            'title' => 'Urgent Matter',
            'status' => 'open',
            'notes' => null,
            'custom_fields' => [
                'court_register' => 'PN.001',
                'priority' => 'urgent',
            ],
        ])->assertRedirect(route('legal.cases.index'));

        $tenant->run(function () {
            $case = LegalCase::query()->where('title', 'Urgent Matter')->first();
            $this->assertNotNull($case);
            $this->assertSame('pending', $case->status);
            $this->assertStringStartsWith('A-', $case->code);

            $values = FieldValue::query()
                ->where('entity_type', 'legal_case')
                ->where('entity_id', $case->id)
                ->pluck('value', 'field_def_id');

            $defs = FieldDef::query()->where('entity_type', 'legal_case')->pluck('id', 'code');
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
                'entity_type' => 'legal_case',
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

        $this->from('/legal/cases/create')
            ->post('/legal/cases', [
                'code' => 'CASE-X',
                'title' => 'Missing CF',
                'status' => 'open',
                'custom_fields' => [],
            ])
            ->assertRedirect('/legal/cases/create')
            ->assertSessionHasErrors('custom_fields.court_register');
    }
}
