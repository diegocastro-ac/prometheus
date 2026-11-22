<?php

namespace App\Infrastructure\Ai;

use App\Contracts\DataAssistantInterface;
use App\Dto\AssistantReply;

/**
 * Implementacion nula del copiloto: cuando no hay GEMINI_API_KEY configurada,
 * la pagina responde con un aviso en vez de romperse. Es el equivalente al
 * WeekendBusinessCalendar de las pruebas: el sistema sigue funcionando sin
 * la dependencia externa.
 */
class NullDataAssistant implements DataAssistantInterface
{
    public function ask(string $question, array $history, int $userId): AssistantReply
    {
        return AssistantReply::say(__('assistant.unavailable'));
    }

    public function title(string $firstMessage): ?string
    {
        return null;
    }

    public function draft(string $instruction, array $context): ?string
    {
        return null;
    }
}
