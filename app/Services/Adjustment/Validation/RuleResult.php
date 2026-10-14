<?php

namespace App\Services\Adjustment\Validation;

/**
 * El resultado de una comprobacion.
 *
 * Tres gravedades y no dos porque el sistema no bloquea, pero tampoco trata todo
 * igual:
 *
 *   Bloqueo    contradice el articulo 20 y seguir seria una conducta que la ley no autoriza.
 *   Aviso      conviene que el arrendador lo sepa antes de aplicar.
 *   Informativo  es contexto, no un problema.
 *
 * El bloqueante existe para el caso de un IPC inexistente, que no es una
 * discusion juridica sino un dato que falta: no se puede emitir una carta que
 * cita una cifra que no existe.
 */
final readonly class RuleResult
{
    private function __construct(
        public string $severity,
        public string $message,
        public ?string $article = null,
        public array $context = [],
    ) {}

    public static function passes(?string $article = null): self
    {
        return new self('info', '', $article);
    }

    /**
     * Contradice la ley. No se puede seguir sin resolverlo.
     */
    public static function blocking(string $message, ?string $article = null, array $context = []): self
    {
        return new self('blocking', $message, $article, $context);
    }

    /**
     * Conviene avisar, pero el arrendador puede continuar.
     */
    public static function warning(string $message, ?string $article = null, array $context = []): self
    {
        return new self('warning', $message, $article, $context);
    }

    /**
     * Solo contexto para mostrar.
     */
    public static function info(string $message, ?string $article = null, array $context = []): self
    {
        return new self('info', $message, $article, $context);
    }

    public function isBlocking(): bool
    {
        return $this->severity === 'blocking';
    }

    public function isWarning(): bool
    {
        return $this->severity === 'warning';
    }

    public function hasMessage(): bool
    {
        return $this->message !== '';
    }

    /**
     * Todas las comprobaciones de un reajuste en una sola llamada.
     */
    public static function summary(array $results): self
    {
        $blocking = array_values(array_filter(
            $results,
            fn (self $result): bool => $result->isBlocking(),
        ));

        if ($blocking !== []) {
            return new self(
                'blocking',
                implode(' ', array_map(fn (self $r): string => $r->message, $blocking)),
                $blocking[0]->article,
                ['blocking' => count($blocking)],
            );
        }

        $warnings = array_values(array_filter(
            $results,
            fn (self $result): bool => $result->isWarning(),
        ));

        if ($warnings !== []) {
            return new self(
                'warning',
                implode(' ', array_map(fn (self $r): string => $r->message, $warnings)),
                $warnings[0]->article,
                ['warnings' => count($warnings)],
            );
        }

        $messages = array_values(array_filter(
            $results,
            fn (self $result): bool => $result->hasMessage(),
        ));

        return new self(
            'info',
            implode(' ', array_map(fn (self $r): string => $r->message, $messages)),
            null,
        );
    }
}
