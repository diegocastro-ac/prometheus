<?php

namespace App\Filament\Resources\DeliveryActResource\Pages;

use App\Filament\Concerns\AssistantTableSearch;
use App\Filament\Resources\DeliveryActResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDeliveryActs extends ListRecords
{
    use AssistantTableSearch;

    protected static string $resource = DeliveryActResource::class;

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
