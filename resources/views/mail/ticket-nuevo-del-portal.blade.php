@php
    $tipoLabel = [
        'technical'  => 'Técnico',
        'billing'    => 'Facturación',
        'onboarding' => 'Puesta en marcha',
        'other'      => 'Otro',
    ][$tipo] ?? $tipo;

    $productoLabel = ['ispwatch' => 'ISPWatch', 'converza' => 'Converza'][$producto] ?? $producto;
@endphp

<x-mail::message>
# Requerimiento #{{ $numero }}

**{{ $cuenta }}** abrió un requerimiento desde su panel.

**{{ $asunto }}**

@if($tipoLabel || $productoLabel)
{{ collect([$tipoLabel, $productoLabel])->filter()->implode(' · ') }}
@endif

<x-mail::panel>
{{ $mensaje }}
</x-mail::panel>

Está sin responder desde ahora mismo, así que ya aparece arriba en la bandeja.

<x-mail::button :url="$enlace">
Abrir la bandeja de tickets
</x-mail::button>

Si contestas este correo no le llega al cliente: la respuesta se escribe en el
ticket, desde la bandeja o desde la ficha de la cuenta.
</x-mail::message>
