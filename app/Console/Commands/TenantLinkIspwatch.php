<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Ispwatch\IspwatchRepository;
use Illuminate\Console\Command;

class TenantLinkIspwatch extends Command
{
    /**
     * Uso:
     *   php artisan tenant:link 1 17        # vincula tenant Converza #1 con ispwatch #17
     *   php artisan tenant:link 1 --unlink  # desvincula
     *   php artisan tenant:link --list      # lista todos los vínculos actuales
     */
    protected $signature = 'tenant:link
        {converza? : ID del tenant en Converza}
        {ispwatch? : ID del tenant en ISPWatch}
        {--unlink : Quitar la vinculación}
        {--list : Listar todos los tenants y su vinculación}';

    protected $description = 'Gestiona la vinculación entre un tenant de Converza y uno de ISPWatch (solo admin del SaaS).';

    public function handle(IspwatchRepository $ispwatch): int
    {
        if ($this->option('list')) {
            return $this->listAll($ispwatch);
        }

        $converzaId = $this->argument('converza');
        if (! $converzaId) {
            $this->error('Falta el ID del tenant de Converza. Uso: tenant:link <converza_id> <ispwatch_id> | --unlink | --list');
            return self::INVALID;
        }

        $tenant = Tenant::find($converzaId);
        if (! $tenant) {
            $this->error("No existe el tenant de Converza con ID {$converzaId}.");
            return self::FAILURE;
        }

        if ($this->option('unlink')) {
            $previous = $tenant->ispwatch_tenant_id;
            $tenant->ispwatch_tenant_id = null;
            $tenant->save();
            $this->info("✓ Tenant '{$tenant->name}' desvinculado (antes apuntaba a ispwatch #{$previous}).");
            return self::SUCCESS;
        }

        $ispwatchId = $this->argument('ispwatch');
        if (! $ispwatchId) {
            $this->error('Falta el ID del tenant de ISPWatch. Uso: tenant:link <converza_id> <ispwatch_id>');
            return self::INVALID;
        }

        $info = $ispwatch->tenantInfo((int) $ispwatchId);
        if (! $info) {
            $this->error("No existe un tenant en ISPWatch con ID {$ispwatchId}.");
            $this->line('Sugerencia: corre <info>tenant:link --list</info> para ver opciones, o consulta directamente la BD de ispwatch.');
            return self::FAILURE;
        }

        // Detectar si ya hay otro tenant Converza vinculado a este mismo ispwatch
        $conflicting = Tenant::where('ispwatch_tenant_id', $ispwatchId)
            ->where('id', '!=', $tenant->id)
            ->first();
        if ($conflicting) {
            if (! $this->confirm("⚠ ISPWatch #{$ispwatchId} ya está vinculado al tenant Converza '{$conflicting->name}' (#{$conflicting->id}). ¿Continuar y duplicar el vínculo?", false)) {
                $this->warn('Cancelado.');
                return self::FAILURE;
            }
        }

        $previous = $tenant->ispwatch_tenant_id;
        $tenant->ispwatch_tenant_id = (int) $ispwatchId;
        $tenant->save();

        // Invalidar caché del repo para que el siguiente request al chat tome la data fresca
        cache()->forget("ispwatch:tenant_info:{$ispwatchId}");

        $action = $previous ? "re-vinculado (antes: #{$previous})" : 'vinculado';
        $this->info("✓ Tenant Converza '{$tenant->name}' (#{$tenant->id}) {$action} a ISPWatch '{$info['name']}' (#{$ispwatchId}).");
        $this->line("  · {$info['customers_count']} clientes accesibles");
        $this->line("  · {$info['invoices_count']} facturas accesibles");

        return self::SUCCESS;
    }

    private function listAll(IspwatchRepository $ispwatch): int
    {
        $tenants = Tenant::orderBy('id')->get(['id', 'name', 'slug', 'ispwatch_tenant_id', 'is_active']);

        if ($tenants->isEmpty()) {
            $this->warn('No hay tenants en Converza.');
            return self::SUCCESS;
        }

        $rows = $tenants->map(function ($t) use ($ispwatch) {
            $linkInfo = '—';
            if ($t->ispwatch_tenant_id) {
                $info = $ispwatch->tenantInfo((int) $t->ispwatch_tenant_id);
                $linkInfo = $info
                    ? "#{$t->ispwatch_tenant_id} \"{$info['name']}\""
                    : "#{$t->ispwatch_tenant_id} (NO EXISTE EN ISPWATCH)";
            }
            return [
                'id'       => $t->id,
                'name'     => $t->name,
                'slug'     => $t->slug,
                'active'   => $t->is_active ? 'sí' : 'no',
                'ispwatch' => $linkInfo,
            ];
        })->all();

        $this->table(
            ['ID', 'Nombre', 'Slug', 'Activo', 'ISPWatch vinculado'],
            $rows,
        );

        return self::SUCCESS;
    }
}
