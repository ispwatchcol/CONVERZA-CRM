<?php

namespace App\Services\Campaigns;

use App\Models\CampaignRecipient;
use App\Models\Template;
use App\Services\Templates\TemplateRenderer;

/**
 * Resuelve `variable_mapping` (definido en el wizard: cada variable de la
 * plantilla → columna del archivo, campo de contacto o texto fijo) contra los
 * datos concretos de UN destinatario, y produce los params listos para
 * WhatsAppService::sendTemplate().
 *
 * `variable_mapping` tiene la forma:
 *   { "<nombre_o_posicion_de_variable>": { "type": "column|contact_field|static", "value": "..." } }
 *
 * - column: `value` es la clave dentro de la fila original (variables del destinatario).
 * - contact_field: `value` es uno de name|phone.
 * - static: `value` es el texto literal.
 */
class CampaignMessageBuilder
{
    /**
     * @param  array<string, array{type: string, value: string}>  $mapping
     * @return array<int|string, string>
     */
    public function buildParams(Template $template, CampaignRecipient $recipient, array $mapping): array
    {
        return $this->paramsFromValues(
            $template,
            $this->resolveValues($recipient->variables ?? [], $recipient->name, $recipient->phone, $mapping),
        );
    }

    public function renderBody(Template $template, array $params): string
    {
        return TemplateRenderer::renderBody($template, $params);
    }

    /**
     * Vista previa del cuerpo final para una fila cruda (antes de persistir
     * CampaignRecipient), usado en el wizard.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, array{type: string, value: string}>  $mapping
     */
    public function previewForRow(Template $template, array $row, ?string $name, ?string $phone, array $mapping): string
    {
        $params = $this->paramsFromValues($template, $this->resolveValues($row, $name, $phone, $mapping));
        return TemplateRenderer::renderBody($template, $params);
    }

    /**
     * Variables únicas que la plantilla espera (nombradas o "1","2",...).
     *
     * @return array<int, string>
     */
    public function templateVariableKeys(Template $template): array
    {
        if ($template->usesNamedVariables()) {
            return Template::namedVariablesIn($template->body);
        }

        return array_map('strval', Template::positionalVariablesIn($template->body));
    }

    /**
     * @param  array<string, array{type: string, value: string}>  $mapping
     * @return array<string, string>
     */
    private function resolveValues(array $row, ?string $name, ?string $phone, array $mapping): array
    {
        $values = [];

        foreach ($mapping as $variable => $source) {
            $type = $source['type'] ?? 'static';
            $value = $source['value'] ?? '';

            $values[$variable] = match ($type) {
                'column' => (string) ($row[$value] ?? ''),
                'contact_field' => match ($value) {
                    'name' => (string) ($name ?? ''),
                    'phone' => (string) ($phone ?? ''),
                    default => '',
                },
                default => (string) $value,
            };
        }

        return $values;
    }

    /**
     * Reparte un mapa { variable => valor } en los params posicional/named que
     * espera WhatsAppService::sendTemplate(), según el formato de la plantilla.
     *
     * @param  array<string, string>  $values
     * @return array<int|string, string>
     */
    private function paramsFromValues(Template $template, array $values): array
    {
        if ($template->usesNamedVariables()) {
            $params = [];
            foreach (Template::namedVariablesIn($template->body) as $name) {
                $params[$name] = $values[$name] ?? '';
            }
            return $params;
        }

        $positional = Template::positionalVariablesIn($template->body);
        $varCount = $positional ? max($positional) : 0;

        $params = [];
        for ($i = 1; $i <= $varCount; $i++) {
            $params[] = $values[(string) $i] ?? '';
        }
        return $params;
    }
}
