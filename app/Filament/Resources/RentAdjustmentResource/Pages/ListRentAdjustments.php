<?php

namespace App\Filament\Resources\RentAdjustmentResource\Pages;

use App\Filament\Resources\RentAdjustmentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRentAdjustments extends ListRecords
{
    protected static string $resource = RentAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}