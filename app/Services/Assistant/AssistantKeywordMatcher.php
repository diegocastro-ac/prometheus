<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

/**
 * Clasificador local de respaldo/fast-path para las preguntas mas comunes.
 *
 * Gemini entiende matices, pero para frases directas ("mis facturas",
 * "cuánto me deben", "mis inquilinos") una lista determinista de patrones
 * es mas rapida, mas barata y no depende de que la API este disponible.
 *
 * El orden de las reglas importa: primero las intenciones especificas
 * (vencidas, recordatorios, acciones) y al final las genericas (listados,
 * portafolio, saludo). Devuelve null cuando nada aplica con confianza:
 * ahi entra Gemini.
 */
class AssistantKeywordMatcher
{
    /**
     * Pregunta original con su capitalizacion: los nombres propios que se
     * extraen para params deben conservar mayusculas ("María", no "maría").
     */
    private ?string $source = null;

    /**
     * @return array{intent: string, params: array<string, mixed>}|null
     */
    public function match(string $question): ?array
    {
        $this->source = trim($question);
        $q = Str::lower($this->source);

        if ($q === '') {
            return null;
        }

        return $this->matchDraft($q)
            ?? $this->matchActions($q)
            ?? $this->matchOverdue($q)
            ?? $this->matchBalance($q)
            ?? $this->matchIncome($q)
            ?? $this->matchAnalysis($q)
            ?? $this->matchAdjustments($q)
            ?? $this->matchContracts($q)
            ?? $this->matchPortfolio($q)
            ?? $this->matchCreates($q)
            ?? $this->matchActs($q)
            ?? $this->matchNavigate($q)
            ?? $this->matchLists($q)
            ?? $this->matchGreeting($q);
    }

    /**
     * @return array{intent: string, params: array<string, mixed>}|null
     */
    private function matchDraft(string $q): ?array
    {
        $reminder = $this->hasAny($q, ['recordatorio', 'recordar', 'reminder', 'collect', 'payment reminder', 'mensaje de cobro', 'carta de cobro'])
            || ($this->hasAny($q, ['redact', 'escrib', 'write', 'draft']) && $this->hasAny($q, ['cobro', 'pago', 'debe', 'deben', 'saldo']));
        $notice = $this->hasAny($q, ['aviso', 'notificacion', 'notificación', 'carta', 'notice', 'notification'])
            && $this->hasAny($q, ['incremento', 'reajuste', 'canon', 'arriendo', 'rent']);

        if (! $reminder && ! $notice) {
            return null;
        }

        $params = [];
        $name = $this->extractAfter($q, '/\b(?:para|for)\s+(?:el|la|los|las|the)\s+(.+?)(?:[.?!]|$)/u');

        if ($name !== null) {
            $params['rental_name'] = $name;
        }

        return ['intent' => $notice ? 'draft_notice' : 'draft_reminder', 'params' => $params];
    }

    /**
     * @return array{intent: string, params: array<string, mixed>}|null
     */
    private function matchActions(string $q): ?array
    {
        if ($this->hasAny($q, ['genera', 'generar', 'genera las', 'crear', 'cree', 'haz las', 'hacer', 'saca', 'emitir', 'generate', 'create', 'issue'])
            && $this->hasAny($q, ['factura', 'facturas', 'invoice', 'invoices'])) {
            return ['intent' => 'propose_generate_invoices', 'params' => $this->extractPeriod($q)];
        }

        $paid = $this->hasAny($q, ['pagué', 'pague', 'pagó', 'aboné', 'abone', 'abono', 'registr', 'anota', 'anotar', 'paid', 'register', 'record']);

        if ($paid && $this->hasAny($q, ['factura', 'invoice', 'pago', 'payment'])) {
            return ['intent' => 'propose_record_payment', 'params' => $this->extractPaymentParams($q)];
        }

        return null;
    }

    private function matchOverdue(string $q): ?array
    {
        if (! $this->hasAny($q, ['vencid', 'vencieron', 'vencen', 'overdue', 'en mora', 'morosos', 'atrasad', 'late', 'past due'])) {
            return null;
        }

        // "Llevame a la seccion de facturas vencidas" pide la PANTALLA
        // filtrada, no solo el listado: se navega con el filtro aplicado.
        if ($this->hasAny($q, ['llévame', 'llevame', 'ir a', 'sección', 'seccion', 'pantalla', 'page', 'open', 'go to', 'muéstrame la', 'muestrame la'])
            && $this->hasAny($q, ['factura', 'invoice'])) {
            return ['intent' => 'navigate', 'params' => ['target' => 'invoices', 'filter' => 'VENCIDA']];
        }

        return ['intent' => 'overdue_invoices', 'params' => []];
    }

    private function matchBalance(string $q): ?array
    {
        $match = $this->hasAny($q, [
            'deben', 'deuda', 'deudas', 'saldo', 'pendiente', 'por cobrar',
            'owe', 'owed', 'balance', 'outstanding', 'estado de cuenta',
            'statement', 'cuánto me deben', 'cuanto me deben',
        ]);

        if (! $match) {
            return null;
        }

        $params = [];
        $tenant = $this->extractOwedTenant($q);

        if ($tenant !== null) {
            $params['tenant'] = $tenant;
        }

        return ['intent' => 'pending_balance', 'params' => $params];
    }

    private function matchIncome(string $q): ?array
    {
        return $this->hasAny($q, ['recaud', 'ingres', 'income', 'cuánto cobré', 'cuanto cobre', 'cuánto facturé', 'cuanto facture', 'ventas', 'revenue', 'how much did i earn'])
            ? ['intent' => 'income_period', 'params' => $this->extractPeriod($q)]
            : null;
    }

    private function matchAnalysis(string $q): ?array
    {
        return $this->hasAny($q, ['analiza', 'analizar', 'análisis', 'analisis', 'finanza', 'métricas', 'metricas', 'trends', 'tendencia', 'salud financiera', 'financial'])
            ? ['intent' => 'financial_analysis', 'params' => []]
            : null;
    }

    private function matchAdjustments(string $q): ?array
    {
        return $this->hasAny($q, ['reajuste', 'reajustes', 'ajuste', 'ajustes', 'incremento', 'incrementos', 'ipc', 'subida', 'adjustment', 'increase'])
            ? ['intent' => 'next_adjustments', 'params' => []]
            : null;
    }

    private function matchPortfolio(string $q): ?array
    {
        return $this->hasAny($q, ['portafolio', 'portfolio', 'resumen', 'cómo va', 'como va', 'estado general', 'overview', 'negocio', 'business'])
            ? ['intent' => 'portfolio_summary', 'params' => []]
            : null;
    }

    private function matchContracts(string $q): ?array
    {
        return $this->hasAny($q, ['contrato', 'contratos', 'contract'])
            && $this->hasAny($q, ['vence', 'vencen', 'termina', 'finaliza', 'caduca', 'expira', 'ends', 'expires', 'renew'])
            ? ['intent' => 'expiring_contracts', 'params' => []]
            : null;
    }

    private function matchLists(string $q): ?array
    {
        $map = [
            ['inquilino', 'inquilinos', 'tenant', 'arrendatario', 'arrendatarios', 'list_tenants'],
            ['propiedad', 'propiedades', 'inmueble', 'inmuebles', 'property', 'properties', 'list_properties'],
            ['arriendo', 'arriendos', 'alquiler', 'alquileres', 'rental', 'rentals', 'list_rentals'],
            ['factura', 'facturas', 'invoice', 'invoices', 'list_invoices'],
            ['pago', 'pagos', 'payment', 'payments', 'abono', 'abonos', 'list_payments'],
        ];

        foreach ($map as $rule) {
            $intent = array_pop($rule);

            if ($this->hasAny($q, $rule)) {
                return ['intent' => $intent, 'params' => []];
            }
        }

        return null;
    }

    /**
     * Intenciones de creacion: "agrega un inquilino", "agenda un arriendo",
     * "nueva propiedad". Van antes de los listados para que "crea un
     * inquilino" no caiga en list_tenants.
     */
    private function matchCreates(string $q): ?array
    {
        $verb = ['agrega', 'agregar', 'añade', 'anade', 'crea', 'crear', 'nuevo', 'nueva',
            'registra', 'registrar', 'agenda', 'agendar', 'arrenda', 'arrendar',
            'alquila', 'alquilar', 'aparta', 'aparta', 'add', 'create', 'new',
            'register', 'schedule', 'rent', 'set up'];

        if (! $this->hasAny($q, $verb)) {
            // Respuesta de seguimiento a "¿cómo se llama?": el usuario suelta
            // solo los datos ("Juan Pérez CC 123456 tel 300123") sin repetir
            // el verbo. Se reconoce por la combinacion documento + telefono.
            // Solo aplica cuando NO hay verbo de creacion, para no robar
            // mensajes como "agenda a María CC ... en el apto".
            if (preg_match('/\b(?:cc|cedula|cédula|documento)\b/iu', $q) === 1
                && preg_match('/\d{4,}/', $q) === 1
                && preg_match('/(?:tel|telefono|teléfono|celular|phone|whatsapp|\+?\d[\d\s().-]{7,})/iu', $q) === 1) {
                return ['intent' => 'create_tenant', 'params' => $this->extractEntityParams($q)];
            }

            return null;
        }

        $schedulable = ['agenda', 'agendar', 'arrenda', 'arrendar', 'alquila', 'alquilar',
            'aparta', 'schedule', 'rent', 'set up'];

        $rentalSignal = $this->hasAny($q, ['inquilino', 'inquilinos', 'tenant', 'arrendatario',
            'arriendo', 'arriendos', 'alquiler', 'alquileres', 'rental', 'rentals',
            'canon', 'precio', 'monto', 'cuota'])
            || preg_match('/\$\s?\d|\b\d+\s*(?:meses|months?)\b/iu', $q) === 1
            // "agenda a X en el apto Y": la estructura "a <nombre> en <lugar>"
            // ya delata un arriendo aunque no diga la palabra.
            || preg_match('/\b(?:a|para)\s+\S.*?\s+en\s+(?:el|la|un|una)\b/iu', $q) === 1;

        // "agenda/arrenda un inquilino" casi siempre es un arriendo nuevo;
        // los verbos de alta simple (agrega, crea, registra) con "inquilino"
        // son el inquilino mismo.
        if ($this->hasAny($q, $schedulable) && $rentalSignal) {
            return ['intent' => 'create_rental', 'params' => $this->extractRentalParams($q)];
        }

        if ($this->hasAny($q, ['arriendo', 'arriendos', 'alquiler', 'alquileres', 'rental', 'rentals', 'canon', 'precio', 'monto', 'cuota'])
            && $this->hasAny($q, ['inquilino', 'inquilinos', 'tenant', 'arrendatario', 'propiedad', 'inmueble', 'apto', 'apartamento'])) {
            return ['intent' => 'create_rental', 'params' => $this->extractRentalParams($q)];
        }

        if ($this->hasAny($q, ['propiedad', 'propiedades', 'inmueble', 'inmuebles', 'property', 'properties', 'apto', 'apartamento', 'casa', 'local', 'oficina'])) {
            return ['intent' => 'create_property', 'params' => $this->extractEntityParams($q)];
        }

        if ($this->hasAny($q, ['inquilino', 'inquilinos', 'tenant', 'tenants', 'arrendatario', 'arrendatarios'])) {
            return ['intent' => 'create_tenant', 'params' => $this->extractEntityParams($q)];
        }

        return null;
    }

    /**
     * Actas de entrega e inventarios. Va antes de matchNavigate porque
     * "muéstrame mis actas" pide DATOS, no la pantalla; solo los verbos de
     * navegación explícitos ("llévame", "ir a", "sección") abren la sección.
     *
     * @return array{intent: string, params: array<string, mixed>}|null
     */
    private function matchActs(string $q): ?array
    {
        if (! $this->hasAny($q, ['acta', 'actas', 'entrega', 'entregas', 'recepción', 'recepcion', 'inventario', 'inventarios', 'delivery act'])) {
            return null;
        }

        // "crea un acta" no es un listado; lo resuelve Gemini o cae en unknown.
        if ($this->hasAny($q, ['crea', 'crear', 'agrega', 'agregar', 'nueva', 'nuevo', 'registra', 'add', 'create', 'new'])) {
            return null;
        }

        if ($this->hasAny($q, ['llévame', 'llevame', 'ir a', 'sección', 'seccion', 'pantalla', 'page', 'open', 'go to'])) {
            return ['intent' => 'navigate', 'params' => ['target' => 'delivery_acts']];
        }

        return ['intent' => 'list_delivery_acts', 'params' => []];
    }

    private function matchNavigate(string $q): ?array
    {
        if (! $this->hasAny($q, ['llévame', 'llevame', 'muéstrame', 'muestrame', 'muéstreme', 'muestreme', 'ir a', 'open', 'go to', 'sección', 'seccion', 'pantalla', 'section', 'page', 'show me'])) {
            return null;
        }

        $target = match (true) {
            $this->hasAny($q, ['factura', 'invoice']) => 'invoices',
            $this->hasAny($q, ['arriendo', 'alquiler', 'rental', 'contrato', 'contract']) => 'rentals',
            $this->hasAny($q, ['inquilino', 'tenant']) => 'tenants',
            $this->hasAny($q, ['propiedad', 'inmueble', 'property']) => 'properties',
            $this->hasAny($q, ['acta', 'entrega', 'act']) => 'delivery_acts',
            $this->hasAny($q, ['reajuste', 'ajuste', 'adjustment']) => 'adjustments',
            default => null,
        };

        if ($target === null) {
            return null;
        }

        // Filtro de estado pedido junto a la navegacion ("facturas
        // pagadas", "arriendos activos"): viaja como params.filter.
        $filter = match (true) {
            $this->hasAny($q, ['vencid', 'overdue', 'mora']) => 'VENCIDA',
            $this->hasAny($q, ['pagad', 'paid']) => 'PAGADA',
            $this->hasAny($q, ['anulad', 'cancelad']) => 'ANULADA',
            $this->hasAny($q, ['emitid']) => 'EMITIDA',
            $this->hasAny($q, ['activo', 'active']) => 'ACTIVO',
            $this->hasAny($q, ['inactivo', 'inactive']) => 'INACTIVO',
            default => null,
        };

        $params = ['target' => $target];

        if ($filter !== null) {
            $params['filter'] = $filter;
        }

        return ['intent' => 'navigate', 'params' => $params];
    }

    /**
     * Follow-up anaforico: "genera una tabla con los datos", "listalos",
     * "muéstramelos en tabla". El componente lo usa para repetir los datos
     * del ultimo mensaje del asistente sin perder el contexto.
     *
     * Solo aplica a mensajes cortos SIN entidad de dominio: si menciona
     * "facturas" o "inquilinos" es una pregunta nueva y va al flujo normal.
     */
    public function isDataFollowUp(string $question): bool
    {
        $q = Str::lower(trim($question));

        if ($q === '' || mb_strlen($q) > 90) {
            return false;
        }

        if (! $this->hasAny($q, ['tabla', 'table', 'listado', 'lista', 'datos', 'data', 'detall', 'muestr', 'muéstr', 'muest', 'show', 'dame', 'enumera', 'desglos', 'pivot'])) {
            return false;
        }

        return ! $this->hasAny($q, [
            'factura', 'invoice', 'inquilino', 'tenant', 'arrendatario',
            'arriendo', 'alquiler', 'rental', 'propiedad', 'inmueble', 'property',
            'pago', 'payment', 'abono', 'acta', 'entrega', 'reajuste', 'adjustment',
            'finanza', 'finance', 'saldo', 'deuda', 'balance', 'precio', 'canon',
            'contrato', 'contract', 'inventario', 'ipc', 'medidor',
        ]);
    }

    private function matchGreeting(string $q): ?array
    {
        return preg_match('/^(hola|buenas|buenos días|buenas tardes|buenas noches|hello|hi|hey|good (morning|afternoon|evening)|saludos)[!.\s]*$/iu', $q) === 1
            ? ['intent' => 'greeting', 'params' => []]
            : null;
    }

    // ---------------------------------------------------------------
    // Helpers de extraccion
    // ---------------------------------------------------------------

    /**
     * @param  list<string>  $needles
     */
    private function hasAny(string $q, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (Str::contains($q, Str::lower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function extractAfter(string $q, string $pattern): ?string
    {
        if (preg_match($pattern, $q, $m) !== 1) {
            return null;
        }

        $name = trim($m[1]);
        $name = preg_replace('/\s+(por favor|pls|please|porfa|gracias|thanks)\.?$/iu', '', $name) ?? $name;

        return $name === '' ? null : $name;
    }

    /**
     * En "cuánto debe María" el nombre va despues del verbo; se excluyen
     * palabras que no son nombres.
     */
    private function extractOwedTenant(string $q): ?string
    {
        $stop = ['pagar', 'estar', 'haber', 'tener', 'ser', 'yo', 'el', 'la', 'los', 'las', 'un', 'una', 'mucho', 'todo', 'nada'];

        if (preg_match('/\bdebe\s+(?:me\s+)?([a-záéíóúñ][\wáéíóúñ.\' -]{1,40})/iu', $q, $m) !== 1) {
            return null;
        }

        $name = trim($m[1]);
        $first = Str::lower(Str::before($name.' ', ' '));

        if (in_array($first, $stop, true)) {
            return null;
        }

        return $name;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPeriod(string $q): array
    {
        if (preg_match('/(20\d{2})-(0[1-9]|1[0-2])/', $q, $m) === 1) {
            return ['period' => $m[0]];
        }

        if ($this->hasAny($q, ['mes pasado', 'last month', 'anterior', 'previous'])) {
            return ['period' => 'last_month'];
        }

        if (preg_match('/\b(20\d{2})\b/', $q, $m) === 1) {
            return ['period' => $m[1]];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPaymentParams(string $q): array
    {
        $params = [];

        if (preg_match('/\bfactura\s+([a-z0-9][a-z0-9-]{2,})/iu', $q, $m) === 1
            || preg_match('/\bn[°ºo]\.?\s*([a-z0-9][a-z0-9-]{2,})/iu', $q, $m) === 1) {
            $params['invoice_number'] = $m[1];
        }

        if (preg_match('/\$\s?(\d[\d.,]*)/', $q, $m) === 1) {
            $params['amount'] = (float) str_replace(['.', ','], '', $m[1]);
        }

        if ($this->hasAny($q, ['transferencia', 'transfer', 'transferí', 'transferi'])) {
            $params['method'] = 'transferencia';
        } elseif ($this->hasAny($q, ['consignaci', 'deposit'])) {
            $params['method'] = 'consignacion';
        } else {
            $params['method'] = 'efectivo';
        }

        return $params;
    }

    // ---------------------------------------------------------------
    // Extraccion para intenciones de creacion
    // ---------------------------------------------------------------

    /**
     * Parametros de un alquiler nuevo: inquilino, propiedad, canon, duracion.
     * Todo es best-effort; lo que falte lo pide el invocador.
     *
     * @return array<string, mixed>
     */
    private function extractRentalParams(string $q): array
    {
        $params = [];

        $tenant = $this->captureAfter($q, '/\b(?:inquilino|inquilina|arrendatario|arrendataria|tenant)\s+(?:llamad[oa]\s+|de nombre\s+|para\s+(?:el|la)\s+)?([a-záéíóúñ][\wáéíóúñ.\' -]{1,50}?)(?=\s+(?:en|del|de la|por|con|para|hasta|durante|a|de|cc|documento|tel\w*)\b|[.,;!?]|$)/iu')
            // "agenda a María en el apto 301": el nombre va tras la preposicion.
            ?? $this->captureAfter($q, '/\b(?:a|para)\s+([a-záéíóúñ][\wáéíóúñ.\' -]{1,40}?)(?=\s+(?:en\s+(?:el|la|un|una)|cc|documento|tel\w*|con|por|para|de)\b|[.,;!?]|$)/iu');

        if ($tenant !== null) {
            $params['tenant_name'] = $tenant;
        }

        $property = $this->captureAfter($q, '/\b(?:en|en el|en la|del|de la|propiedad|inmueble|apto|apartamento|local|oficina)\s+(?:el|la|un|una|the)?\s*([a-záéíóúñ0-9][\wáéíóúñ.\' -]{0,50}?)(?=\s+(?:por|de|con|para|hasta|durante|a)\b|[.,;!?]|$)/iu');

        if ($property !== null) {
            $params['property_name'] = $property;
        }

        if (preg_match('/\$\s?(\d[\d.,]*)/', $q, $m) === 1
            || preg_match('/\bpor\s+(?:un\s+canon\s+de\s+)?(\d[\d.,]*)\s*(?:mil|pesos|cop)?\b/iu', $q, $m) === 1) {
            $params['amount'] = (float) str_replace(['.', ','], '', $m[1]);
        }

        if (preg_match('/\b(\d{1,3})\s*(?:meses|months?)\b/iu', $q, $m) === 1) {
            $params['months'] = (int) $m[1];
        }

        if ($this->hasAny($q, ['quincenal', 'fortnight', 'biweekly'])) {
            $params['cadence'] = 'QUINCENAL';
        } elseif ($this->hasAny($q, ['anticipo', 'advance', 'prepago', 'prepaid'])) {
            $params['cadence'] = 'ANTICIPO';
        } else {
            $params['cadence'] = 'MENSUAL';
        }

        // Si el inquilino no existe habra que crearlo: documento y telefono
        // viajan como tenant_document/tenant_phone.
        $this->extractContact($params, 'tenant_', $q);

        // La direccion de una propiedad nueva va explicita ("direccion en
        // la calle 5"): "en el apto 301" es el nombre, no la direccion.
        $propertyAddress = $this->captureAfter($q, '/\b(?:direccion|dirección|ubicad[oa])\s+(?:en\s+)?(.+?)(?=\s+(?:con|tel|documento|por)\b|[.,;!?]|$)/iu');

        if ($propertyAddress !== null) {
            $params['property_address'] = $propertyAddress;
        }

        return $params;
    }

    /**
     * Extrae documento y telefono sin confundirlos: primero se saca el
     * documento (lleva palabra clave) y se excluye del texto antes de buscar
     * el telefono, que si no podria tragar los digitos de la cedula.
     *
     * @param  array<string, mixed>  $params
     */
    private function extractContact(array &$params, string $prefix, string $q): void
    {
        $haystack = $this->source ?? $q;

        $documentMatch = null;

        if (preg_match('/\b(?:cc|cedula|cédula|documento|document)\s*[:#]?\s*([a-z0-9][a-z0-9.-]{2,})/iu', $haystack, $m) === 1) {
            $params[$prefix.'document'] = $m[1];
            $documentMatch = $m[0];
        }

        if (preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', $haystack, $m) === 1) {
            $params[$prefix === '' ? 'email' : $prefix.'email'] = $m[0];
        }

        $phoneHaystack = $documentMatch !== null ? str_replace($documentMatch, ' ', $haystack) : $haystack;

        // El telefono casi siempre va tras "tel/phone/celular"; el numero
        // suelto es el ultimo recurso y puede confundir con el documento.
        if (preg_match('/(?:tel|telefono|teléfono|celular|phone|whatsapp|cel)\s*[:#]?\s*(\+?\d[\d\s().-]{6,}\d)/iu', $phoneHaystack, $m) === 1
            || preg_match('/\+?\d[\d\s().-]{7,}\d/', $phoneHaystack, $m) === 1) {
            $params[$prefix.'phone'] = trim($m[1] ?? $m[0]);
        }
    }

    /**
     * Parametros de alta simple (inquilino o propiedad): nombre y datos de
     * contacto/direccion si aparecen.
     *
     * @return array<string, mixed>
     */
    private function extractEntityParams(string $q): array
    {
        $params = [];

        // El nombre suele ir tras "se llama/me llamo/llamado/para/nombre"
        // o tras la entidad ("inquilino Juan"). Se prueban los patrones en
        // orden y el primero que produzca un nombre valido gana: un patron
        // que captura basura ya no bloquea a los siguientes.
        $patterns = [
            '/\b(?:se llama|me llamo|llamad[oa]|de nombre|nombre|para\s+(?:el|la|un|una))\s+([a-záéíóúñ][\wáéíóúñ.\' -]{1,60}?)(?=\s+(?:con|tel\w*|documento|cc|cedula|cédula|correo|email|en|de|por|para)\b|[.,;!?]|$)/iu',
            '/\b(?:inquilino|inquilina|arrendatario|propiedad|inmueble|apto|apartamento|local|oficina)\s+(?:llamad[oa]\s+|de nombre\s+|se llama\s+|me llamo\s+)?([a-záéíóúñ0-9][\wáéíóúñ.\' -]{0,60}?)(?=\s+(?:con|tel\w*|documento|cc|cedula|cédula|correo|email|en|de|por|para)\b|[.,;!?]|$)/iu',
            // "Juan Pérez CC 123 tel 300": el nombre encabeza el mensaje.
            '/^\s*([a-záéíóúñ][\wáéíóúñ.\' -]{1,60}?)(?=\s+(?:cc|cedula|cédula|documento|tel\w*|correo|email|con|en|de|por|para)\b|[.,;!?]|$)/iu',
        ];

        foreach ($patterns as $pattern) {
            $candidate = $this->captureAfter($q, $pattern);

            // Un nombre nunca empieza con el verbo de la peticion:
            // "agrega un inquilino nuevo" no es un nombre.
            if ($candidate !== null
                && ! $this->isStopWord($candidate)
                && preg_match('/^(?:agrega\w*|añade\w*|anade\w*|crea\w*|registra\w*|agenda\w*|arrenda\w*|alquila\w*|aparta\w*|add|create|new|register|schedule|rent)\b/iu', $candidate) !== 1) {
                $params['name'] = $candidate;
                break;
            }
        }

        $this->extractContact($params, '', $q);

        $address = $this->captureAfter($q, '/\b(?:en|ubicad[oa] en|direccion|dirección)\s+(.+?)(?=\s+(?:con|tel|documento|por)\b|[.,;!?]|$)/iu');

        if ($address !== null) {
            $params['address'] = $address;
        }

        return $params;
    }

    /**
     * Captura el primer grupo del patron solo si el resultado no es una
     * palabra vacia ni un conector.
     */
    private function captureAfter(string $q, string $pattern): ?string
    {
        // Se captura sobre el texto original para conservar mayusculas;
        // los patrones llevan /iu asi que igual matchean el texto en
        // minusculas que se uso para clasificar.
        if (preg_match($pattern, $this->source ?? $q, $m) !== 1) {
            return null;
        }

        $value = trim($m[1]);
        $value = preg_replace('/\s+(?:por favor|pls|please|porfa|gracias|thanks)\.?$/iu', '', $value) ?? $value;

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<string>  $stop
     */
    private function isStopWord(string $value): bool
    {
        static $stop = ['un', 'una', 'el', 'la', 'los', 'las', 'al', 'del', 'de', 'en', 'con', 'por',
            'para', 'y', 'o', 'mi', 'mis', 'su', 'sus', 'tu', 'tus', 'este', 'esta', 'ese', 'esa',
            'nuevo', 'nueva', 'nuevo/a', 'favor', 'porfa', 'please', 'pls', 'gracias', 'que', 'se',
            'te', 'me', 'lo', 'le', 'les', 'hay', 'tiene', 'tener', 'ser', 'es', 'son'];

        $first = trim(Str::lower(Str::before(trim($value).' ', ' ')), '.,;:!?"\'');

        return in_array($first, $stop, true) || mb_strlen($value) < 2;
    }
}
