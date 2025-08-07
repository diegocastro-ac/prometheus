<?php

namespace App\Filament\Resources\RentalResource\Pages;

use App\Filament\Resources\RentalResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewRental extends ViewRecord
{
    protected static string $resource = RentalResource::class;

    public function getTitle(): string | Htmlable
    {
        /** @var Rental */
        $record = $this->getRecord();

        return $record->name;
    }
}
