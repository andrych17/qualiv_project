<?php

namespace App\Modules\WNE\Services;

use App\Modules\WNE\Models\MsgCategory;
use App\Modules\WNE\Models\MsgChannelConfig;
use App\Modules\WNE\Models\MsgTemplate;
use Illuminate\Validation\ValidationException;

/**
 * §3L — template CRUD + the two configuration-time checks the spec calls for:
 * an undocumented `{{var}}` blocks activation outright (a real authoring bug); a category
 * missing template coverage for a channel the tenant actually has enabled is a warning
 * surfaced on the index page, not a blocker — categories aren't required to declare
 * `default_channels` (still §3J's job), so "enabled channel with no active template for
 * this category" is the closest checkable proxy for "coverage gap" available today.
 */
class MsgTemplateService
{
    public function __construct(private readonly TemplateRenderingService $renderer) {}

    public function create(array $data): MsgTemplate
    {
        $category = $this->resolveCategory($data['category_code']);

        return MsgTemplate::query()->create([
            'category_id' => $category->id,
            'channel' => $data['channel'],
            'locale' => $data['locale'] ?? MsgTemplate::DEFAULT_LOCALE,
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
            'variables' => $data['variables'] ?? [],
        ]);
    }

    public function update(MsgTemplate $template, array $data): MsgTemplate
    {
        $template->update([
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
            'variables' => $data['variables'] ?? [],
        ]);

        // Editing the body of an already-active template can introduce a new undocumented
        // variable — re-validate rather than let a stale activation stand.
        if ($template->is_active) {
            $this->assertDocumented($template);
        }

        return $template;
    }

    public function delete(MsgTemplate $template): void
    {
        $template->delete();
    }

    /** @throws ValidationException when the template text uses a `{{var}}` its own `variables` list doesn't document. */
    public function activate(MsgTemplate $template): MsgTemplate
    {
        $this->assertDocumented($template);
        $template->update(['is_active' => true]);

        return $template;
    }

    public function deactivate(MsgTemplate $template): MsgTemplate
    {
        $template->update(['is_active' => false]);

        return $template;
    }

    private function assertDocumented(MsgTemplate $template): void
    {
        $used = $this->renderer->extractVariables(($template->subject ?? '').' '.$template->body);
        $documented = $template->variables ?? [];
        $undocumented = array_diff($used, $documented);

        if ($undocumented !== []) {
            throw ValidationException::withMessages([
                'variables' => 'Undocumented variable(s) used in the template: {{'.implode('}}, {{', $undocumented).'}} — add them to the variable list before activating.',
            ]);
        }
    }

    private function resolveCategory(string $code): MsgCategory
    {
        return MsgCategory::query()->firstOrCreate(['code' => $code], ['name' => $code]);
    }

    /**
     * §3L: "missing template combinations fail loudly at configuration time (a clear
     * admin-facing warning)" — for every category with at least one template, list the
     * tenant's enabled channels it has no ACTIVE template for.
     *
     * @return array<int, array{category: string, missing_channels: string[]}>
     */
    public function coverageWarnings(): array
    {
        $enabledChannels = MsgChannelConfig::query()->where('enabled', true)->pluck('channel')->all();

        if ($enabledChannels === []) {
            return [];
        }

        $categories = MsgCategory::query()->whereHas('templates')->with('templates')->get();

        $warnings = [];

        foreach ($categories as $category) {
            $activeChannels = $category->templates->where('is_active', true)->pluck('channel')->all();
            $missing = array_values(array_diff($enabledChannels, $activeChannels));

            if ($missing !== []) {
                $warnings[] = ['category' => $category->code, 'missing_channels' => $missing];
            }
        }

        return $warnings;
    }
}
