<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de un acceso efectivo al contenido de un documento.
 */
class DocumentAccess extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'document_type',
        'reference',
        'format',
        'context',
        'accessed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'accessed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
