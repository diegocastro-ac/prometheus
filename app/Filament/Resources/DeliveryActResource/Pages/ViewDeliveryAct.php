<?php

namespace App\Filament\Resources\DeliveryActResource\Pages;

use App\Filament\Resources\DeliveryActResource;
use App\Models\DeliveryAct;
use App\Services\Acts\DeliveryActInventoryPresenter;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewDeliveryAct extends ViewRecord
{
    protected static string $resource = DeliveryActResource::class;

    protected static string $view = 'filament.resources.delivery-act-resource.pages.view-delivery-act';

    public function getTitle(): string|Htmlable
    {
        /** @var DeliveryAct $record */
        $record = $this->getRecord();

        return __('act.view.title', ['tenant' => $record->tenant_name]);
    }

    public function inventory(): DeliveryActInventoryPresenter
    {
        /** @var DeliveryAct $record */
        $record = $this->getRecord();

        return new DeliveryActInventoryPresenter($record);
    }
}
