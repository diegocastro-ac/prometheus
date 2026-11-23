<?php

namespace App\Filament\Resources\RentalResource\Pages;

use App\Filament\Concerns\AssistantTableSearch;
use App\Filament\Resources\RentalResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRentals extends ListRecords
{
    use AssistantTableSearch;

    protected static string $resource = RentalResource::class;

    public function bootedInteractsWithTable(): void
    {
        $this->applyAssistantTableSearch();
        parent::bootedInteractsWithTable();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
