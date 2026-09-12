<?php

namespace App\Filament\Resources\RentalResource\Pages;

use App\Filament\Resources\RentalResource;
use App\ValueObjects\RentalPeriod;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

class EditRental extends EditRecord
{
    protected static string $resource = RentalResource::class;

    public static function getNavigationLabel(): string
    {
        return __('rental.navigation.pages.edit');
    }

    public function getTitle(): string | Htmlable
    {
        /** @var Rental */
        $record = $this->getRecord();

        return $record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $period = new RentalPeriod(Carbon::parse($data['start_date']), (int) $data['total_months']);
        $data['end_date'] = $period->endDateFormatted();
        return $data;
    }

    protected function afterSave(): void
    {
        $changes = $this->record->getChanges();
        $fieldsToCheck = ['start_date', 'end_date', 'total_months'];

        if (count(array_intersect(array_keys($changes), $fieldsToCheck)) > 0) {
            Notification::make()
                ->warning()
                ->title('Attention')
                ->body('The dates or months have changed, but the payment plan will NOT be automatically updated.')
                ->send();
        }
    }
}
