<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Concerns\AssistantTableSearch;
use App\Filament\Pages\DocumentsPage;
use App\Filament\Resources\InvoiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    use AssistantTableSearch;

    protected static string $resource = InvoiceResource::class;

    public function bootedInteractsWithTable(): void
    {
        $this->applyAssistantTableSearch();
        parent::bootedInteractsWithTable();

        // Deep-link del copiloto: ?af[status]=VENCIDA aplica el filtro de
        // estado. Se hace aqui y no en mount() porque el form de filtros se
        // cachea y se llena en este hook; setear la propiedad antes hace que
        // el form la pise con su estado vacio. Solo se aceptan estados
        // reales del enum y nunca se pisa un filtro ya elegido por el usuario.
        $af = request()->query('af');
        $status = is_array($af) ? strtolower((string) ($af['status'] ?? '')) : '';
        $valid = array_column(InvoiceStatus::cases(), 'value');

        if ($status !== ''
            && in_array($status, $valid, true)
            && blank(data_get($this->tableFilters, 'status.value'))) {
            $state = ['status' => ['isActive' => true, 'value' => $status]];
            $this->tableFilters = $state;
            $this->getTableFiltersForm()->fill($state);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // El estado de cuenta y los otros documentos se arman en una
            // pantalla propia: dependen de un alquiler y un periodo, no de una
            // fila, y meterlos como acciones de esta tabla obligaria a
            // inventarse un formulario por cada formato.
            Actions\Action::make('documents')
                ->label(__('invoice.documents.title'))
                ->icon('heroicon-o-document-duplicate')
                ->url(fn (): string => DocumentsPage::getUrl()),
            Actions\CreateAction::make(),
        ];
    }
}
