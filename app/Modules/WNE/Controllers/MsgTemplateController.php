<?php

namespace App\Modules\WNE\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WNE\Models\MsgTemplate;
use App\Modules\WNE\Requests\StoreMsgTemplateRequest;
use App\Modules\WNE\Requests\UpdateMsgTemplateRequest;
use App\Modules\WNE\Services\MsgTemplateService;
use App\Modules\WNE\Services\TemplateRenderingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3L — Dynamic Template Management. */
class MsgTemplateController extends Controller
{
    public function __construct(
        protected MsgTemplateService $templates,
        protected TemplateRenderingService $renderer,
    ) {}

    public function index(): Response
    {
        $rows = MsgTemplate::query()
            ->with('category')
            ->orderBy('category_id')
            ->get()
            ->map(fn (MsgTemplate $t) => [
                'id' => $t->id,
                'category_code' => $t->category->code,
                'channel' => $t->channel,
                'locale' => $t->locale,
                'subject' => $t->subject,
                'is_active' => $t->is_active,
            ]);

        return Inertia::render('WNE/Templates/Index', [
            'templates' => $rows,
            'coverageWarnings' => $this->templates->coverageWarnings(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('WNE/Templates/Create');
    }

    public function store(StoreMsgTemplateRequest $request)
    {
        $template = $this->templates->create($request->validated());

        return to_route('wne.templates.edit', $template)->with('success', 'Template created as a draft — activate it once you\'re happy with the preview.');
    }

    public function edit(MsgTemplate $template): Response
    {
        return Inertia::render('WNE/Templates/Edit', [
            'template' => [
                'id' => $template->id,
                'category_code' => $template->category->code,
                'channel' => $template->channel,
                'locale' => $template->locale,
                'subject' => $template->subject,
                'body' => $template->body,
                'variables' => $template->variables ?? [],
                'is_active' => $template->is_active,
            ],
        ]);
    }

    public function update(UpdateMsgTemplateRequest $request, MsgTemplate $template)
    {
        $this->templates->update($template, $request->validated());

        return back()->with('success', 'Template updated.');
    }

    public function destroy(MsgTemplate $template)
    {
        $this->templates->delete($template);

        return to_route('wne.templates.index')->with('success', 'Template deleted.');
    }

    public function activate(MsgTemplate $template)
    {
        $this->templates->activate($template);

        return back()->with('success', 'Template activated.');
    }

    public function deactivate(MsgTemplate $template)
    {
        $this->templates->deactivate($template);

        return back()->with('success', 'Template deactivated.');
    }

    /** Same renderer the send path uses — preview can never show something a real send wouldn't produce. */
    public function preview(Request $request)
    {
        $data = json_decode((string) $request->input('sample_data', '{}'), true) ?? [];

        return response()->json([
            'subject' => $request->filled('subject') ? $this->renderer->render($request->input('subject'), $data) : null,
            'body' => $this->renderer->render((string) $request->input('body'), $data),
        ]);
    }
}
