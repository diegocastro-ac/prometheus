<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Contact extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static string $view = 'filament.pages.contact';

    protected static ?string $slug = 'information/contact';

    protected static ?int $navigationSort = 101;

    public static function getNavigationGroup(): ?string
    {
        return __('contact.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('contact.menu_label');
    }

    public function getTitle(): string
    {
        return __('contact.title');
    }
}
