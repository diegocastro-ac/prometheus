<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un turno de la conversacion del copiloto, persistido por usuario.
 *
 * El payload JSON guarda lo que la respuesta trajo (tabla de datos, accion
 * propuesta, redirect, sugerencias) para que el chat se pueda reabrir y
 * retomar exactamente donde quedo, sin volver a llamar a la IA.
 */
class AssistantMessage extends Model
{
    protected $fillable = [
        'user_id',
        'chat_id',
        'role',
        'text',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(AssistantChat::class, 'chat_id');
    }
}
