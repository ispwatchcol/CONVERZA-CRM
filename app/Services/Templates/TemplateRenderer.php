<?php

namespace App\Services\Templates;

use App\Models\Template;

/**
 * Reconstrucción del texto final de una plantilla a partir de los params que se
 * mandan a Meta (named o posicional). Es la MISMA lógica que usaba
 * SendBillingNotifications::renderBody, extraída aquí para que las campañas
 * masivas (CampaignMessageBuilder) también puedan mostrar el texto final en la
 * vista previa y en el eco del chat sin duplicar el switch named/posicional.
 */
class TemplateRenderer
{
    /**
     * @param  array<int|string, string>  $params
     */
    public static function renderBody(Template $tpl, array $params): string
    {
        $body = (string) $tpl->body;

        if (! array_is_list($params)) {
            foreach ($params as $name => $value) {
                // callback evita que un '$' en el valor se interprete como backreference.
                $body = preg_replace_callback(
                    '/\{\{\s*' . preg_quote((string) $name, '/') . '\s*\}\}/',
                    fn () => $value,
                    $body,
                );
            }
            return $body;
        }

        foreach ($params as $i => $value) {
            $body = str_replace('{{' . ($i + 1) . '}}', $value, $body);
        }
        return $body;
    }
}
