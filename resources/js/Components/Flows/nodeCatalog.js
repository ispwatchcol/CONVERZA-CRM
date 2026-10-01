// Catálogo de bloques del Workspace de flujos, del lado del editor.
//
// OJO: las SALIDAS de cada bloque (outputsOf) tienen que coincidir con
// NodeType::outputs() en app/Services/Flows/Nodes. El servidor es la autoridad
// (valida al publicar), pero si acá se dibuja una salida que allá no existe, el
// admin podría conectar algo que el motor nunca va a recorrer.
//
// Las clases de Tailwind van COMPLETAS (no armadas con `bg-${color}`): Tailwind
// solo genera las clases que encuentra escritas tal cual en el código.

export const NODE_TYPES = {
    start: {
        label: 'Inicio',
        hint: 'Cuándo arranca el flujo',
        header: 'bg-emerald-50 text-emerald-800 border-emerald-100',
        dot: 'bg-emerald-500',
        handle: '!bg-emerald-500',
        palette: false,
    },
    message: {
        label: 'Mensaje',
        hint: 'Envía un texto y sigue',
        header: 'bg-sky-50 text-sky-800 border-sky-100',
        dot: 'bg-sky-500',
        handle: '!bg-sky-500',
        palette: true,
    },
    question: {
        label: 'Pregunta',
        hint: 'Pregunta y guarda la respuesta',
        header: 'bg-violet-50 text-violet-800 border-violet-100',
        dot: 'bg-violet-500',
        handle: '!bg-violet-500',
        palette: true,
    },
    menu: {
        label: 'Menú',
        hint: 'Opciones con una rama por opción',
        header: 'bg-indigo-50 text-indigo-800 border-indigo-100',
        dot: 'bg-indigo-500',
        handle: '!bg-indigo-500',
        palette: true,
    },
    condition: {
        label: 'Condición',
        hint: 'Sí / No según reglas u horario',
        header: 'bg-amber-50 text-amber-800 border-amber-100',
        dot: 'bg-amber-500',
        handle: '!bg-amber-500',
        palette: true,
    },
    ispwatch: {
        label: 'Datos del cliente',
        hint: 'Busca al cliente en ispwatch',
        header: 'bg-teal-50 text-teal-800 border-teal-100',
        dot: 'bg-teal-500',
        handle: '!bg-teal-500',
        palette: true,
    },
    tag: {
        label: 'Etiquetar',
        hint: 'Pone o quita una etiqueta',
        header: 'bg-pink-50 text-pink-800 border-pink-100',
        dot: 'bg-pink-500',
        handle: '!bg-pink-500',
        palette: true,
    },
    wait: {
        label: 'Espera',
        hint: 'Pausa unos minutos',
        header: 'bg-slate-100 text-slate-700 border-slate-200',
        dot: 'bg-slate-500',
        handle: '!bg-slate-500',
        palette: true,
    },
    handoff: {
        label: 'Pasar a un asesor',
        hint: 'Entrega la conversación al equipo',
        header: 'bg-orange-50 text-orange-800 border-orange-100',
        dot: 'bg-orange-500',
        handle: '!bg-orange-500',
        palette: true,
    },
    end: {
        label: 'Fin',
        hint: 'Termina: el bot resolvió',
        header: 'bg-gray-100 text-gray-700 border-gray-200',
        dot: 'bg-gray-500',
        handle: '!bg-gray-500',
        palette: true,
    },
};

export const PALETTE = Object.entries(NODE_TYPES)
    .filter(([, meta]) => meta.palette)
    .map(([type, meta]) => ({ type, ...meta }));

/** Id de bloque válido para el validador: /^[A-Za-z0-9_-]{1,64}$/. */
export function newId(prefix = 'n') {
    return `${prefix}_${Math.random().toString(36).slice(2, 8)}`;
}

/** Configuración inicial de un bloque recién agregado: ya publicable o casi. */
export function defaultData(type) {
    switch (type) {
        case 'message':
            return { text: '' };
        case 'question':
            return { text: '', save_as: '', validation: 'any', retry_text: '', max_retries: 1, save_to_contact: '' };
        case 'menu':
            return {
                text: '¿En qué te puedo ayudar?',
                style: 'text',
                button_label: 'Ver opciones',
                append_options: true,
                options: [
                    { id: newId('o'), label: 'Opción 1', keywords: '' },
                    { id: newId('o'), label: 'Opción 2', keywords: '' },
                ],
                retry_text: '',
                max_retries: 1,
            };
        case 'condition':
            return { match: 'all', rules: [{ kind: 'variable', variable: 'respuesta', operator: 'contains', value: '' }] };
        case 'tag':
            return { label_id: null, action: 'add' };
        case 'wait':
            return { minutes: 5 };
        case 'handoff':
            return { text: 'Te comunico con un asesor 🙌. En breve alguien del equipo te escribe.', team_id: null, assign: 'auto', note: true };
        default:
            return {};
    }
}

/** Opciones con nombre de un menú (las vacías no cuentan, igual que en el servidor). */
export function menuOptions(data) {
    return (data?.options ?? []).filter((o) => o && String(o.label ?? '').trim() !== '');
}

/**
 * Salidas del bloque, en el orden en que se dibujan.
 * Espejo de NodeType::outputs() del backend.
 *
 * @returns {Array<{handle: string, label: string}>}
 */
export function outputsOf(type, data = {}) {
    switch (type) {
        case 'end':
        case 'handoff':
            return [];
        case 'menu':
            // Una opción sin nombre conserva su salida mientras se edita: si no,
            // borrar el texto para reescribirlo se llevaría su conexión. El
            // validador del servidor no deja publicar opciones sin nombre.
            return [
                ...(data?.options ?? []).filter((o) => o && o.id).map((o) => ({
                    handle: `opt_${o.id}`,
                    label: String(o.label ?? '').trim() || '(sin nombre)',
                })),
                { handle: 'no_match', label: 'No entendió' },
            ];
        case 'condition':
            return [
                { handle: 'true', label: 'Sí' },
                { handle: 'false', label: 'No' },
            ];
        case 'ispwatch':
            return [
                { handle: 'found', label: 'Encontrado' },
                { handle: 'not_found', label: 'No encontrado' },
            ];
        case 'question':
            return (data?.validation ?? 'any') === 'any'
                ? [{ handle: 'next', label: 'Respondió' }]
                : [
                    { handle: 'next', label: 'Respondió' },
                    { handle: 'invalid', label: 'Sin respuesta válida' },
                ];
        default:
            return [{ handle: 'next', label: 'Siguiente' }];
    }
}

const OPERATORS = {
    equals: 'es igual a',
    not_equals: 'no es igual a',
    contains: 'contiene',
    not_contains: 'no contiene',
    starts_with: 'empieza por',
    is_empty: 'está vacía',
    is_not_empty: 'tiene algo',
    greater_than: 'es mayor que',
    less_than: 'es menor que',
};

export const OPERATOR_OPTIONS = Object.entries(OPERATORS).map(([value, label]) => ({ value, label }));

export const WEEK_DAYS = [
    { value: 1, label: 'Lun' },
    { value: 2, label: 'Mar' },
    { value: 3, label: 'Mié' },
    { value: 4, label: 'Jue' },
    { value: 5, label: 'Vie' },
    { value: 6, label: 'Sáb' },
    { value: 7, label: 'Dom' },
];

function truncate(text, max = 140) {
    const clean = String(text ?? '').trim();
    return clean.length > max ? `${clean.slice(0, max - 1)}…` : clean;
}

function minutesLabel(minutes) {
    const m = Number(minutes) || 0;
    if (m >= 60) {
        const h = Math.floor(m / 60);
        const rest = m % 60;
        return rest ? `${h} h ${rest} min` : `${h} h`;
    }
    return `${m} min`;
}

/** Resumen de una línea para la tarjeta del lienzo. */
export function summaryOf(type, data = {}, catalog = {}) {
    switch (type) {
        case 'start': {
            const trigger = data.trigger ?? {};
            if (trigger.type === 'keyword') {
                const kws = Array.isArray(trigger.keywords) ? trigger.keywords : String(trigger.keywords ?? '').split(',');
                const list = kws.map((k) => String(k).trim()).filter(Boolean);
                return list.length ? `Cuando escriben: ${list.slice(0, 5).join(', ')}` : 'Cuando escriben una palabra clave (falta definirla)';
            }
            return trigger.idle_hours
                ? `Conversación nueva o reabierta, o tras ${trigger.idle_hours} h de silencio`
                : 'Conversación nueva o reabierta';
        }
        case 'message':
        case 'menu':
        case 'question':
            return truncate(data.text) || 'Sin texto todavía';
        case 'condition': {
            const rules = data.rules ?? [];
            if (!rules.length) return 'Sin reglas';
            const first = rules[0];
            let text = '';
            if (first.kind === 'variable') text = `{{${first.variable || '?'}}} ${OPERATORS[first.operator] ?? '?'} ${['is_empty', 'is_not_empty'].includes(first.operator) ? '' : `«${first.value ?? ''}»`}`;
            if (first.kind === 'label') {
                const name = (catalog.labels ?? []).find((l) => l.id === Number(first.label_id))?.name ?? '?';
                text = `${first.operator === 'not_has' ? 'No tiene' : 'Tiene'} la etiqueta «${name}»`;
            }
            if (first.kind === 'schedule') {
                const days = (first.days ?? []).map((d) => WEEK_DAYS.find((w) => w.value === Number(d))?.label).filter(Boolean);
                text = `Horario ${days.join(', ') || 'sin días'} · ${first.start ?? '?'}–${first.end ?? '?'}`;
            }
            const more = rules.length > 1 ? ` (+${rules.length - 1} ${data.match === 'any' ? 'o' : 'y'})` : '';
            return truncate(text + more);
        }
        case 'ispwatch':
            return 'Busca por el teléfono del contacto: saldo, facturas y estado del servicio';
        case 'tag': {
            const name = (catalog.labels ?? []).find((l) => l.id === Number(data.label_id))?.name;
            if (!name) return 'Elige una etiqueta';
            return `${data.action === 'remove' ? 'Quita' : 'Pone'} «${name}»`;
        }
        case 'wait':
            return `Espera ${minutesLabel(data.minutes)}`;
        case 'handoff': {
            const team = (catalog.teams ?? []).find((t) => t.id === Number(data.team_id))?.name;
            const parts = [];
            if (team) parts.push(`Equipo ${team}`);
            parts.push(data.assign === 'none' ? 'queda en la bandeja' : 'auto-asignación');
            return `${truncate(data.text, 80) || 'Sin mensaje'} · ${parts.join(' · ')}`;
        }
        case 'end':
            return 'El flujo termina; la conversación queda en la bandeja';
        default:
            return '';
    }
}

/** Por qué terminó una ejecución, en palabras del admin. */
export const ENDED_REASONS = {
    completed: 'Terminó en un bloque Fin',
    handoff: 'Pasó a un asesor',
    assigned: 'Un asesor tomó la conversación',
    human_replied: 'Un asesor le escribió al cliente',
    flow_deactivated: 'Se apagó el flujo',
    input_timeout: 'El cliente dejó de responder',
    window_closed: 'Ventana de 24 h cerrada: no se envió',
    send_failed: 'WhatsApp no aceptó un envío',
    step_limit: 'Tope de pasos (posible ciclo)',
    dead_end: 'Llegó a una salida sin conectar',
    error: 'Error interno',
    stalled: 'La cola no procesó el arranque',
    missing_version: 'La versión ya no existe',
    missing_node: 'Un bloque ya no existe',
    conversation_missing: 'La conversación se borró',
};

export const RUN_STATUS = {
    pending: { label: 'Arrancando', class: 'bg-sky-100 text-sky-700' },
    waiting_input: { label: 'Esperando al cliente', class: 'bg-sky-100 text-sky-700' },
    waiting_timer: { label: 'En una espera', class: 'bg-slate-100 text-slate-700' },
    completed: { label: 'Resuelta por el bot', class: 'bg-emerald-100 text-emerald-700' },
    handed_off: { label: 'Pasó al equipo', class: 'bg-orange-100 text-orange-700' },
    cancelled: { label: 'Cortada', class: 'bg-gray-100 text-gray-600' },
    failed: { label: 'Falló', class: 'bg-red-100 text-red-700' },
};

/** Lo que hizo un bloque, en una frase. */
export function outcomeLabel(step) {
    const outcome = step.outcome ?? '';
    if (outcome.startsWith('opt_')) return `Eligió «${step.detail?.opcion ?? outcome.slice(4)}»`;
    return {
        next: 'Siguió',
        waiting_input: 'Esperando respuesta',
        waiting_timer: 'En espera',
        no_match: 'No entendió',
        invalid: 'Respuesta no válida',
        true: 'Sí',
        false: 'No',
        found: 'Cliente encontrado',
        not_found: 'Cliente no encontrado',
        completed: 'Fin',
        handoff: 'Traspaso al equipo',
    }[outcome] ?? (ENDED_REASONS[outcome] ?? outcome);
}
