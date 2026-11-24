<?php

namespace App\Contracts;

use App\Dto\AssistantReply;

/**
 * Puerto del copiloto del arrendador (Adapter).
 *
 * La implementacion real (GeminiDataAssistant) traduce la pregunta a una
 * intencion y unos parametros; quien la resuelve es AssistantToolInvoker con
 * servicios del sistema. Ninguna cifra sale de la IA: solo del dominio.
 *
 * Sin clave configurada se usa NullDataAssistant, que responde con un aviso
 * en lugar de fallar.
 */
interface DataAssistantInterface
{
    /**
     * Responde una pregunta del arrendador.
     *
     * @param  list<array{role: string, text: string}>  $history  turnos previos
     *                                                            (role: 'user'|'assistant'), del mas antiguo al mas reciente.
     * @param  (callable(): bool)|null  $abortCheck  si devuelve true, el pipeline se
     *                                                 detiene antes de cualquier efecto
     *                                                 lateral (sesiones de formulario,
     *                                                 propuestas) y responde cancelado.
     */
    public function ask(string $question, array $history, int $userId, ?callable $abortCheck = null): AssistantReply;

    /**
     * Genera un titulo corto para una conversacion a partir de su primer
     * mensaje. Devuelve null si el proveedor no esta disponible o falla.
     */
    public function title(string $firstMessage): ?string;

    /**
     * Redacta texto libre (recordatorios, avisos) con datos reales inyectados.
     * Devuelve null si el proveedor no esta disponible o falla.
     *
     * @param  array<string, mixed>  $context  datos reales que el texto debe usar.
     */
    public function draft(string $instruction, array $context): ?string;
}
