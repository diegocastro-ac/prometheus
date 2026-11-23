<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Concerns\AssistantTableSearch;
use App\Filament\Resources\TenantResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageTenants extends ManageRecords
{
    use AssistantTableSearch;

    protected static string $resource = TenantResource::class;

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
