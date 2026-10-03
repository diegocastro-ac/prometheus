<?php

namespace Tests\Feature;

use App\Filament\Pages\AjustesPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AjustesPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function la_pagina_muestra_los_valores_guardados(): void
    {
        DB::table('app_settings')->insert([
            'id' => 1,
            'business_name' => 'Inmobiliaria Guardada S.A.S.',
            'tax_id' => '900111222-3',
            'currency' => 'COP',
            'invoice_due_days' => 7,
            'space_catalog' => json_encode(['Sala', 'Cocina']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs(User::factory()->create());

        Livewire::test(AjustesPage::class)
            ->assertSuccessful()
            ->assertFormSet([
                'business_name' => 'Inmobiliaria Guardada S.A.S.',
                'tax_id' => '900111222-3',
                'invoice_due_days' => 7,
            ]);
    }

    #[Test]
    public function guardar_desde_la_pagina_persiste_y_limpia_la_instancia_unica(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(AjustesPage::class)
            ->fillForm([
                'business_name' => 'Inmobiliaria desde Pantalla S.A.S.',
                'tax_id' => '900444555-6',
                'address' => 'Avenida Siempre Viva 742',
                'currency' => 'COP',
                'invoice_due_days' => 3,
                'legal_footer' => 'Documento sin valor fiscal.',
                'space_catalog' => [
                    ['space' => 'Sala'],
                    ['space' => 'Cocina'],
                    ['space' => 'Terraza'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $row = DB::table('app_settings')->where('id', 1)->first();

        $this->assertNotNull($row);
        $this->assertSame('Inmobiliaria desde Pantalla S.A.S.', $row->business_name);
        $this->assertSame('900444555-6', $row->tax_id);
        $this->assertSame(3, (int) $row->invoice_due_days);
        $this->assertSame(
            ['Sala', 'Cocina', 'Terraza'],
            json_decode($row->space_catalog, true)
        );

        // La siguiente peticion debe releer la base de datos, no la instancia vieja.
        $this->assertSame('Inmobiliaria desde Pantalla S.A.S.', app(\App\Settings\AppSettings::class)->businessName());
    }

    #[Test]
    public function exige_razon_social_y_nit(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(AjustesPage::class)
            ->fillForm(['business_name' => '', 'tax_id' => ''])
            ->call('save')
            ->assertHasFormErrors(['business_name', 'tax_id']);
    }
}