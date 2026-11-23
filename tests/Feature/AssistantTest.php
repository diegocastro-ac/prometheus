<?php

namespace Tests\Feature;

use App\Contracts\DataAssistantInterface;
use App\Enums\InvoiceStatus;
use App\Infrastructure\Ai\NullDataAssistant;
use App\Livewire\AssistantChat;
use App\Models\AssistantMessage;
use App\Models\DeliveryAct;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\User;
use App\Services\Assistant\AssistantActionExecutor;
use App\Services\Assistant\AssistantToolInvoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El copiloto: la IA clasifica, el sistema responde con datos reales.
 *
 * Las pruebas no salen a la red: Http::fake devuelve la clasificacion que
 * Gemini habria dado y se verifica lo que el sistema hace con ella.
 */
class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Rental $rental;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('app_settings')->insert([
            'id' => 1,
            'business_name' => 'Inmobiliaria Ejemplo S.A.S.',
            'tax_id' => '900123456-7',
            'currency' => 'COP',
            'invoice_due_days' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = User::factory()->create();
        $this->rental = Rental::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Local norte',
            'monthly_amount' => 800000,
            'is_active' => true,
            'start_date' => today()->subMonths(2)->toDateString(),
            'total_months' => 12,
            // El factory calcula end_date con sus propias variables, no con
            // los atributos sobreescritos: hay que fijarlo a mano.
            'end_date' => today()->addMonths(6)->toDateString(),
        ]);
    }

    private function fakeGemini(array $payload): void
    {
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-test');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode($payload)]]]],
                ],
            ]),
        ]);
    }

    private function assistant(): DataAssistantInterface
    {
        return app(DataAssistantInterface::class);
    }

    // ---------------------------------------------------------------
    // Configuracion
    // ---------------------------------------------------------------

    #[Test]
    public function sin_key_y_sin_match_local_responde_no_configurado(): void
    {
        config()->set('services.gemini.key', null);

        $reply = app(DataAssistantInterface::class)->ask('xyzzy platano', [], $this->user->id);

        $this->assertStringContainsString('GEMINI_API_KEY', $reply->text);
    }

    #[Test]
    public function sin_key_las_consultas_locales_siguen_respondiendo(): void
    {
        config()->set('services.gemini.key', null);
        Http::fake();

        $reply = app(DataAssistantInterface::class)->ask('mis inquilinos', [], $this->user->id);

        $this->assertStringContainsString('inquilinos', $reply->text);
        Http::assertNothingSent();
    }

    #[Test]
    public function null_assistant_no_llama_a_la_red(): void
    {
        Http::fake();

        $reply = (new NullDataAssistant)->ask('hola', [], $this->user->id);

        $this->assertNotEmpty($reply->text);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // Resiliencia
    // ---------------------------------------------------------------

    #[Test]
    public function si_gemini_falla_el_matcher_local_sigue_respondiendo(): void
    {
        Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'number' => 'FV-2026-0001',
            'amount' => 800000,
            'status' => InvoiceStatus::EMITIDA,
            'issued_at' => today()->toDateString(),
            'due_at' => today()->addDays(5)->toDateString(),
            'period' => today()->format('Y-m'),
        ]);

        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-test');

        // API caida (red, TLS, cuota o 5xx): el chat no debe quedarse mudo
        // cuando la pregunta se puede resolver con el matcher local.
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('', 500),
        ]);

        $reply = $this->assistant()->ask('cuanto me deben', [], $this->user->id);

        $this->assertStringNotContainsString('No pude responder', $reply->text);
        $this->assertStringContainsString('$800.000', $reply->text);
    }

    #[Test]
    public function si_gemini_falla_y_no_hay_match_local_muestra_el_error(): void
    {
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-test');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('', 500),
        ]);

        $reply = $this->assistant()->ask('xyzzy platano', [], $this->user->id);

        $this->assertStringContainsString('No pude responder', $reply->text);
    }

    // ---------------------------------------------------------------
    // Dominio
    // ---------------------------------------------------------------

    #[Test]
    public function rechaza_preguntas_fuera_del_dominio(): void
    {
        $this->fakeGemini([
            'domain' => 'offtopic',
            'intent' => 'unknown',
            'params' => [],
            'confidence' => 0.99,
        ]);

        $reply = $this->assistant()->ask('quien gano el mundial', [], $this->user->id);

        $this->assertStringContainsString('arriendos', $reply->text);
    }

    #[Test]
    public function confidence_baja_cae_en_desconocido(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'pending_balance',
            'params' => [],
            'confidence' => 0.1,
        ]);

        $reply = $this->assistant()->ask('mmm algo de plata', [], $this->user->id);

        $this->assertStringContainsString('no sé cómo ayudarte', $reply->text);
    }

    // ---------------------------------------------------------------
    // Datos reales
    // ---------------------------------------------------------------

    #[Test]
    public function pending_balance_usa_datos_reales_del_usuario(): void
    {
        Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'number' => 'FV-2026-0001',
            'amount' => 800000,
            'status' => InvoiceStatus::EMITIDA,
            'issued_at' => today()->toDateString(),
            'due_at' => today()->addDays(5)->toDateString(),
            'period' => today()->format('Y-m'),
        ]);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'pending_balance',
            'params' => [],
            'confidence' => 0.95,
        ]);

        $reply = $this->assistant()->ask('cuanto me deben', [], $this->user->id);

        $this->assertStringContainsString('$800.000', $reply->text);
        $this->assertSame('Local norte', $reply->data['debts'][0]['rental']);
    }

    #[Test]
    public function el_digest_solo_incluye_al_dueno(): void
    {
        $other = User::factory()->create();
        Rental::factory()->create([
            'user_id' => $other->id,
            'name' => 'Local ajeno',
        ]);

        $captured = null;
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-test');

        Http::fake(function ($request) use (&$captured) {
            // La llamada del colorista de badges tambien va a Gemini;
            // el digest viaja en la de clasificacion.
            if (! str_contains($request->body(), 'diseno de interfaces')) {
                $captured = $request->body();
            }

            return Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'domain' => 'app', 'intent' => 'portfolio_summary', 'params' => [], 'confidence' => 0.9,
                ])]]]]],
            ]);
        });

        // Frase sin palabras clave del matcher: tiene que llegar a Gemini
        // para que el digest viaje en el cuerpo de la llamada.
        $this->assistant()->ask('dime algo interesante', [], $this->user->id);

        $this->assertStringContainsString('Local norte', $captured);
        $this->assertStringNotContainsString('Local ajeno', $captured);
    }

    #[Test]
    public function income_period_calcula_recaudo_real(): void
    {
        Payment::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'amount' => 400000,
            'date' => today()->toDateString(),
        ]);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'income_period',
            'params' => ['period' => 'this_month'],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('cuanto recaude este mes', [], $this->user->id);

        $this->assertSame(400000.0, $reply->data['income']);
    }

    // ---------------------------------------------------------------
    // Redaccion con IA
    // ---------------------------------------------------------------

    #[Test]
    public function draft_reminder_redacta_con_segunda_llamada(): void
    {
        Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'number' => 'FV-2026-0002',
            'amount' => 800000,
            'status' => InvoiceStatus::VENCIDA,
            'issued_at' => today()->subMonth()->toDateString(),
            'due_at' => today()->subDays(10)->toDateString(),
            'period' => today()->subMonth()->format('Y-m'),
        ]);

        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-test');

        // Con key TODO pasa por Gemini: primera llamada clasifica
        // (draft_reminder), segunda redacta el texto.
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;

            if ($calls === 1) {
                return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'domain' => 'app',
                    'intent' => 'draft_reminder',
                    'params' => ['rental_name' => 'local norte'],
                    'confidence' => 0.9,
                ])]]]]]]);
            }

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Mensaje redactado por IA']]]]]]);
        });

        $reply = $this->assistant()->ask('redactame un recordatorio para el local norte', [], $this->user->id);

        $this->assertSame('Mensaje redactado por IA', $reply->text);
        $this->assertSame(2, $calls);
    }

    #[Test]
    public function draft_reminder_sin_rental_pide_aclaracion(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'draft_reminder',
            'params' => [],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('redactame un recordatorio', [], $this->user->id);

        $this->assertStringContainsString('arriendo o inquilino', $reply->text);
        // Solo la llamada de clasificacion: sin rental no hay redaccion.
        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------------
    // Acciones con confirmacion
    // ---------------------------------------------------------------

    #[Test]
    public function propose_generate_invoices_devuelve_accion_sin_ejecutar(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'propose_generate_invoices',
            'params' => [],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('genera las facturas de este mes', [], $this->user->id);

        $this->assertSame('generate_invoices', $reply->action['type']);
        $this->assertSame(0, Invoice::count());
    }

    #[Test]
    public function generate_invoices_confirmado_crea_facturas(): void
    {
        $result = app(AssistantActionExecutor::class)->execute([
            'type' => 'generate_invoices',
            'params' => ['period' => today()->format('Y-m')],
        ], $this->user->id);

        $this->assertStringContainsString('crearon', $result->text);
        $this->assertSame(1, Invoice::where('rental_id', $this->rental->id)->count());
    }

    #[Test]
    public function generate_invoices_es_idempotente(): void
    {
        $executor = app(AssistantActionExecutor::class);
        $action = ['type' => 'generate_invoices', 'params' => ['period' => today()->format('Y-m')]];

        $executor->execute($action, $this->user->id);
        $executor->execute($action, $this->user->id);

        $this->assertSame(1, Invoice::where('rental_id', $this->rental->id)->count());
    }

    #[Test]
    public function propose_record_payment_y_confirmacion_registra_abono(): void
    {
        $invoice = Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'number' => 'FV-2026-7777',
            'amount' => 800000,
            'status' => InvoiceStatus::EMITIDA,
            'issued_at' => today()->toDateString(),
            'due_at' => today()->addDays(5)->toDateString(),
            'period' => today()->format('Y-m'),
        ]);

        $invoker = app(AssistantToolInvoker::class);

        $reply = $invoker->invoke('propose_record_payment', [
            'invoice_number' => '7777',
            'amount' => 800000,
        ], $this->user->id);

        $this->assertSame('record_payment', $reply->action['type']);
        $this->assertSame($invoice->id, $reply->action['params']['invoice_id']);

        $result = app(AssistantActionExecutor::class)->execute($reply->action, $this->user->id);

        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame(InvoiceStatus::PAGADA, $invoice->fresh()->status);
        $this->assertStringContainsString('Pago registrado', $result->text);
    }

    #[Test]
    public function record_payment_no_toca_facturas_ajenas(): void
    {
        $other = User::factory()->create();
        $foreignInvoice = Invoice::factory()->create([
            'user_id' => $other->id,
            'status' => InvoiceStatus::EMITIDA,
        ]);

        $result = app(AssistantActionExecutor::class)->execute([
            'type' => 'record_payment',
            'params' => [
                'invoice_id' => $foreignInvoice->id,
                'amount' => 100,
            ],
        ], $this->user->id);

        $this->assertSame(0, Payment::where('invoice_id', $foreignInvoice->id)->count());
        $this->assertStringContainsString('no está disponible', $result->text);
    }

    // ---------------------------------------------------------------
    // UI
    // ---------------------------------------------------------------

    #[Test]
    public function la_pantalla_envia_y_muestra_respuesta(): void
    {
        $this->actingAs($this->user);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'portfolio_summary',
            'params' => [],
            'confidence' => 0.9,
        ]);

        Livewire::test(AssistantChat::class)
            ->set('input', 'como va mi portafolio')
            ->call('send')
            ->assertSee('arriendo')
            ->assertSet('input', '');
    }

    #[Test]
    public function el_chat_persiste_la_conversacion_en_base_de_datos(): void
    {
        $this->actingAs($this->user);
        config()->set('services.gemini.key', null);

        Livewire::test(AssistantChat::class)
            ->set('input', 'hola')
            ->call('send')
            ->assertSee('copiloto');

        $this->assertSame(2, AssistantMessage::where('user_id', $this->user->id)->count());
        $this->assertSame(1, \App\Models\AssistantChat::where('user_id', $this->user->id)->count());

        // Un "remount" (cerrar y reabrir el drawer) recupera el historial:
        // los mensajes cargan perezosamente al abrir.
        Livewire::test(AssistantChat::class)
            ->call('toggle')
            ->assertSee('copiloto')
            ->assertSee('hola');
    }

    #[Test]
    public function delete_chat_borra_conversacion_y_mensajes(): void
    {
        $this->actingAs($this->user);
        config()->set('services.gemini.key', null);

        Livewire::test(AssistantChat::class)
            ->set('input', 'hola')
            ->call('send');

        $chatId = \App\Models\AssistantChat::where('user_id', $this->user->id)->value('id');

        $this->assertNotNull($chatId);

        Livewire::test(AssistantChat::class)
            ->call('deleteChat', $chatId)
            ->assertSet('messages', []);

        $this->assertSame(0, AssistantMessage::where('user_id', $this->user->id)->count());
        $this->assertSame(0, \App\Models\AssistantChat::where('user_id', $this->user->id)->count());
    }

    #[Test]
    public function cada_chat_guarda_sus_mensajes_separados(): void
    {
        $this->actingAs($this->user);
        config()->set('services.gemini.key', null);

        Livewire::test(AssistantChat::class)
            ->set('input', 'hola')
            ->call('send')
            ->call('newChat')
            ->set('input', 'mis inquilinos')
            ->call('send');

        $chats = \App\Models\AssistantChat::where('user_id', $this->user->id)->get();

        // Cada chat tiene su par user+assistant: 2 mensajes por conversacion.
        $this->assertCount(2, $chats);
        $this->assertSame(4, AssistantMessage::count());
        $this->assertSame(2, $chats[0]->messages()->count());
        $this->assertSame(2, $chats[1]->messages()->count());
        $this->assertNotSame($chats[0]->id, $chats[1]->id);
    }

    #[Test]
    public function las_sugerencias_y_los_listados_funcionan_sin_llamar_a_la_api(): void
    {
        $this->actingAs($this->user);
        config()->set('services.gemini.key', null);
        Http::fake();

        Livewire::test(AssistantChat::class)
            ->call('askSuggestion', 'mis inquilinos')
            ->assertSee('inquilinos')
            ->assertSee($this->rental->tenant->name);

        // Sin key no hay titulo que generar ni classify: cero HTTP.
        Http::assertNothingSent();
    }

    #[Test]
    public function crear_inquilino_propone_accion_y_confirma(): void
    {
        $this->actingAs($this->user);
        config()->set('services.gemini.key', null);

        Livewire::test(AssistantChat::class)
            ->set('input', 'agrega un inquilino llamado María Pérez CC 1234567890 teléfono 3001234567')
            ->call('send')
            ->assertSee('María Pérez')
            ->call('confirmAction', 1);

        $this->assertDatabaseHas('tenants', [
            'user_id' => $this->user->id,
            'name' => 'María Pérez',
            'phone_number' => '3001234567',
            'document' => '1234567890',
        ]);
    }

    #[Test]
    public function crear_inquilino_sin_nombre_pide_el_dato(): void
    {
        $this->actingAs($this->user);
        config()->set('services.gemini.key', null);

        Livewire::test(AssistantChat::class)
            ->set('input', 'agrega un inquilino nuevo')
            ->call('send')
            ->assertSee('nombre');

        // Solo existe el inquilino del arriendo del fixture: nada se creo.
        $this->assertSame(1, \App\Models\Tenant::where('user_id', $this->user->id)->count());
    }

    #[Test]
    public function agendar_arriendo_crea_inquilino_propiedad_y_arriendo(): void
    {
        $this->actingAs($this->user);
        config()->set('services.gemini.key', null);

        Livewire::test(AssistantChat::class)
            ->set('input', 'agenda a María López CC 99887766 en el apto 301 por $800.000 por 12 meses teléfono 3009998877, dirección Calle 50 #10-20')
            ->call('send')
            ->assertSee('María López')
            ->assertSee('apto 301')
            ->call('confirmAction', 1);

        $tenant = \App\Models\Tenant::where('user_id', $this->user->id)->where('name', 'María López')->first();
        $property = \App\Models\Property::where('user_id', $this->user->id)->where('name', 'apto 301')->first();

        $this->assertNotNull($tenant);
        $this->assertNotNull($property);
        $this->assertSame('Calle 50 #10-20', $property->address);
        $this->assertDatabaseHas('rentals', [
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'monthly_amount' => 800000,
            'total_months' => 12,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function agendar_arriendo_sin_canon_pide_el_dato(): void
    {
        $this->actingAs($this->user);
        config()->set('services.gemini.key', null);

        Livewire::test(AssistantChat::class)
            ->set('input', 'agenda a María en el apto 301')
            ->call('send')
            ->assertSee('canon');
    }

    // ---------------------------------------------------------------
    // Preview modal y navegacion con filtros
    // ---------------------------------------------------------------

    #[Test]
    public function preview_action_muestra_el_detalle_y_confirma(): void
    {
        $this->actingAs($this->user);
        config()->set('services.gemini.key', null);

        Livewire::test(AssistantChat::class)
            ->set('input', 'agrega un inquilino llamado Pedro Gómez documento 555 tel 3001112233')
            ->call('send')
            ->call('previewAction', 1)
            ->assertSet('preview.kind', 'action')
            ->assertSet('preview.action_index', 1)
            ->assertSee('Pedro Gómez')
            ->call('confirmPreview')
            ->assertSet('preview', null);

        $this->assertDatabaseHas('tenants', [
            'user_id' => $this->user->id,
            'name' => 'Pedro Gómez',
            'document' => '555',
        ]);
    }

    #[Test]
    public function preview_data_expande_la_tabla_completa(): void
    {
        $this->actingAs($this->user);
        config()->set('services.gemini.key', null);

        Livewire::test(AssistantChat::class)
            ->set('input', 'mis inquilinos')
            ->call('send')
            ->call('previewData', 1, 'tenants')
            ->assertSet('preview.kind', 'data')
            ->assertSee('Detalle');
    }

    #[Test]
    public function navegar_a_facturas_vencidas_lleva_con_filtro(): void
    {
        config()->set('services.gemini.key', null);

        $reply = $this->assistant()->ask('llévame a la sección de facturas vencidas', [], $this->user->id);

        $this->assertStringContainsString('af', $reply->redirect);
        $this->assertStringContainsString('vencida', $reply->redirect);
    }

    #[Test]
    public function navegar_con_filtro_no_soportado_muestra_los_datos(): void
    {
        config()->set('services.gemini.key', null);

        $reply = $this->assistant()->ask('muéstrame la sección de inquilinos activos', [], $this->user->id);

        $this->assertStringContainsString('no puedo abrir', \Illuminate\Support\Str::lower($reply->text));
        $this->assertNotEmpty($reply->data['tenants'] ?? null);
        $this->assertNotNull($reply->redirect);
    }

    #[Test]
    public function la_lista_de_facturas_aplica_el_filtro_de_la_url(): void
    {
        $this->actingAs($this->user);

        Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'status' => InvoiceStatus::VENCIDA,
            'due_at' => today()->subDays(3)->toDateString(),
        ]);
        Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'status' => InvoiceStatus::PAGADA,
        ]);

        Livewire::withQueryParams(['af' => ['status' => 'VENCIDA']])
            ->test(\App\Filament\Resources\InvoiceResource\Pages\ListInvoices::class)
            ->assertSet('tableFilters.status.value', 'vencida');
    }

    #[Test]
    public function manage_records_aplica_table_search_de_la_url(): void
    {
        $this->actingAs($this->user);

        Livewire::withQueryParams(['tableSearch' => 'camila'])
            ->test(\App\Filament\Resources\TenantResource\Pages\ManageTenants::class)
            ->assertSet('tableSearch', 'camila');
    }

    #[Test]
    public function las_actas_de_entrega_listan_datos_reales_e_inventario_relevante(): void
    {
        $this->actingAs($this->user);
        config()->set('services.gemini.key', null);

        $act = \App\Models\DeliveryAct::factory()->create([
            'user_id' => $this->user->id,
            'rental_id' => $this->rental->id,
            'tenant_name' => 'María Gómez',
        ]);
        \App\Models\ActItem::factory()->create([
            'delivery_act_id' => $act->id,
            'space' => 'Sala',
            'name' => 'Sofá',
            'state' => \App\Enums\ActItemState::DESTRUIDO,
        ]);
        \App\Models\ActItem::factory()->create([
            'delivery_act_id' => $act->id,
            'space' => 'Cocina',
            'name' => 'Nevera',
            'state' => \App\Enums\ActItemState::BUENO,
        ]);

        $reply = $this->assistant()->ask('mis actas de entrega y su inventario', [], $this->user->id);

        $this->assertNotNull($reply->data);
        $this->assertCount(1, $reply->data['acts']);
        $this->assertSame('María Gómez', $reply->data['acts'][0]['tenant']);
        $this->assertSame(2, $reply->data['acts'][0]['items']);

        // Solo el elemento en mal estado aparece como relevante.
        $this->assertCount(1, $reply->data['attention_items']);
        $this->assertSame('Sofá', $reply->data['attention_items'][0]['item']);
    }

    #[Test]
    public function pedir_la_tabla_como_followup_pasa_por_la_ia(): void
    {
        $this->actingAs($this->user);

        \App\Models\DeliveryAct::factory()->create([
            'user_id' => $this->user->id,
            'rental_id' => $this->rental->id,
            'tenant_name' => 'María Gómez',
        ]);

        // Gemini re-emite el intent de listado en el follow-up: los datos
        // llegan frescos de la base, no de un atajo local.
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'list_delivery_acts',
            'params' => [],
            'confidence' => 0.9,
        ]);

        Livewire::test(AssistantChat::class)
            ->set('input', 'mis actas de entrega')
            ->call('send')
            ->set('input', 'pero genera una tabla con los datos pls')
            ->call('send')
            ->assertSet('messages.3.data.acts.0.tenant', 'María Gómez');

        // Las dos preguntas fueron a Gemini: no hay atajo local. Cada
        // respuesta con tabla de estados dispara ademas la llamada del
        // colorista de badges: 2 classify + 2 colorist.
        Http::assertSentCount(4);
    }

    #[Test]
    public function navegacion_explicita_a_actas_sigue_llevando_a_la_seccion(): void
    {
        $this->actingAs($this->user);
        config()->set('services.gemini.key', null);

        $reply = $this->assistant()->ask('llévame a la sección de actas de entrega', [], $this->user->id);

        $this->assertStringContainsString('delivery-acts', (string) $reply->redirect);
    }

    // ---------------------------------------------------------------
    // Todo por la IA + alta de inquilino (bug "se llama X")
    // ---------------------------------------------------------------

    #[Test]
    public function con_key_las_preguntas_directas_tambien_clasifican_en_gemini(): void
    {
        $this->actingAs($this->user);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'list_tenants',
            'params' => [],
            'confidence' => 0.9,
        ]);

        // "mis inquilinos" antes la resolvia el matcher sin llamar a la API;
        // ahora pasa por Gemini como cualquier otra pregunta.
        $reply = $this->assistant()->ask('mis inquilinos', [], $this->user->id);

        $this->assertNotEmpty($reply->data['tenants'] ?? null);
        Http::assertSentCount(1);
    }

    #[Test]
    public function greeting_tambien_lo_interpreta_la_ia(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'greeting',
            'params' => [],
            'confidence' => 0.95,
        ]);

        $reply = $this->assistant()->ask('hola buenas', [], $this->user->id);

        $this->assertStringContainsString('copiloto', $reply->text);
    }

    #[Test]
    public function la_ia_propone_el_alta_de_inquilino_con_frase_natural(): void
    {
        $this->actingAs($this->user);

        // Frase exacta reportada: Gemini extrae nombre completo, documento,
        // telefono y correo; la ciudad se ignora porque Tenant no la tiene.
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'create_tenant',
            'params' => [
                'name' => 'Isaac David',
                'document' => '1067591312',
                'phone' => '3023018355',
                'email' => 'isaacjacome9@gmail.com',
            ],
            'confidence' => 0.95,
        ]);

        Livewire::test(AssistantChat::class)
            ->set('input', 'puedes registrar un inquilino pls, se llama Isaac David, cc 1067591312, tel 3023018355, correo isaacjacome9@gmail.com ciudad de valledupar')
            ->call('send')
            ->assertSee('Isaac David')
            ->call('confirmAction', 1);

        $this->assertDatabaseHas('tenants', [
            'user_id' => $this->user->id,
            'name' => 'Isaac David',
            'document' => '1067591312',
            'phone_number' => '3023018355',
            'email' => 'isaacjacome9@gmail.com',
        ]);
    }

    #[Test]
    public function sin_key_el_matcher_tambien_extrae_se_llama(): void
    {
        $this->actingAs($this->user);
        config()->set('services.gemini.key', null);

        // Regresion del bug reportado: el follow-up "se llama Isaac David,
        // cc ..." debe extraer el nombre completo aunque no haya IA.
        Livewire::test(AssistantChat::class)
            ->set('input', 'se llama Isaac David, cc 1067591312, tel 3023018355, correo isaacjacome9@gmail.com')
            ->call('send')
            ->assertSee('Isaac David')
            ->call('confirmAction', 1);

        $this->assertDatabaseHas('tenants', [
            'user_id' => $this->user->id,
            'name' => 'Isaac David',
            'document' => '1067591312',
            'phone_number' => '3023018355',
        ]);
    }

    #[Test]
    public function el_centro_de_ayuda_se_renderiza_con_sus_categorias(): void
    {
        $this->actingAs($this->user);

        Livewire::test(AssistantChat::class)
            ->assertSee('Centro de ayuda')
            ->assertSee('Cobros y cartera')
            ->assertSee('Navegación');
    }

    // ---------------------------------------------------------------
    // Batch: varias peticiones en un mensaje + perfil de cuenta
    // ---------------------------------------------------------------

    #[Test]
    public function batch_de_consultas_fusiona_datos_de_varias_entidades(): void
    {
        \App\Models\Property::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Apto 301',
        ]);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'unknown',
            'confidence' => 0.9,
            'requests' => [
                ['intent' => 'list_tenants', 'params' => []],
                ['intent' => 'list_properties', 'params' => []],
            ],
        ]);

        $reply = $this->assistant()->ask('muéstrame mis inquilinos y mis propiedades', [], $this->user->id);

        // Los dos conjuntos de datos viajan juntos, cada uno con su tabla.
        $this->assertArrayHasKey('tenants', $reply->data);
        $this->assertArrayHasKey('properties', $reply->data);
        $this->assertStringContainsString('inquilinos', \Illuminate\Support\Str::lower($reply->text));
        $this->assertStringContainsString('propiedades', \Illuminate\Support\Str::lower($reply->text));

        // Las secciones conservan el orden pedido: texto + tabla por entidad.
        $this->assertCount(2, $reply->sections);
        $this->assertArrayHasKey('tenants', $reply->sections[0]['data']);
        $this->assertArrayHasKey('properties', $reply->sections[1]['data']);
    }

    #[Test]
    public function batch_de_creacion_propone_un_paquete_con_preview_seccionado(): void
    {
        $this->actingAs($this->user);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'unknown',
            'confidence' => 0.9,
            'requests' => [
                ['intent' => 'create_tenant', 'params' => ['name' => 'Isaac David', 'document' => '1067591312', 'phone' => '3023018355']],
                ['intent' => 'create_tenant', 'params' => ['name' => 'María López', 'document' => '99887766', 'phone' => '3009998877']],
                ['intent' => 'create_property', 'params' => ['name' => 'Apto 301', 'address' => 'Calle 50 #10-20']],
            ],
        ]);

        Livewire::test(AssistantChat::class)
            ->set('input', 'registra estos 2 inquilinos y 1 propiedad: Isaac David cc 1067591312 tel 3023018355, María López cc 99887766 tel 3009998877, y el Apto 301 en Calle 50 #10-20')
            ->call('send')
            ->assertSee('Isaac David')
            ->call('previewAction', 1)
            ->assertSet('preview.kind', 'action')
            ->call('confirmPreview');

        $this->assertDatabaseHas('tenants', ['user_id' => $this->user->id, 'name' => 'Isaac David']);
        $this->assertDatabaseHas('tenants', ['user_id' => $this->user->id, 'name' => 'María López']);
        $this->assertDatabaseHas('properties', ['user_id' => $this->user->id, 'name' => 'Apto 301']);
    }

    #[Test]
    public function batch_complejo_con_entidades_pendientes_no_pide_datos_de_nuevo(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'unknown',
            'confidence' => 0.9,
            'requests' => [
                ['intent' => 'create_property', 'params' => ['name' => 'Apto 902', 'address' => 'Calle 80 #45-12']],
                ['intent' => 'create_tenant', 'params' => ['name' => 'Camila Torres', 'document' => '1020304050', 'phone_number' => '3112223344', 'email' => 'camila@mail.com']],
                ['intent' => 'create_rental', 'params' => [
                    'tenant_name' => 'Camila Torres',
                    'property_name' => 'Apto 902',
                    'monthly_amount' => 1200000,
                    'total_months' => 12,
                ]],
                ['intent' => 'propose_generate_invoices', 'params' => ['period' => '2026-11']],
            ],
        ]);

        $reply = $this->assistant()->ask(
            'registra la propiedad Apto 902 en Calle 80 #45-12, agenda un arriendo de 1.200.000 mensuales por 12 meses para el inquilino nuevo Camila Torres cc 1020304050 tel 3112223344 correo camila@mail.com, y de una vez genera las facturas del mes',
            [],
            $this->user->id,
        );

        // Una sola accion batch con las 4 operaciones, sin pedir datos otra vez.
        $this->assertSame('batch', $reply->action['type']);
        $this->assertCount(4, $reply->action['items']);
        $this->assertStringNotContainsString('missing', \Illuminate\Support\Str::lower($reply->text));

        // El arriendo viaja con todos sus parametros aunque la propiedad y
        // el inquilino se estan creando en el mismo batch.
        $rental = collect($reply->action['items'])->firstWhere('type', 'create_rental');
        $this->assertSame('Camila Torres', $rental['params']['tenant_name']);
        $this->assertSame('Apto 902', $rental['params']['property_name']);
        $this->assertSame(1200000.0, (float) $rental['params']['monthly_amount']);
        $this->assertSame(12, $rental['params']['total_months']);
    }

    #[Test]
    public function batch_sin_acciones_de_escritura_no_propone_tarjeta(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'list_tenants',
            'confidence' => 0.9,
            'requests' => [
                ['intent' => 'list_tenants', 'params' => []],
                ['intent' => 'list_properties', 'params' => []],
            ],
        ]);

        $reply = $this->assistant()->ask('inquilinos y propiedades', [], $this->user->id);

        // Sin acciones de escritura el batch no inventa tarjeta de confirmacion.
        $this->assertNull($reply->action);
    }

    #[Test]
    public function show_profile_devuelve_los_datos_de_la_cuenta(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'show_profile',
            'params' => [],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('¿qué correo tengo registrado?', [], $this->user->id);

        $this->assertSame($this->user->email, $reply->data['profile'][0]['email']);
        $this->assertSame($this->user->name, $reply->data['profile'][0]['name']);
    }

    #[Test]
    public function update_profile_confirma_y_guarda_los_cambios(): void
    {
        $this->actingAs($this->user);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'propose_update_profile',
            'params' => ['email' => 'nuevo@correo.com', 'phone_number' => '3001234567'],
            'confidence' => 0.9,
        ]);

        Livewire::test(AssistantChat::class)
            ->set('input', 'cambia mi correo a nuevo@correo.com y mi teléfono a 3001234567')
            ->call('send')
            ->assertSet('messages.1.action.type', 'update_profile')
            ->assertSet('messages.1.action.params.email', 'nuevo@correo.com')
            ->call('confirmAction', 1);

        $this->assertSame('nuevo@correo.com', $this->user->fresh()->email);
        $this->assertSame('3001234567', $this->user->fresh()->phone_number);
    }

    #[Test]
    public function si_el_modelo_principal_falla_reintenta_con_el_fallback(): void
    {
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-saturado');
        config()->set('services.gemini.fallback_model', 'gemini-respaldo');

        Http::fake([
            '*/gemini-saturado:generateContent' => Http::response(['error' => ['message' => 'high demand']], 503),
            '*/gemini-respaldo:generateContent' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode([
                        'domain' => 'app',
                        'intent' => 'portfolio_summary',
                        'confidence' => 0.9,
                    ])]]]],
                ],
            ]),
        ]);

        $reply = $this->assistant()->ask('¿cómo va mi portafolio?', [], $this->user->id);

        $this->assertNotSame('No pude responder en este momento. Intenta de nuevo en un momento.', $reply->text);

        // 2 del classify (saturado 503 -> respaldo 200) + 2 del colorista
        // de badges, que recorre los mismos candidatos.
        Http::assertSentCount(4);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'gemini-respaldo'));
    }

    #[Test]
    public function propiedades_con_actas_devuelven_datos_anidados(): void
    {
        DeliveryAct::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
        ]);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'list_properties_with_acts',
            'params' => [],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('dame las propiedades con las actas de cada una', [], $this->user->id);

        $properties = collect($reply->data['properties']);
        $row = $properties->firstWhere('name', $this->rental->property->name);

        $this->assertNotNull($row);
        $this->assertArrayHasKey('acts', $row);
        $this->assertCount(1, $row['acts']);
        $this->assertArrayHasKey('type', $row['acts'][0]);
        $this->assertArrayHasKey('signed', $row['acts'][0]);
    }

    #[Test]
    public function el_usuario_elige_el_modelo_y_gemini_lo_usa(): void
    {
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-config');
        $this->user->update(['assistant_model' => 'gemini-elegido']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode([
                        'domain' => 'app',
                        'intent' => 'portfolio_summary',
                        'confidence' => 0.9,
                    ])]]]],
                ],
            ]),
        ]);

        $this->assistant()->ask('¿cómo va mi portafolio?', [], $this->user->id);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'gemini-elegido'));
    }

    #[Test]
    public function el_selector_de_modelo_guarda_la_preferencia(): void
    {
        $this->actingAs($this->user);

        Livewire::test(AssistantChat::class)
            ->call('openDrawer')
            ->call('setAssistantModel', 'gemini-3.8-flash')
            ->assertSet('assistantModel', 'gemini-3.8-flash')
            ->call('setAssistantModel', 'modelo-inexistente')
            ->assertSet('assistantModel', 'gemini-3.8-flash');

        $this->assertSame('gemini-3.8-flash', $this->user->fresh()->assistant_model);
    }

    #[Test]
    public function preview_all_data_muestra_todas_las_tablas(): void
    {
        $this->actingAs($this->user);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'unknown',
            'confidence' => 0.9,
            'requests' => [
                ['intent' => 'list_tenants', 'params' => []],
                ['intent' => 'list_properties', 'params' => []],
            ],
        ]);

        Livewire::test(AssistantChat::class)
            ->set('input', 'muéstrame inquilinos y propiedades')
            ->call('send')
            ->assertSee('Ver todas las tablas')
            ->call('previewAllData', 1)
            ->assertSet('preview.kind', 'data_all')
            ->assertSet('preview.title', 'Todas las tablas');
    }

    #[Test]
    public function update_profile_rechaza_email_repetido(): void
    {
        $other = User::factory()->create(['email' => 'ocupado@correo.com']);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'propose_update_profile',
            'params' => ['email' => 'ocupado@correo.com'],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('cambia mi correo a ocupado@correo.com', [], $this->user->id);

        $result = app(AssistantActionExecutor::class)->execute($reply->action, $this->user->id);

        $this->assertStringContainsString('ya está registrado', $result->text);
        $this->assertNotSame('ocupado@correo.com', $this->user->fresh()->email);
    }

    // ---------------------------------------------------------------
    // Revision de interaccion: wizard, lifecycle local y diffs
    // ---------------------------------------------------------------

    #[Test]
    public function update_rental_desactiva_con_booleano_falso_real(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'update_rental',
            'params' => ['rental_name' => 'Local norte', 'is_active' => false],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('desactiva el arriendo de Local norte', [], $this->user->id);

        $this->assertSame('update_rental', $reply->action['type']);
        $this->assertFalse($reply->action['params']['is_active']);

        app(AssistantActionExecutor::class)->execute($reply->action, $this->user->id);

        $this->assertFalse((bool) $this->rental->fresh()->is_active);
    }

    #[Test]
    public function update_rental_normaliza_alias_de_cadencia(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'update_rental',
            'params' => ['rental_name' => 'Local norte', 'billing_cadence' => 'quincenal'],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('cambia la periodicidad del arriendo a quincenal', [], $this->user->id);

        $this->assertSame('QUINCENAL', $reply->action['params']['billing_cadence']);
    }

    #[Test]
    public function el_wizard_del_acta_no_guarda_listo_como_elemento(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'create_delivery_act',
            'params' => ['rental_name' => 'Local norte'],
            'confidence' => 0.9,
        ]);

        $this->assistant()->ask('crea un acta de entrega para Local norte', [], $this->user->id);
        $this->assistant()->ask('entrega', [], $this->user->id);
        // "siguiente" cierra medidores + inventario vacio + extras en el
        // mismo turno: la propuesta llega ya en esta respuesta.
        $last = $this->assistant()->ask('siguiente', [], $this->user->id);

        $this->assertSame('create_delivery_act', $last->action['type'] ?? null);
        $this->assertSame([], $last->action['params']['items']);
    }

    #[Test]
    public function item_sin_espacio_con_un_solo_espacio_se_asigna_solo(): void
    {
        $space = \App\Models\Space::factory()->create(['user_id' => $this->user->id, 'name' => 'Sala']);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'create_delivery_act',
            'params' => ['rental_name' => 'Local norte'],
            'confidence' => 0.9,
        ]);

        $this->assistant()->ask('crea un acta de entrega para Local norte', [], $this->user->id);
        $this->assistant()->ask('entrega', [], $this->user->id);
        // Las lecturas cierran medidores y NO deben convertirse en item.
        $this->assistant()->ask('agua 10, energia 20', [], $this->user->id);
        $this->assistant()->ask('nevera bueno', [], $this->user->id);
        $last = $this->assistant()->ask('listo', [], $this->user->id);

        $items = $last->action['params']['items'] ?? [];

        $this->assertCount(1, $items);
        $this->assertSame('nevera', $items[0]['name'] ?? null);
        $this->assertSame((int) $space->id, (int) $items[0]['space_id']);
        $this->assertSame('Sala', $items[0]['space']);
    }

    #[Test]
    public function item_sin_espacio_con_varios_espacios_se_descarta_con_aviso(): void
    {
        \App\Models\Space::factory()->create(['user_id' => $this->user->id, 'name' => 'Sala']);
        \App\Models\Space::factory()->create(['user_id' => $this->user->id, 'name' => 'Cocina']);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'create_delivery_act',
            'params' => ['rental_name' => 'Local norte'],
            'confidence' => 0.9,
        ]);

        $this->assistant()->ask('crea un acta de entrega para Local norte', [], $this->user->id);
        $this->assistant()->ask('entrega', [], $this->user->id);
        $this->assistant()->ask('agua 10', [], $this->user->id); // cierra medidores
        $this->assistant()->ask('nevera bueno', [], $this->user->id); // item sin espacio
        $reply = $this->assistant()->ask('listo', [], $this->user->id);

        // El item sin espacio resoluble se descarta con aviso, no tumba el acta.
        $this->assertStringContainsString('nevera', $reply->text);
        $this->assertNotNull($reply->pendingForm);

        // El wizard sigue vivo: "listo" otra vez cierra con items vacios.
        $last = $this->assistant()->ask('listo', [], $this->user->id);
        $this->assertSame('create_delivery_act', $last->action['type'] ?? null);
        $this->assertSame([], $last->action['params']['items']);
    }

    #[Test]
    public function matcher_local_anula_factura_y_desactiva_arriendo(): void
    {
        config()->set('services.gemini.key', null);
        Http::fake();

        Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'number' => 'FV-2026-0042',
            'amount' => 800000,
            'status' => InvoiceStatus::EMITIDA,
            'issued_at' => today()->toDateString(),
            'due_at' => today()->addDays(5)->toDateString(),
            'period' => today()->format('Y-m'),
        ]);

        $annul = $this->assistant()->ask('anula la factura FV-2026-0042', [], $this->user->id);

        $this->assertSame('annul_invoice', $annul->action['type']);
        $this->assertSame((int) Invoice::query()->where('number', 'FV-2026-0042')->value('id'), $annul->action['params']['invoice_id']);

        app(AssistantActionExecutor::class)->execute($annul->action, $this->user->id);
        $this->assertSame(InvoiceStatus::ANULADA, Invoice::query()->where('number', 'FV-2026-0042')->first()->status);

        $deactivate = $this->assistant()->ask('desactiva el arriendo de Local norte', [], $this->user->id);

        $this->assertSame('update_rental', $deactivate->action['type']);
        $this->assertFalse($deactivate->action['params']['is_active']);
        $this->assertSame((int) $this->rental->id, $deactivate->action['params']['rental_id']);
    }

    #[Test]
    public function matcher_local_reconoce_estado_y_sincronizacion_ipc(): void
    {
        config()->set('services.gemini.key', null);
        Http::fake();

        $status = $this->assistant()->ask('como esta el ipc', [], $this->user->id);
        $this->assertSame('ipc_status', $status->intent);

        $sync = $this->assistant()->ask('sincroniza el ipc', [], $this->user->id);
        $this->assertSame('sync_ipc', $sync->action['type']);
    }

    #[Test]
    public function matcher_local_cubre_update_documento_metricas_ajustes_export_y_ficha(): void
    {
        config()->set('services.gemini.key', null);
        Http::fake();

        $tenantName = (string) $this->rental->tenant->name;

        // Update puntual de inquilino.
        $update = $this->assistant()->ask("cambia el telefono del inquilino {$tenantName} a 3009998877", [], $this->user->id);
        $this->assertSame('update_tenant', $update->action['type']);
        $this->assertSame('3009998877', $update->action['params']['phone_number']);

        // Documento descargable por matcher local.
        Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'number' => 'FV-2026-0077',
            'amount' => 800000,
            'status' => InvoiceStatus::PAGADA,
            'issued_at' => today()->toDateString(),
            'due_at' => today()->addDays(5)->toDateString(),
            'period' => today()->format('Y-m'),
        ]);

        $doc = $this->assistant()->ask('descarga el comprobante de la factura FV-2026-0077', [], $this->user->id);
        $this->assertSame('generate_document', $doc->action['type']);
        $this->assertSame('receipt', $doc->action['params']['kind']);

        // Metricas financieras.
        $debtors = $this->assistant()->ask('quiénes son mis peores deudores', [], $this->user->id);
        $this->assertSame('top_debtors', $debtors->intent);

        $rate = $this->assistant()->ask('cual es mi tasa de recaudo', [], $this->user->id);
        $this->assertSame('collection_rate', $rate->intent);

        // Ciclo de vida de reajustes. Sin series IPC en el fixture el
        // preview falla con un mensaje claro (no action), pero el intent
        // ya quedo clasificado correctamente.
        $record = $this->assistant()->ask('registra un reajuste para Local norte', [], $this->user->id);
        $this->assertSame('record_adjustment', $record->intent);

        // Settings y exportacion.
        $settings = $this->assistant()->ask('como estan mis ajustes del negocio', [], $this->user->id);
        $this->assertSame('show_settings', $settings->intent);

        $export = $this->assistant()->ask('exporta la tabla a csv', [], $this->user->id);
        $this->assertSame('export_csv', $export->action['type']);

        // Ficha de entidad.
        $detail = $this->assistant()->ask('cuéntame del arriendo de Local norte', [], $this->user->id);
        $this->assertSame('entity_detail', $detail->intent);
        $this->assertSame('Local norte', $detail->data['rental_detail'][0]['name'] ?? null);
    }

    #[Test]
    public function generate_document_acepta_doc_type_canonico_de_gemini(): void
    {
        $invoice = Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'number' => 'FV-2026-0099',
            'amount' => 800000,
            'status' => InvoiceStatus::PAGADA,
            'issued_at' => today()->toDateString(),
            'due_at' => today()->addDays(5)->toDateString(),
            'period' => today()->format('Y-m'),
        ]);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'generate_document',
            'params' => ['doc_type' => 'payment_receipt', 'invoice_number' => 'FV-2026-0099', 'format' => 'text'],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('dame el comprobante de la factura FV-2026-0099', [], $this->user->id);

        $this->assertSame('generate_document', $reply->action['type']);
        $this->assertSame('receipt', $reply->action['params']['kind']);
        $this->assertSame((int) $invoice->id, $reply->action['params']['invoice_id']);
    }
}
