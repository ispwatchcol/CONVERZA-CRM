<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignOptOut;
use App\Models\CampaignRecipient;
use App\Models\Label;
use App\Models\Template;
use App\Services\Campaigns\AudienceBuilder;
use App\Services\Campaigns\CampaignMessageBuilder;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CampaignController extends Controller
{
    public function __construct(
        private readonly AudienceBuilder $audience,
        private readonly CampaignMessageBuilder $messageBuilder,
        private readonly WhatsAppService $whatsapp,
    ) {}

    public function index(Request $request)
    {
        $tenantId = app('tenant')->id;

        $campaigns = Campaign::query()
            ->where('tenant_id', $tenantId)
            ->with('template:id,name')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Campaigns/Index', [
            'campaigns' => $campaigns,
            'tierThresholds' => config('campaigns.tier_warn_thresholds'),
        ]);
    }

    public function create()
    {
        $tenantId = app('tenant')->id;

        return Inertia::render('Campaigns/Create', [
            'templates' => Template::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'approved')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'language', 'category', 'body']),
            'labels' => Label::query()->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'color']),
            'defaultThrottle' => config('campaigns.default_throttle_per_minute'),
            'tierThresholds' => config('campaigns.tier_warn_thresholds'),
        ]);
    }

    public function show(Campaign $campaign)
    {
        $this->authorizeTenantOwnership($campaign);

        $status = request()->query('status', '');

        $recipients = $campaign->recipients()
            ->when(in_array($status, ['pending', 'queued', 'sent', 'delivered', 'read', 'failed', 'skipped', 'opted_out'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Campaigns/Show', [
            'campaign' => $campaign->load('template:id,name', 'creator:id,name'),
            'recipients' => $recipients,
            'filters' => ['status' => $status],
        ]);
    }

    /**
     * Construye y normaliza la audiencia sin persistir nada: usado por el
     * wizard para mostrar conteos/vista previa antes de crear la campaña.
     */
    public function previewAudience(Request $request)
    {
        $tenant = app('tenant');

        $data = $request->validate([
            'source_type' => ['required', Rule::in(['upload', 'crm', 'manual', 'sheet'])],
            'rows' => ['nullable', 'array'],
            'phone_column' => ['nullable', 'string'],
            'name_column' => ['nullable', 'string'],
            'manual_text' => ['nullable', 'string'],
            'sheet_url' => ['nullable', 'url'],
            'label_id' => ['nullable', 'integer'],
            'ispwatch_filter' => ['nullable', Rule::in(['all', 'customers', 'non_customers'])],
        ]);

        try {
            $rows = match ($data['source_type']) {
                'manual' => $this->audience->parseManualText($data['manual_text'] ?? ''),
                'sheet' => $this->audience->fetchSheetRows($data['sheet_url'] ?? ''),
                default => $data['rows'] ?? [],
            };
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $columns = $data['source_type'] !== 'crm' && $rows !== [] ? array_keys($rows[0]) : [];

        $result = $this->audience->build(
            $tenant,
            $data['source_type'],
            $rows,
            $data['phone_column'] ?? null,
            $data['name_column'] ?? null,
            $data['label_id'] ?? null,
            $data['ispwatch_filter'] ?? 'all',
        );

        return response()->json([...$result, 'columns' => $columns]);
    }

    /**
     * Vista previa en vivo del texto final de la plantilla para una fila de
     * ejemplo, mientras el usuario ajusta el mapeo de variables en el wizard.
     */
    public function previewMessage(Request $request)
    {
        $tenant = app('tenant');

        $data = $request->validate([
            'template_id' => ['required', 'integer'],
            'mapping' => ['required', 'array'],
            'row' => ['nullable', 'array'],
            'name' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
        ]);

        $template = Template::where('tenant_id', $tenant->id)->findOrFail($data['template_id']);

        return response()->json([
            'preview' => $this->messageBuilder->previewForRow($template, $data['row'] ?? [], $data['name'] ?? null, $data['phone'] ?? null, $data['mapping']),
            'variables' => $this->messageBuilder->templateVariableKeys($template),
        ]);
    }

    public function store(Request $request)
    {
        $tenant = app('tenant');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'template_id' => ['required', 'integer'],
            'source_type' => ['required', Rule::in(['upload', 'crm', 'manual', 'sheet'])],
            'source_meta' => ['nullable', 'array'],
            'variable_mapping' => ['required', 'array'],
            'throttle_per_minute' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'scheduled_at' => ['nullable', 'date'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.phone' => ['nullable', 'string'],
            'rows.*.name' => ['nullable', 'string'],
            'rows.*.contact_id' => ['nullable', 'integer'],
            'rows.*.variables' => ['nullable', 'array'],
            'rows.*.valid' => ['required', 'boolean'],
            'rows.*.skip_reason' => ['nullable', 'string'],
        ]);

        $template = Template::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'approved')
            ->where('is_active', true)
            ->find($data['template_id']);

        if (! $template) {
            return back()->withErrors(['template_id' => 'La plantilla elegida debe estar aprobada y activa.']);
        }

        $now = now();
        $seen = [];
        $toInsert = [];

        foreach ($data['rows'] as $row) {
            $phone = trim((string) ($row['phone'] ?? ''));
            // Mismo teléfono ya incluido (la fila duplicada de AudienceBuilder no
            // aporta nada más y rompe el unique(campaign_id, phone) si se inserta).
            if ($phone === '' || isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;

            $status = ($row['valid'] ?? false)
                ? CampaignRecipient::STATUS_PENDING
                : (str_contains((string) ($row['skip_reason'] ?? ''), 'opt-out')
                    ? CampaignRecipient::STATUS_OPTED_OUT
                    : CampaignRecipient::STATUS_SKIPPED);

            $toInsert[] = [
                'tenant_id' => $tenant->id,
                'phone' => $phone,
                'name' => $row['name'] ?? null,
                'contact_id' => $row['contact_id'] ?? null,
                'variables' => json_encode($row['variables'] ?? []),
                'status' => $status,
                'skip_reason' => $status === CampaignRecipient::STATUS_PENDING ? null : ($row['skip_reason'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $pendingCount = count(array_filter($toInsert, fn ($r) => $r['status'] === CampaignRecipient::STATUS_PENDING));

        if ($pendingCount === 0) {
            return back()->withErrors(['rows' => 'No hay destinatarios válidos para crear la campaña.']);
        }

        $campaign = DB::transaction(function () use ($tenant, $template, $data, $toInsert, $pendingCount) {
            $campaign = Campaign::create([
                'tenant_id' => $tenant->id,
                'template_id' => $template->id,
                'created_by' => auth()->id(),
                'name' => $data['name'],
                'status' => Campaign::STATUS_DRAFT,
                'source_type' => $data['source_type'],
                'source_meta' => $data['source_meta'] ?? null,
                'variable_mapping' => $data['variable_mapping'],
                'throttle_per_minute' => $data['throttle_per_minute'] ?? config('campaigns.default_throttle_per_minute'),
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'total_recipients' => $pendingCount,
                'skipped_count' => count($toInsert) - $pendingCount,
            ]);

            foreach (array_chunk($toInsert, 500) as $chunk) {
                CampaignRecipient::insert(array_map(fn ($r) => $r + ['campaign_id' => $campaign->id], $chunk));
            }

            return $campaign;
        });

        return redirect()->route('campaigns.show', $campaign)->with('success', 'Campaña creada como borrador.');
    }

    public function update(Request $request, Campaign $campaign)
    {
        $this->authorizeTenantOwnership($campaign);

        if (! in_array($campaign->status, [Campaign::STATUS_DRAFT, Campaign::STATUS_SCHEDULED], true)) {
            return back()->withErrors(['status' => 'Solo se puede editar una campaña en borrador o agendada.']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'template_id' => ['required', 'integer'],
            'variable_mapping' => ['required', 'array'],
            'throttle_per_minute' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $template = Template::query()
            ->where('tenant_id', $campaign->tenant_id)
            ->where('status', 'approved')
            ->where('is_active', true)
            ->find($data['template_id']);

        if (! $template) {
            return back()->withErrors(['template_id' => 'La plantilla elegida debe estar aprobada y activa.']);
        }

        $campaign->update([
            'name' => $data['name'],
            'template_id' => $template->id,
            'variable_mapping' => $data['variable_mapping'],
            'throttle_per_minute' => $data['throttle_per_minute'] ?? $campaign->throttle_per_minute,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'status' => $data['scheduled_at'] ? Campaign::STATUS_SCHEDULED : Campaign::STATUS_DRAFT,
        ]);

        return back()->with('success', 'Campaña actualizada.');
    }

    public function launch(Campaign $campaign)
    {
        $this->authorizeTenantOwnership($campaign);

        if (! in_array($campaign->status, [Campaign::STATUS_DRAFT, Campaign::STATUS_SCHEDULED], true)) {
            return back()->withErrors(['status' => 'La campaña ya fue lanzada.']);
        }

        if (! $campaign->hasPendingWork()) {
            return back()->withErrors(['status' => 'La campaña no tiene destinatarios pendientes.']);
        }

        if ($campaign->scheduled_at && $campaign->scheduled_at->isFuture()) {
            $campaign->update(['status' => Campaign::STATUS_SCHEDULED]);
            return back()->with('success', 'Campaña agendada para ' . $campaign->scheduled_at->format('d/m/Y H:i') . '.');
        }

        $campaign->update(['status' => Campaign::STATUS_SENDING, 'started_at' => now()]);

        return back()->with('success', 'Campaña lanzada. El envío comienza en el próximo ciclo (máx. 1 minuto).');
    }

    public function pause(Campaign $campaign)
    {
        $this->authorizeTenantOwnership($campaign);

        if (! in_array($campaign->status, [Campaign::STATUS_SENDING, Campaign::STATUS_SCHEDULED], true)) {
            return back()->withErrors(['status' => 'Solo se puede pausar una campaña en envío o agendada.']);
        }

        $campaign->update(['status' => Campaign::STATUS_PAUSED]);

        return back()->with('success', 'Campaña pausada.');
    }

    public function resume(Campaign $campaign)
    {
        $this->authorizeTenantOwnership($campaign);

        if ($campaign->status !== Campaign::STATUS_PAUSED) {
            return back()->withErrors(['status' => 'La campaña no está pausada.']);
        }

        $campaign->update([
            'status' => $campaign->scheduled_at && $campaign->scheduled_at->isFuture()
                ? Campaign::STATUS_SCHEDULED
                : Campaign::STATUS_SENDING,
        ]);

        return back()->with('success', 'Campaña reanudada.');
    }

    public function cancel(Campaign $campaign)
    {
        $this->authorizeTenantOwnership($campaign);

        if (in_array($campaign->status, [Campaign::STATUS_COMPLETED, Campaign::STATUS_CANCELLED], true)) {
            return back()->withErrors(['status' => 'La campaña ya está finalizada.']);
        }

        DB::transaction(function () use ($campaign) {
            $campaign->recipients()
                ->whereIn('status', [CampaignRecipient::STATUS_PENDING, CampaignRecipient::STATUS_QUEUED])
                ->update(['status' => CampaignRecipient::STATUS_SKIPPED, 'skip_reason' => 'Campaña cancelada']);

            $campaign->update(['status' => Campaign::STATUS_CANCELLED]);
        });

        return back()->with('success', 'Campaña cancelada.');
    }

    public function test(Request $request, Campaign $campaign)
    {
        $this->authorizeTenantOwnership($campaign);

        $data = $request->validate([
            'phone' => ['required', 'string'],
        ]);

        $template = $campaign->template;
        if (! $template) {
            return back()->withErrors(['phone' => 'La campaña no tiene plantilla asociada.']);
        }

        $sampleRecipient = $campaign->recipients()->first();
        $params = $sampleRecipient
            ? $this->messageBuilder->buildParams($template, $sampleRecipient, $campaign->variable_mapping ?? [])
            : [];

        $tenant = app('tenant');
        $result = $this->whatsapp->forTenant($tenant)->sendTemplate($data['phone'], $template->name, $template->language ?? 'es_CO', $params);

        if (! ($result['success'] ?? false)) {
            return back()->withErrors(['phone' => 'Falló el envío de prueba: ' . ($result['error'] ?? 'desconocido')]);
        }

        return back()->with('success', $result['mock'] ?? false
            ? 'Envío de prueba simulado (WhatsApp en modo mock, sin credenciales configuradas).'
            : 'Mensaje de prueba enviado.');
    }

    public function retryFailed(Campaign $campaign)
    {
        $this->authorizeTenantOwnership($campaign);

        $count = $campaign->recipients()->where('status', CampaignRecipient::STATUS_FAILED)->update([
            'status' => CampaignRecipient::STATUS_PENDING,
            'error' => null,
        ]);

        if ($count > 0 && in_array($campaign->status, [Campaign::STATUS_COMPLETED, Campaign::STATUS_FAILED], true)) {
            $campaign->update(['status' => Campaign::STATUS_SENDING]);
        }

        return back()->with('success', "{$count} destinatarios fallidos puestos de nuevo en cola.");
    }

    public function duplicate(Campaign $campaign)
    {
        $this->authorizeTenantOwnership($campaign);

        $copy = DB::transaction(function () use ($campaign) {
            $copy = Campaign::create([
                'tenant_id' => $campaign->tenant_id,
                'template_id' => $campaign->template_id,
                'created_by' => auth()->id(),
                'name' => $campaign->name . ' (copia)',
                'status' => Campaign::STATUS_DRAFT,
                'source_type' => $campaign->source_type,
                'source_meta' => $campaign->source_meta,
                'variable_mapping' => $campaign->variable_mapping,
                'throttle_per_minute' => $campaign->throttle_per_minute,
                'total_recipients' => 0,
            ]);

            $pending = 0;
            $campaign->recipients()
                ->whereIn('status', ['pending', 'queued', 'sent', 'delivered', 'read', 'failed'])
                ->chunkById(500, function ($chunk) use ($copy, &$pending) {
                    $now = now();
                    $rows = $chunk->map(function (CampaignRecipient $r) use ($copy, $now) {
                        return [
                            'tenant_id' => $copy->tenant_id,
                            'campaign_id' => $copy->id,
                            'contact_id' => $r->contact_id,
                            'phone' => $r->phone,
                            'name' => $r->name,
                            'variables' => json_encode($r->variables ?? []),
                            'status' => CampaignRecipient::STATUS_PENDING,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    })->all();
                    $pending += count($rows);
                    CampaignRecipient::insert($rows);
                });

            $copy->update(['total_recipients' => $pending]);

            return $copy;
        });

        return redirect()->route('campaigns.show', $copy)->with('success', 'Campaña duplicada como borrador.');
    }

    public function destroy(Campaign $campaign)
    {
        $this->authorizeTenantOwnership($campaign);

        if ($campaign->status === Campaign::STATUS_SENDING) {
            return back()->withErrors(['status' => 'Pausa o cancela la campaña antes de borrarla.']);
        }

        $campaign->delete();

        return back()->with('success', 'Campaña eliminada.');
    }

    public function export(Campaign $campaign)
    {
        $this->authorizeTenantOwnership($campaign);

        $filename = 'campana-' . $campaign->id . '-destinatarios.csv';

        return response()->streamDownload(function () use ($campaign) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Teléfono', 'Nombre', 'Estado', 'Enviado', 'Entregado', 'Leído', 'Respondió', 'Error']);

            $campaign->recipients()->orderBy('id')->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $r) {
                    fputcsv($out, [
                        $r->phone,
                        $r->name,
                        $r->status,
                        $r->sent_at?->format('Y-m-d H:i'),
                        $r->delivered_at?->format('Y-m-d H:i'),
                        $r->read_at?->format('Y-m-d H:i'),
                        $r->replied_at?->format('Y-m-d H:i'),
                        $r->error,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function authorizeTenantOwnership(Campaign $campaign): void
    {
        abort_if($campaign->tenant_id !== app('tenant')->id, 403, 'No autorizado para este tenant.');
    }
}
