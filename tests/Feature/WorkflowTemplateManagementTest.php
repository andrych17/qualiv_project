<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigGroupUser;
use App\Modules\WNE\Events\InAppNotificationCreated;
use App\Modules\WNE\Models\MsgCategory;
use App\Modules\WNE\Models\MsgChannelConfig;
use App\Modules\WNE\Models\MsgTemplate;
use App\Modules\WNE\Models\WrkflowDefinition;
use App\Modules\WNE\Models\WrkflowSlaRule;
use App\Modules\WNE\Models\WrkflowStep;
use App\Modules\WNE\Models\WrkflowVersion;
use App\Modules\WNE\Services\MessagingService;
use App\Modules\WNE\Services\MsgTemplateService;
use App\Modules\WNE\Services\SlaEscalationService;
use App\Modules\WNE\Services\TemplateRenderingService;
use App\Modules\WNE\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * WNE_SPECS.md §3L — Dynamic Template Management. Covers the renderer itself, the
 * undocumented-variable activation gate, category auto-creation, per-delivery template
 * resolution vs. the §3I literal fallback, coverage warnings, and the end-to-end path
 * from §3F's SLA breach through a real active template.
 */
class WorkflowTemplateManagementTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    private function adminId(): int
    {
        return User::query()->where('email', 'admin@nusaevo.com')->value('id');
    }

    public function test_renderer_substitutes_present_variables_and_leaves_missing_ones_visible(): void
    {
        $renderer = new TemplateRenderingService;

        $rendered = $renderer->render('Hi {{name}}, due {{due_date}}.', ['name' => 'Jane']);

        $this->assertSame('Hi Jane, due {{due_date}}.', $rendered);
    }

    public function test_extract_variables_finds_every_distinct_token(): void
    {
        $renderer = new TemplateRenderingService;

        $this->assertSame(['name', 'due_date'], $renderer->extractVariables('Hi {{name}}, due {{due_date}}. Thanks, {{name}}.'));
    }

    public function test_activation_blocks_on_undocumented_variable_and_succeeds_once_documented(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $service = app(MsgTemplateService::class);
            $template = $service->create([
                'category_code' => 'demo.template_test',
                'channel' => MsgChannelConfig::CHANNEL_IN_APP,
                'body' => 'Hi {{name}}!',
                'variables' => [],
            ]);

            try {
                $service->activate($template);
                $this->fail('Expected a ValidationException for the undocumented {{name}} variable.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('name', $e->validator->errors()->first('variables'));
            }

            $this->assertFalse($template->fresh()->is_active);

            $service->update($template, ['body' => 'Hi {{name}}!', 'variables' => ['name']]);
            $service->activate($template);

            $this->assertTrue($template->fresh()->is_active);
        });
    }

    public function test_creating_a_template_auto_creates_its_category(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $this->assertNull(MsgCategory::query()->where('code', 'demo.brand_new_category')->first());

            app(MsgTemplateService::class)->create([
                'category_code' => 'demo.brand_new_category',
                'channel' => MsgChannelConfig::CHANNEL_IN_APP,
                'body' => 'Hello.',
                'variables' => [],
            ]);

            $this->assertNotNull(MsgCategory::query()->where('code', 'demo.brand_new_category')->first());
        });
    }

    public function test_send_uses_the_active_template_for_the_matching_channel_and_falls_back_without_one(): void
    {
        Event::fake([InAppNotificationCreated::class]);
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();

            // No template yet — falls back to the literal subject/body passed to notify() (§3I behavior, unchanged).
            app(MessagingService::class)->notify(
                category: 'demo.template_fallback',
                recipient: ['type' => 'user', 'user_id' => $admin],
                subject: 'Literal subject',
                body: 'Literal body',
            );

            Event::assertDispatched(InAppNotificationCreated::class, fn ($e) => $e->subject === 'Literal subject' && $e->body === 'Literal body');

            // Now an active in_app template exists for this category — it must override the literal text.
            $service = app(MsgTemplateService::class);
            $template = $service->create([
                'category_code' => 'demo.template_fallback',
                'channel' => MsgChannelConfig::CHANNEL_IN_APP,
                'subject' => 'Templated: {{who}}',
                'body' => 'Rendered body for {{who}}.',
                'variables' => ['who'],
            ]);
            $service->activate($template);

            app(MessagingService::class)->notify(
                category: 'demo.template_fallback',
                recipient: ['type' => 'user', 'user_id' => $admin],
                subject: 'Literal subject',
                body: 'Literal body',
                data: ['who' => 'Bob'],
            );

            Event::assertDispatched(InAppNotificationCreated::class, fn ($e) => $e->subject === 'Templated: Bob' && $e->body === 'Rendered body for Bob.');
        });
    }

    public function test_coverage_warnings_flag_a_category_missing_an_active_template_for_an_enabled_channel(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            MsgChannelConfig::query()->create(['channel' => MsgChannelConfig::CHANNEL_SMS, 'enabled' => true, 'credentials' => [], 'config' => []]);

            $service = app(MsgTemplateService::class);
            $template = $service->create([
                'category_code' => 'demo.coverage_gap',
                'channel' => MsgChannelConfig::CHANNEL_EMAIL,
                'body' => 'Hello.',
                'variables' => [],
            ]);
            $service->activate($template);

            $warnings = $service->coverageWarnings();

            $this->assertNotEmpty(array_filter($warnings, fn ($w) => $w['category'] === 'demo.coverage_gap' && in_array('sms', $w['missing_channels'], true)));
        });
    }

    public function test_http_create_activate_and_preview_flow(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->post('/wne/templates', [
            'category_code' => 'demo.http_template',
            'channel' => 'in_app',
            'body' => 'Hi {{name}}!',
        ])->assertRedirect();

        $templateId = null;
        $tenant->run(function () use (&$templateId) {
            $templateId = MsgTemplate::query()->where('channel', 'in_app')->firstOrFail()->id;
        });

        // Undocumented {{name}} — activation must fail loudly, not silently succeed.
        $this->post("/wne/templates/{$templateId}/activate")->assertSessionHasErrors('variables');

        $this->put("/wne/templates/{$templateId}", ['body' => 'Hi {{name}}!', 'variables' => ['name']])->assertRedirect();
        $this->post("/wne/templates/{$templateId}/activate")->assertSessionDoesntHaveErrors();

        $tenant->run(function () use ($templateId) {
            $this->assertTrue(MsgTemplate::query()->findOrFail($templateId)->is_active);
        });

        $preview = $this->post('/wne/templates-preview', ['subject' => '', 'body' => 'Hi {{name}}!', 'sample_data' => json_encode(['name' => 'Jane'])]);
        $preview->assertOk();
        $this->assertSame('Hi Jane!', $preview->json('body'));
    }

    /**
     * End-to-end: the SLA breach dispatched by §3F now renders through a real active
     * template instead of the generic listener fallback ("New event: {...}") — proves
     * §3L's per-delivery resolution actually reaches the one dispatcher this module has.
     */
    public function test_an_sla_breach_notification_renders_through_an_active_template(): void
    {
        Event::fake([InAppNotificationCreated::class]);
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $service = app(MsgTemplateService::class);
            $template = $service->create([
                'category_code' => 'wne.sla_breach',
                'channel' => MsgChannelConfig::CHANNEL_IN_APP,
                'subject' => 'Breach: {{step_code}}',
                'body' => 'Step {{step_code}} breached its SLA.',
                'variables' => ['step_code'],
            ]);
            $service->activate($template);

            $definition = WrkflowDefinition::query()->create(['code' => 'demo.template_integration', 'name' => 'Template Integration', 'status' => WrkflowDefinition::STATUS_PUBLISHED]);
            $version = WrkflowVersion::query()->create(['definition_id' => $definition->id, 'version_no' => 1, 'published_by' => $this->adminId()]);
            $entry = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_APPROVAL, 'is_entry_step' => true,
                'config' => ['assignee' => ['type' => 'user', 'user_id' => $this->adminId()]],
            ]);
            WrkflowSlaRule::query()->create(['step_id' => $entry->id, 'sla_hours' => 1, 'escalation_action' => WrkflowSlaRule::ACTION_NOTIFY_ROLE, 'escalation_target' => 'STAFF']);
            ConfigGroupUser::query()->create([
                'group_id' => ConfigGroup::query()->where('code', 'STAFF')->value('id'),
                'group_code' => 'STAFF',
                'user_id' => $this->adminId(),
            ]);

            $base = Carbon::parse('2026-04-01 08:00:00');
            $this->travelTo($base);
            app(WorkflowService::class)->start('demo.template_integration', null, null, []);

            $this->travelTo($base->copy()->addHours(2));
            app(SlaEscalationService::class)->escalateBreachedSteps();

            Event::assertDispatched(InAppNotificationCreated::class, fn ($e) => $e->subject === 'Breach: entry' && $e->body === 'Step entry breached its SLA.');
        });
    }
}
