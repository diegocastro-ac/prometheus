<?php

namespace App\Services\Assistant;

/**
 * Borrador de un formulario guiado (wizard) del copiloto.
 *
 * Flujos como el acta de entrega necesitan varios turnos de chat: el
 * usuario suelta un dato por mensaje y el asistente pregunta el siguiente
 * campo faltante. El estado vive en la sesion del servidor (no en BD) y
 * muere con ella; cada respuesta puede traer `pendingForm` para que el
 * chat pinte la barra de progreso.
 *
 * Estructura guardada: {form: string, data: array<string, mixed>}.
 * `data` acumula los campos ya respondidos; `step` se calcula al vuelo
 * segun el handler del form.
 */
class AssistantFormSession
{
    private const PREFIX = 'assistant.form.';

    /**
     * Formularios de CREACION (tenant/property/rental): a diferencia de
     * los wizards, NO interceptan los mensajes del chat — la IA sigue
     * clasificando texto libre y el formulario es una via paralela para
     * diligenciar campos con tipos correctos. Por eso vive en otra key.
     */
    private const CREATE_PREFIX = 'assistant.createform.';

    /**
     * Formulario activo del usuario: {form, data} o null si no hay wizard.
     *
     * @return array{form: string, data: array<string, mixed>}|null
     */
    public function get(int $userId): ?array
    {
        $session = session()->get(self::PREFIX.$userId);

        if (! is_array($session) || ! isset($session['form']) || ! is_string($session['form'])) {
            return null;
        }

        return [
            'form' => $session['form'],
            'data' => is_array($session['data'] ?? null) ? $session['data'] : [],
        ];
    }

    /**
     * Nombre del formulario activo (null si no hay wizard en curso).
     */
    public function form(int $userId): ?string
    {
        return $this->get($userId)['form'] ?? null;
    }

    /**
     * Datos acumulados del formulario activo.
     *
     * @return array<string, mixed>
     */
    public function data(int $userId): array
    {
        return $this->get($userId)['data'] ?? [];
    }

    /**
     * Reemplaza el borrador completo del formulario.
     *
     * @param  array<string, mixed>  $data
     */
    public function put(int $userId, string $form, array $data): void
    {
        session()->put(self::PREFIX.$userId, ['form' => $form, 'data' => $data]);
    }

    /**
     * Fusiona campos nuevos sobre lo ya acumulado (los nuevos ganan).
     *
     * @param  array<string, mixed>  $params
     */
    public function merge(int $userId, array $params): void
    {
        $session = $this->get($userId);

        if ($session === null) {
            return;
        }

        session()->put(self::PREFIX.$userId, [
            'form' => $session['form'],
            'data' => array_merge($session['data'], $params),
        ]);
    }

    public function clear(int $userId): void
    {
        session()->forget(self::PREFIX.$userId);
    }

    /**
     * Formulario de creacion abierto: {entity, values, queue?, collected?,
     * step?, total?} o null. `queue`/`collected` solo existen cuando el
     * form es parte de un batch de creaciones: cada entidad se diligencia
     * por turno y al final se propone UNA accion batch con todo.
     *
     * @return array{entity: string, values: array<string, mixed>, queue?: list<array{entity: string, params: array<string, mixed>}>, collected?: list<array<string, mixed>>, step?: int, total?: int}|null
     */
    public function getCreate(int $userId): ?array
    {
        $data = session()->get(self::CREATE_PREFIX.$userId);

        if (! is_array($data) || ! isset($data['entity']) || ! is_string($data['entity'])) {
            return null;
        }

        return [
            'entity' => $data['entity'],
            'values' => is_array($data['values'] ?? null) ? $data['values'] : [],
            'queue' => is_array($data['queue'] ?? null) ? $data['queue'] : [],
            'collected' => is_array($data['collected'] ?? null) ? $data['collected'] : [],
            'step' => isset($data['step']) ? (int) $data['step'] : null,
            'total' => isset($data['total']) ? (int) $data['total'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<array{entity: string, params: array<string, mixed>}>|null  $queue
     * @param  list<array<string, mixed>>|null  $collected
     */
    public function putCreate(int $userId, string $entity, array $values, ?array $queue = null, ?array $collected = null, ?int $step = null, ?int $total = null): void
    {
        session()->put(self::CREATE_PREFIX.$userId, array_filter([
            'entity' => $entity,
            'values' => $values,
            'queue' => $queue,
            'collected' => $collected,
            'step' => $step,
            'total' => $total,
        ], fn ($value) => $value !== null));
    }

    public function clearCreate(int $userId): void
    {
        session()->forget(self::CREATE_PREFIX.$userId);
    }
}
