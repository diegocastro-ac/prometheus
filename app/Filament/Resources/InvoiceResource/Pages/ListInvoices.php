<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Pages\DocumentsPage;
use App\Filament\Resources\InvoiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

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