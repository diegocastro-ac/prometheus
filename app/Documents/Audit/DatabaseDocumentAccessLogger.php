<?php

namespace App\Documents\Audit;

use App\Contracts\DocumentAccessLogger;
use App\Documents\DocumentDescriptor;
use App\Models\DocumentAccess;

/**
 * Persiste el acceso efectivo al contenido de un documento.
 */
class DatabaseDocumentAccessLogger implements DocumentAccessLogger
{
    public function log(DocumentDescriptor $descriptor): void
    {
        DocumentAccess::create([
            'user_id' => $descriptor->userId,
            'document_type' => $descriptor->type,
            'reference' => $descriptor->reference,
            'format' => $descriptor->format,
            'context' => $descriptor->context,
            'accessed_at' => now(),
        ]);
    }
}
