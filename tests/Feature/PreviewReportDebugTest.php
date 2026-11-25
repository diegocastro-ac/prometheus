<?php

namespace Tests\Feature;

use App\Livewire\AssistantChat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PreviewReportDebugTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_report_renderiza(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(AssistantChat::class)
            ->set('messages', [
                ['role' => 'user', 'text' => 'analisis', 'data' => [], 'at' => now()->toIso8601String()],
                ['role' => 'assistant', 'text' => 'reporte', 'data' => ['report' => [
                    'title' => 'Analisis', 'intro' => 'Intro',
                    'sections' => [
                        ['title' => 'Portafolio', 'blocks' => [
                            ['type' => 'metrics', 'items' => [['label' => 'Mora', 'value' => '$1', 'tone' => 'danger']]],
                            ['type' => 'table', 'title' => 'T', 'columns' => ['A', 'B'], 'rows' => [['x', 'y']]],
                            ['type' => 'chart', 'chart' => 'bar', 'title' => 'C', 'labels' => ['a'], 'values' => [1]],
                            ['type' => 'steps', 'items' => [['title' => 'P', 'text' => 't', 'action' => ['kind' => 'url', 'target' => 'invoices', 'label' => 'Ir', 'url' => 'invoices']]]],
                            ['type' => 'callouts', 'items' => [['title' => 'Al', 'text' => 'x', 'tone' => 'warning']]],
                            ['type' => 'list', 'items' => ['uno', 'dos']],
                            ['type' => 'text', 'text' => '**bold**'],
                        ]],
                    ],
                ]], 'at' => now()->toIso8601String()],
            ])
            ->call('previewReport', 1)
            ->assertSet('preview.kind', 'report')
            ->assertSee('Portafolio');
    }
}
