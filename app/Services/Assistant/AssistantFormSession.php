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
}
