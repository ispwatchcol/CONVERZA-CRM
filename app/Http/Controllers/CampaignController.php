<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignOptOut;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Models\CampaignStep;
use App\Models\CampaignWarmup;
use App\Models\Label;
use App\Models\Template;
use App\Services\Campaigns\AudienceBuilder;
use App\Services\Campaigns\CampaignMessageBuilder;
use App\Services\Campaigns\WarmupBudget;
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
        private readonly WarmupBudget $warmupBudget,
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

        $warmup = CampaignWarmup::forTenant($tenantId);

        return Inertia::render('Campaigns/Index', [
            'campaigns' => $campaigns,
            'tierThresholds' => config('campaigns.tier_warn_thresholds'),
            'canManage' => $request->user()->hasStaffRole('admin'),
            'warmup' => [
                'enabled' => $warmup->enabled,
                'start_per_day' => $warmup->start_per_day,
                'daily_increment' => $warmup->daily_increment,
                'max_per_day' => $warmup->max_per_day,
                'started_on' => $warmup->started_on?->toDateString(),
                'used_last_24h' => $this->warmupBudget->usedLast24h($tenantId),
                // Solo tiene sentido mostrar el tope cuando el warm-up está activo.
                'allowance_today' => $warmup->enabled ? $this->warmupBudget->allowance($warmup) : null,
            ],
        ]);
    }

    public function create()
    {
        $tenantId = app('tenant')->id;

        return Inertia::render('Campaigns/Create', [
            'templates' => $this->eligibleTemplates($tenantId)
                ->orderBy('name')
                ->get(['id', 'name', 'language', 'category', 'body']),
            'labels' => Label::query()->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'color']),
            'defaultThrottle' => config('campaigns.default_throttle_per_minute'),
            'tierThresholds' => config('campaigns.tier_warn_thresholds'),
            'sendConditions' => CampaignStep::CONDITIONS,
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

        // Embudo por paso: una fila por step_order con sus conteos (count(col)
        // ignora los null en Postgres, así que cuenta solo los que llegaron a
        // ese estado). `read` es palabra reservada → alias read_count.
        $funnel = CampaignSend::where('campaign_id', $campaign->id)
            ->selectRaw("step_order,
                count(*) as total,
                count(sent_at) as sent,
                count(delivered_at) as delivered,
                count(read_at) as read_count,
                count(replied_at) as replied,
                sum(case when status = 'failed' then 1 else 0 end) as failed")
            ->groupBy('step_order')
            ->get()
            ->keyBy('step_order');

        $steps = $campaign->steps()->with('template:id,name')->get()->map(function (CampaignStep $s) use ($funnel) {
            $m = $funnel->get($s->step_order);

            return [
                'step_order' => $s->step_order,
                'template' => $s->template?->name,
                'delay_hours' => $s->delay_hours,
                'send_condition' => $s->send_condition,
                'metrics' => [
                    'total' => (int) ($m->total ?? 0),
                    'sent' => (int) ($m->sent ?? 0),
                    'delivered' => (int) ($m->delivered ?? 0),
                    'read' => (int) ($m->read_count ?? 0),
                    'replied' => (int) ($m->replied ?? 0),
                    'failed' => (int) ($m->failed ?? 0),
                ],
            ];
        });

        return Inertia::render('Campaigns/Show', [
            'campaign' => $campaign->load('template:id,name', 'creator:id,name'),
            'recipients' => $recipients,
            'steps' => $steps,
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
            // Pasos de seguimiento (la plantilla inicial es el paso 1, aparte).
            'steps' => ['nullable', 'array'],
            'steps.*.template_id' => ['required', 'integer'],
            'steps.*.variable_mapping' => ['nullable', 'array'],
            'steps.*.delay_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'steps.*.send_condition' => ['required', Rule::in(CampaignStep::CONDITIONS)],
        ]);

        $template = $this->eligibleTemplates($tenant->id)->find($data['template_id']);

        if (! $template) {
            return back()->withErrors(['template_id' => 'La plantilla elegida debe estar aprobada, activa y de categoría Marketing (publicidad/ventas).']);
        }

        // Cada paso de seguimiento debe usar también una plantilla Marketing aprobada+activa.
        $followUps = [];
        foreach ($data['steps'] ?? [] as $s) {
            $stepTemplate = $this->eligibleTemplates($tenant->id)->find($s['template_id']);

            if (! $stepTemplate) {
                return back()->withErrors(['steps' => 'Cada paso de seguimiento debe usar una plantilla aprobada, activa y de categoría Marketing (publicidad/ventas).']);
            }

            $followUps[] = $s;
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
                'current_step' => 1,
                'enrollment_status' => match ($status) {
                    CampaignRecipient::STATUS_PENDING => CampaignRecipient::ENROLLMENT_ACTIVE,
                    CampaignRecipient::STATUS_OPTED_OUT => CampaignRecipient::ENROLLMENT_OPTED_OUT,
                    default => CampaignRecipient::ENROLLMENT_COMPLETED,
                },
                'skip_reason' => $status === CampaignRecipient::STATUS_PENDING ? null : ($row['skip_reason'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $pendingCount = count(array_filter($toInsert, fn ($r) => $r['status'] === CampaignRecipient::STATUS_PENDING));

        if ($pendingCount === 0) {
            return back()->withErrors(['rows' => 'No hay destinatarios válidos para crear la campaña.']);
        }

        $campaign = DB::transaction(function () use ($tenant, $template, $data, $toInsert, $pendingCount, $followUps) {
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

            $this->syncSteps($campaign, $template->id, $data['variable_mapping'], $followUps);

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
            'steps' => ['nullable', 'array'],
            'steps.*.template_id' => ['required', 'integer'],
            'steps.*.variable_mapping' => ['nullable', 'array'],
            'steps.*.delay_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'steps.*.send_condition' => ['required', Rule::in(CampaignStep::CONDITIONS)],
        ]);

        $template = $this->eligibleTemplates($campaign->tenant_id)->find($data['template_id']);

        if (! $template) {
            return back()->withErrors(['template_id' => 'La plantilla elegida debe estar aprobada, activa y de categoría Marketing (publicidad/ventas).']);
        }

        $followUps = [];
        foreach ($data['steps'] ?? [] as $s) {
            $ok = $this->eligibleTemplates($campaign->tenant_id)->whereKey($s['template_id'])->exists();

            if (! $ok) {
                return back()->withErrors(['steps' => 'Cada paso de seguimiento debe usar una plantilla aprobada, activa y de categoría Marketing (publicidad/ventas).']);
            }

            $followUps[] = $s;
        }

        DB::transaction(function () use ($campaign, $template, $data, $followUps) {
            $campaign->update([
                'name' => $data['name'],
                'template_id' => $template->id,
                'variable_mapping' => $data['variable_mapping'],
                'throttle_per_minute' => $data['throttle_per_minute'] ?? $campaign->throttle_per_minute,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'status' => $data['scheduled_at'] ? Campaign::STATUS_SCHEDULED : Campaign::STATUS_DRAFT,
            ]);

            $this->syncSteps($campaign, $template->id, $data['variable_mapping'], $followUps);
        });

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

        // Última línea de defensa: Meta exige plantillas Marketing (publicidad/
        // ventas) para campañas. Si algún paso quedó con otra categoría o perdió
        // la aprobación (p. ej. un borrador viejo), no dejar lanzar.
        $badStep = $campaign->steps()
            ->whereDoesntHave('template', fn ($q) => $q
                ->where('status', 'approved')
                ->where('is_active', true)
                ->where('category', config('campaigns.template_category')))
            ->orderBy('step_order')
            ->first();

        if ($badStep) {
            return back()->withErrors(['status' => "El paso {$badStep->step_order} usa una plantilla que no es de categoría Marketing aprobada y activa. Meta lo exige para campañas (publicidad/ventas)."]);
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
                ->whereIn('enrollment_status', [CampaignRecipient::ENROLLMENT_ACTIVE, CampaignRecipient::ENROLLMENT_SENDING])
                ->update([
                    'enrollment_status' => CampaignRecipient::ENROLLMENT_COMPLETED,
                    'status' => CampaignRecipient::STATUS_SKIPPED,
                    'skip_reason' => 'Campaña cancelada',
                    'next_action_at' => null,
                ]);

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

        $count = $campaign->recipients()->where('enrollment_status', CampaignRecipient::ENROLLMENT_FAILED)->update([
            'enrollment_status' => CampaignRecipient::ENROLLMENT_ACTIVE,
            'status' => CampaignRecipient::STATUS_PENDING,
            'error' => null,
            'next_action_at' => null,
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

            foreach ($campaign->steps as $step) {
                CampaignStep::create([
                    'tenant_id' => $copy->tenant_id,
                    'campaign_id' => $copy->id,
                    'step_order' => $step->step_order,
                    'template_id' => $step->template_id,
                    'variable_mapping' => $step->variable_mapping,
                    'delay_hours' => $step->delay_hours,
                    'send_condition' => $step->send_condition,
                ]);
            }

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
                            'current_step' => 1,
                            'enrollment_status' => CampaignRecipient::ENROLLMENT_ACTIVE,
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

    /**
     * Configura el "calentamiento" (warm-up) del número de WhatsApp del tenant:
     * la rampa de volumen diario compartida por todas sus campañas. Solo admin
     * (afecta el quality rating del número, igual que lanzar/pausar).
     */
    public function updateWarmup(Request $request)
    {
        $tenant = app('tenant');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'start_per_day' => ['required', 'integer', 'min:1', 'max:100000'],
            'daily_increment' => ['required', 'integer', 'min:0', 'max:100000'],
            'max_per_day' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        if ($data['max_per_day'] < $data['start_per_day']) {
            return back()->withErrors(['max_per_day' => 'El tope diario no puede ser menor que el envío inicial.']);
        }

        $warmup = CampaignWarmup::forTenant($tenant->id);
        $wasEnabled = (bool) $warmup->getOriginal('enabled');

        $warmup->fill($data);
        $warmup->tenant_id = $tenant->id;

        // Al encender (o re-encender) la rampa, reinicia el día 0 a hoy: así no
        // salta a un tope alto por los días que el warm-up estuvo apagado.
        if ($data['enabled'] && ! $wasEnabled) {
            $warmup->started_on = now()->toDateString();
        }

        $warmup->save();

        return back()->with('success', 'Configuración de calentamiento guardada.');
    }

    /**
     * Reescribe los pasos de la campaña: paso 1 (envío inicial con la plantilla
     * principal, condición `always`) + los seguimientos en orden. Usado al crear
     * y al editar (borra los pasos anteriores; seguro porque solo se edita en
     * borrador/agendada, sin envíos aún).
     *
     * @param  array<int, array{template_id:int, variable_mapping?:array, delay_hours:int, send_condition:string}>  $followUps
     */
    private function syncSteps(Campaign $campaign, int $step1TemplateId, array $step1Mapping, array $followUps): void
    {
        $campaign->steps()->delete();

        CampaignStep::create([
            'tenant_id' => $campaign->tenant_id,
            'campaign_id' => $campaign->id,
            'step_order' => 1,
            'template_id' => $step1TemplateId,
            'variable_mapping' => $step1Mapping,
            'delay_hours' => 0,
            'send_condition' => CampaignStep::CONDITION_ALWAYS,
        ]);

        $order = 2;
        foreach ($followUps as $s) {
            CampaignStep::create([
                'tenant_id' => $campaign->tenant_id,
                'campaign_id' => $campaign->id,
                'step_order' => $order++,
                'template_id' => $s['template_id'],
                'variable_mapping' => $s['variable_mapping'] ?? [],
                'delay_hours' => $s['delay_hours'],
                'send_condition' => $s['send_condition'],
            ]);
        }
    }

    /**
     * Query base de plantillas elegibles para campañas: aprobadas, activas y de
     * la categoría que Meta exige (Marketing / publicidad-ventas). Centralizar
     * esto evita que se cuele una utility/authentication en cualquier paso.
     */
    private function eligibleTemplates(int $tenantId)
    {
        return Template::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'approved')
            ->where('is_active', true)
            ->where('category', config('campaigns.template_category'));
    }

    private function authorizeTenantOwnership(Campaign $campaign): void
    {
        abort_if($campaign->tenant_id !== app('tenant')->id, 403, 'No autorizado para este tenant.');
    }
}
