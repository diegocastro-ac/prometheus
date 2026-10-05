<?php

namespace App\Filament\Resources\DeliveryActResource\Pages;

use App\Filament\Resources\DeliveryActResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDeliveryActs extends ListRecords
{
    protected static string $resource = DeliveryActResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
