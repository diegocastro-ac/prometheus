<?php

namespace App\Filament\Resources\PropertyResource\Pages;

use App\Filament\Concerns\AssistantTableSearch;
use App\Filament\Resources\PropertyResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageProperties extends ManageRecords
{
    use AssistantTableSearch;

    protected static string $resource = PropertyResource::class;

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
