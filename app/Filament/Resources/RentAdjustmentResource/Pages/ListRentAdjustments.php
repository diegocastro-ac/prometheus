<?php

namespace App\Filament\Resources\RentAdjustmentResource\Pages;

use App\Filament\Concerns\AssistantTableSearch;
use App\Filament\Resources\RentAdjustmentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRentAdjustments extends ListRecords
{
    use AssistantTableSearch;

    protected static string $resource = RentAdjustmentResource::class;

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
