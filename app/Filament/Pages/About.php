<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class About extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-information-circle';

    protected static string $view = 'filament.pages.about';

    protected static ?string $slug = 'information/about';

    protected static ?int $navigationSort = 100;

    public static function getNavigationGroup(): ?string
    {
        return __('about.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('about.menu_label');
    }

    public function getTitle(): string
    {
        return __('about.title');
    }
}
