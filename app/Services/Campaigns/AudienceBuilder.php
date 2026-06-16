<?php

namespace App\Services\Campaigns;

use App\Models\CampaignOptOut;
use App\Models\Contact;
use App\Models\Label;
use App\Models\Tenant;
use App\Services\Ispwatch\IspwatchRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Construye la lista de destinatarios de una campaña a partir de cualquiera de
 * las 4 fuentes soportadas (upload/manual/sheet ya llegan como filas; crm se
 * consulta acá). Siempre normaliza teléfono, deduplica, cruza contra la lista
 * de exclusión (campaign_opt_outs) y casa con Contact existente si hay match.
 *
 * No persiste nada — el controlador decide qué hacer con el resultado
 * (preview sin guardar, o crear los CampaignRecipient al lanzar la campaña).
 */
class AudienceBuilder
{
    public function __construct(private readonly IspwatchRepository $ispwatch) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows  Filas ya parseadas (upload/manual/sheet),
     *         cada una con al menos una columna de teléfono. Ignorado si source_type=crm.
     * @return array{rows: array<int, array<string, mixed>>, stats: array<string, int>}
     */
    public function build(
        Tenant $tenant,
        string $sourceType,
        array $rows,
        ?string $phoneColumn,
        ?string $nameColumn,
        ?int $labelId = null,
        string $ispwatchFilter = 'all',
    ): array {
        $raw = match ($sourceType) {
            'crm' => $this->fromCrm($tenant, $labelId, $ispwatchFilter),
            default => $rows,
        };

        return $this->normalize($tenant, $raw, $phoneColumn, $nameColumn, $sourceType === 'crm');
    }

    /**
     * Descarga y parsea un CSV desde un link de Google Sheets ("Publicar en la
     * web" o un /edit normal — en ambos casos lo convertimos a export CSV).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchSheetRows(string $url): array
    {
        $csvUrl = $this->toCsvExportUrl($url);

        $response = Http::timeout(15)->get($csvUrl);
        if (! $response->successful()) {
            throw new \RuntimeException('No se pudo descargar la hoja. Verifica que el link sea público ("Cualquiera con el link puede ver").');
        }

        return $this->parseCsv($response->body());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseCsv(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
        if ($lines === []) {
            return [];
        }

        $header = str_getcsv(array_shift($lines));
        $header = array_map(fn ($h) => trim((string) $h), $header);

        $rows = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line);
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key !== '' ? $key : "col_{$i}"] = $cols[$i] ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Parsea texto pegado a mano: una línea por contacto. Soporta
     * "Nombre, teléfono" o solo "teléfono".
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseManualText(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        $rows = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/[,;\t]/', $line, 2);
            if (count($parts) === 2) {
                $rows[] = ['nombre' => trim($parts[0]), 'telefono' => trim($parts[1])];
            } else {
                $rows[] = ['nombre' => '', 'telefono' => trim($parts[0])];
            }
        }

        return $rows;
    }

    /**
     * Convierte un link normal de Google Sheets (/edit, /pub) en su export CSV.
     * Si ya es un link de export/gviz, lo deja igual.
     */
    private function toCsvExportUrl(string $url): string
    {
        if (Str::contains($url, ['output=csv', 'format=csv'])) {
            return $url;
        }

        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
            $id = $m[1];
            $gid = null;
            if (preg_match('/[#&?]gid=(\d+)/', $url, $gm)) {
                $gid = $gm[1];
            }
            return "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv" . ($gid !== null ? "&gid={$gid}" : '');
        }

        return $url;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fromCrm(Tenant $tenant, ?int $labelId, string $ispwatchFilter): array
    {
        $query = Contact::query()->where('tenant_id', $tenant->id);

        if ($labelId) {
            $query->whereHas('labels', fn ($q) => $q->where('labels.id', $labelId)->where('labels.tenant_id', $tenant->id));
        }

        $contacts = $query->get(['id', 'phone', 'name', 'email']);

        if ($ispwatchFilter !== 'all' && $tenant->ispwatch_tenant_id) {
            $ispwatchTenantId = (int) $tenant->ispwatch_tenant_id;
            $wantCustomer = $ispwatchFilter === 'customers';
            $contacts = $contacts->filter(function (Contact $c) use ($ispwatchTenantId, $wantCustomer) {
                $isCustomer = $this->ispwatch->customerByPhone($ispwatchTenantId, $c->phone, $c->name) !== null;
                return $isCustomer === $wantCustomer;
            })->values();
        }

        return $contacts->map(fn (Contact $c) => [
            'nombre'     => $c->name,
            'telefono'   => $c->phone,
            'email'      => $c->email,
            'contact_id' => $c->id,
        ])->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, stats: array<string, int>}
     */
    private function normalize(Tenant $tenant, array $rows, ?string $phoneColumn, ?string $nameColumn, bool $fromCrm): array
    {
        $optedOut = CampaignOptOut::query()
            ->where('tenant_id', $tenant->id)
            ->pluck('phone')
            ->flip()
            ->all();

        // Si el usuario aún no eligió columnas (primera vista previa), adivinamos
        // por nombres comunes para que la UI ya muestre algo razonable.
        if (! $fromCrm && $rows !== []) {
            $phoneColumn ??= $this->guessColumn($rows[0], ['telefono', 'teléfono', 'phone', 'celular', 'numero', 'número', 'tel']);
            $nameColumn ??= $this->guessColumn($rows[0], ['nombre', 'name', 'cliente', 'contacto']);
        }

        $seenPhones = [];
        $out = [];
        $stats = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'duplicates' => 0, 'opted_out' => 0];

        foreach ($rows as $row) {
            $stats['total']++;

            $phoneRaw = $fromCrm
                ? ($row['telefono'] ?? null)
                : ($row[$phoneColumn] ?? $row['telefono'] ?? $row['phone'] ?? null);
            $nameRaw = $fromCrm
                ? ($row['nombre'] ?? null)
                : ($row[$nameColumn] ?? $row['nombre'] ?? $row['name'] ?? null);

            $phone = Contact::normalizePhone(is_scalar($phoneRaw) ? (string) $phoneRaw : null);

            $entry = [
                'phone'       => $phone,
                'name'        => $nameRaw !== null ? trim((string) $nameRaw) : null,
                'contact_id'  => $row['contact_id'] ?? null,
                'variables'   => $row,
                'valid'       => true,
                'skip_reason' => null,
            ];

            if (! $phone) {
                $entry['valid'] = false;
                $entry['skip_reason'] = 'Teléfono inválido o vacío';
                $stats['invalid']++;
            } elseif (isset($seenPhones[$phone])) {
                $entry['valid'] = false;
                $entry['skip_reason'] = 'Duplicado en la lista';
                $stats['duplicates']++;
            } elseif (isset($optedOut[$phone])) {
                $entry['valid'] = false;
                $entry['skip_reason'] = 'El contacto se dio de baja (opt-out)';
                $stats['opted_out']++;
            } else {
                $stats['valid']++;
            }

            if ($phone) {
                $seenPhones[$phone] = true;
            }

            if ($phone && ! $entry['contact_id']) {
                $existing = Contact::query()->where('tenant_id', $tenant->id)->where('phone', $phone)->first(['id']);
                $entry['contact_id'] = $existing?->id;
            }

            $out[] = $entry;
        }

        return ['rows' => $out, 'stats' => $stats];
    }
}
