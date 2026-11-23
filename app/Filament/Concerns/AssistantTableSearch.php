<?php

namespace App\Filament\Concerns;

/**
 * Deep-link de busqueda del asistente: ?tableSearch=termino.
 *
 * Filament v4 no expone tableSearch como #[Url]; este helper lo lee del
 * query string. Debe llamarse ANTES de parent::bootedInteractsWithTable()
 * en el override de cada pagina index: al quedar tableSearch con valor,
 * la restauracion desde sesion de InteractsWithTable se omite y la tabla
 * aterriza ya filtrada.
 */
trait AssistantTableSearch
{
    protected function applyAssistantTableSearch(): void
    {
        $search = request()->query('tableSearch');

        if (is_string($search) && trim($search) !== '') {
            $this->tableSearch = $search;
        }
    }
}
