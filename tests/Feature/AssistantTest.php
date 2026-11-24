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
use App\Models\Tenant;
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

        // Los tests verifican el resultado del invoker: la redaccion
        // final por IA se prueba aparte con un fake de dos etapas.
        config()->set('services.gemini.compose_replies', false);

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

    // Responde distinto payload segun el texto de la pregunta: la clave es
    // una subcadena del mensaje del usuario (ej. 'anula' => annul_invoice).
    private function fakeGeminiRouter(array $map): void
    {
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-test');

        Http::fake(function ($request) use ($map) {
            $body = json_decode((string) $request->body(), true);
            $question = '';
            foreach (array_reverse($body['contents'] ?? []) as $content) {
                foreach ($content['parts'] ?? [] as $part) {
                    $question = (string) ($part['text'] ?? '');
                    break 2;
                }
            }

            foreach ($map as $needle => $payload) {
                if (mb_stripos($question, (string) $needle) !== false) {
                    return Http::response([
                        'candidates' => [
                            ['content' => ['parts' => [['text' => json_encode($payload)]]]],
                        ],
                    ]);
                }
            }

            return Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode([
                        'domain' => 'app',
                        'intent' => 'unknown',
                        'params' => [],
                        'confidence' => 0.9,
                    ])]]]],
                ],
            ]);
        });
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
    public function sin_key_toda_pregunta_responde_no_configurado_sin_llamar_a_la_api(): void
    {
        config()->set('services.gemini.key', null);
        Http::fake();

        // Sin key no hay razonamiento posible: ninguna pregunta de
        // negocio recibe atajos. Solo el cambio de tema sigue siendo
        // instantaneo.
        $reply = app(DataAssistantInterface::class)->ask('mis inquilinos', [], $this->user->id);

        $this->assertStringContainsString('GEMINI_API_KEY', $reply->text);
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
    public function si_gemini_falla_muestra_error_sin_filtrar_respuestas_de_atajo(): void
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

        // API caida (red, TLS, cuota o 5xx): el chat no debe quedarse mudo,
        // pero tampoco inventar una respuesta de atajo sin razonamiento.
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('', 500),
        ]);

        $reply = $this->assistant()->ask('cuanto me deben', [], $this->user->id);

        $this->assertStringContainsString('No pude responder', $reply->text);
        $this->assertStringNotContainsString('$800.000', $reply->text);
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
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'greeting',
            'params' => [],
            'confidence' => 0.95,
        ]);

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
    public function las_sugerencias_y_los_listados_funcionan(): void
    {
        $this->actingAs($this->user);
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'list_tenants',
            'params' => [],
            'confidence' => 0.9,
        ]);

        Livewire::test(AssistantChat::class)
            ->call('askSuggestion', 'mis inquilinos')
            ->assertSee('inquilinos')
            ->assertSee($this->rental->tenant->name);

        // Cada pregunta pasa por Gemini: el listado llega clasificado.
        Http::assertSentCount(1);
    }

    #[Test]
    public function crear_inquilino_propone_accion_y_confirma(): void
    {
        $this->actingAs($this->user);
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'create_tenant',
            'params' => [
                'name' => 'María Pérez',
                'document' => '1234567890',
                'phone_number' => '3001234567',
            ],
            'confidence' => 0.95,
        ]);

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
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'create_tenant',
            'params' => ['name' => ''],
            'confidence' => 0.8,
        ]);

        Livewire::test(AssistantChat::class)
            ->set('input', 'agrega un inquilino nuevo')
            ->call('send')
            ->assertSee('Revisar y proponer');

        // Solo existe el inquilino del arriendo del fixture: nada se creo.
        $this->assertSame(1, \App\Models\Tenant::where('user_id', $this->user->id)->count());
    }

    #[Test]
    public function agendar_arriendo_crea_inquilino_propiedad_y_arriendo(): void
    {
        $this->actingAs($this->user);
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'create_rental',
            'params' => [
                'tenant_name' => 'María López',
                'tenant_document' => '99887766',
                'tenant_phone' => '3009998877',
                'property_name' => 'apto 301',
                'property_address' => 'Calle 50 #10-20',
                'monthly_amount' => 800000,
                'total_months' => 12,
            ],
            'confidence' => 0.95,
        ]);

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
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'create_rental',
            'params' => [
                'tenant_name' => 'María',
                'property_name' => 'apto 301',
            ],
            'confidence' => 0.9,
        ]);

        Livewire::test(AssistantChat::class)
            ->set('input', 'agenda a María en el apto 301')
            ->call('send')
            ->assertSee('Canon mensual');
    }

    // ---------------------------------------------------------------
    // Preview modal y navegacion con filtros
    // ---------------------------------------------------------------

    #[Test]
    public function preview_action_muestra_el_detalle_y_confirma(): void
    {
        $this->actingAs($this->user);
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'create_tenant',
            'params' => [
                'name' => 'Pedro Gómez',
                'document' => '555',
                'phone_number' => '3001112233',
            ],
            'confidence' => 0.95,
        ]);

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
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'list_tenants',
            'params' => [],
            'confidence' => 0.9,
        ]);

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
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'navigate',
            'params' => ['target' => 'invoices', 'filter' => 'VENCIDA'],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('llévame a la sección de facturas vencidas', [], $this->user->id);

        $this->assertStringContainsString('af', $reply->redirect);
        $this->assertStringContainsString('vencida', $reply->redirect);
    }

    #[Test]
    public function navegar_con_filtro_no_soportado_muestra_los_datos(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'navigate',
            'params' => ['target' => 'tenants', 'filter' => 'ACTIVO'],
            'confidence' => 0.8,
        ]);

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
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'list_delivery_acts',
            'params' => [],
            'confidence' => 0.9,
        ]);

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
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'navigate',
            'params' => ['target' => 'delivery_acts'],
            'confidence' => 0.9,
        ]);

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
    public function sin_key_no_hay_atajo_local_ni_si_el_mensaje_parece_un_alta(): void
    {
        $this->actingAs($this->user);
        config()->set('services.gemini.key', null);
        Http::fake();

        // Sin razonamiento de IA no se extraen datos ni se proponen altas:
        // el chat responde que falta configurar el modelo.
        Livewire::test(AssistantChat::class)
            ->set('input', 'se llama Isaac David, cc 1067591312, tel 3023018355, correo isaacjacome9@gmail.com')
            ->call('send')
            ->assertSee('GEMINI_API_KEY');

        $this->assertSame(1, \App\Models\Tenant::where('user_id', $this->user->id)->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function el_repertorio_de_prompts_se_renderiza_con_sus_categorias(): void
    {
        $this->actingAs($this->user);

        Livewire::test(AssistantChat::class)
            ->assertSee('Prompts rápidos')
            ->assertSee('Biblioteca de prompts')
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
    public function batch_de_updates_sin_campo_abre_un_solo_wizard_y_cierra_en_un_batch(): void
    {
        $tenantB = Tenant::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Otra Persona',
        ]);
        $nameA = (string) $this->rental->tenant->name;

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'unknown',
            'confidence' => 0.9,
            'requests' => [
                ['intent' => 'update_tenant', 'params' => ['tenant_name' => $nameA]],
                ['intent' => 'update_tenant', 'params' => ['tenant_name' => 'Otra Persona']],
            ],
        ]);

        $reply = $this->assistant()->ask("quiero editar a {$nameA} y a Otra Persona", [], $this->user->id);

        // UN mensaje unificado + UN formulario (el primero de la cola);
        // antes cada update abria el suyo, se pisaban y no se veia ninguno.
        $this->assertNotNull($reply->pendingForm);
        $this->assertStringContainsString('2', $reply->text);
        $this->assertSame('update_clarify', session('assistant.form.'.$this->user->id)['form'] ?? null);

        $invoker = app(AssistantToolInvoker::class);

        // Primer formulario: cambia el telefono del primero.
        $first = $invoker->submitUpdateForm(['phone_number' => '3001112233'], $this->user->id);

        $this->assertNull($first->action);
        $this->assertNotNull($first->pendingForm);
        $this->assertSame('update_clarify', session('assistant.form.'.$this->user->id)['form'] ?? null);

        // Segundo formulario: cambia el nombre del segundo y cierra.
        $last = $invoker->submitUpdateForm(['name' => 'Nombre Nuevo'], $this->user->id);

        $this->assertSame('batch', $last->action['type'] ?? null);
        $this->assertCount(2, $last->action['items'] ?? []);
        $this->assertSame('update_tenant', $last->action['items'][0]['type'] ?? null);
        $this->assertSame('update_tenant', $last->action['items'][1]['type'] ?? null);
        $this->assertNull(session('assistant.form.'.$this->user->id));
    }

    #[Test]
    public function batch_de_updates_con_campos_directos_no_abre_wizard(): void
    {
        Tenant::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Otra Persona',
        ]);
        $nameA = (string) $this->rental->tenant->name;

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'unknown',
            'confidence' => 0.9,
            'requests' => [
                ['intent' => 'update_tenant', 'params' => ['tenant_name' => $nameA, 'phone_number' => '3000000001']],
                ['intent' => 'update_tenant', 'params' => ['tenant_name' => 'Otra Persona', 'phone_number' => '3000000002']],
            ],
        ]);

        $reply = $this->assistant()->ask("cambia el telefono de {$nameA} y de Otra Persona", [], $this->user->id);

        // Con datos completos no hay wizard: las dos acciones van en un
        // solo batch como siempre.
        $this->assertNull($reply->pendingForm);
        $this->assertSame('batch', $reply->action['type'] ?? null);
        $this->assertCount(2, $reply->action['items'] ?? []);
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
    public function batch_confirmado_devuelve_tabla_de_resultados_sin_repetir_texto(): void
    {
        $result = app(AssistantActionExecutor::class)->execute([
            'type' => 'batch',
            'items' => [
                ['type' => 'create_tenant', 'params' => ['name' => 'Isaac Uno', 'document' => '111', 'phone_number' => '3000000001']],
                ['type' => 'create_tenant', 'params' => ['name' => 'Maria Dos', 'document' => '222', 'phone_number' => '3000000002']],
                ['type' => 'create_property', 'params' => ['name' => 'Apto sin direccion']],
            ],
        ], $this->user->id);

        // Una fila por item: accion, elemento, detalle y estado — la
        // tabla reemplaza los bullets "Listo: ..." repetidos por item.
        $rows = $result->data['batch_results'] ?? [];
        $this->assertCount(3, $rows);
        $this->assertSame('Registrar inquilino', $rows[0]['action']);
        $this->assertSame('Isaac Uno', $rows[0]['item']);
        $this->assertSame('Hecho', $rows[0]['status']);
        $this->assertSame('Maria Dos', $rows[1]['item']);

        // El item invalido queda marcado como fallido y el resumen lo
        // cuenta sin listar cada exito otra vez.
        $this->assertSame('Falló', $rows[2]['status']);
        $this->assertStringContainsString('2 de 3', $result->text);
        $this->assertStringNotContainsString('•', $result->text);
    }

    #[Test]
    public function batch_de_updates_confirmado_devuelve_tabla_con_cada_entidad(): void
    {
        $tenantA = Tenant::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Persona A',
            'phone_number' => '3000000000',
        ]);
        $tenantB = Tenant::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Persona B',
            'phone_number' => '3000000000',
        ]);

        $result = app(AssistantActionExecutor::class)->execute([
            'type' => 'batch',
            'items' => [
                ['type' => 'update_tenant', 'params' => ['tenant_id' => $tenantA->id, 'phone_number' => '3111111111'], 'summary' => 'Actualizar inquilino: Persona A'],
                ['type' => 'update_tenant', 'params' => ['tenant_id' => $tenantB->id, 'name' => 'Persona B Editada'], 'summary' => 'Actualizar inquilino: Persona B'],
            ],
        ], $this->user->id);

        $rows = $result->data['batch_results'] ?? [];
        $this->assertCount(2, $rows);
        $this->assertSame('Actualizar inquilino', $rows[0]['action']);
        $this->assertSame('Hecho', $rows[1]['status']);

        // Los updates viajan por id: el nombre de la entidad sale del
        // summary de la accion, no de los params.
        $this->assertSame('Actualizar inquilino: Persona A', $rows[0]['item']);
        $this->assertSame('3111111111', $tenantA->fresh()->phone_number);
        $this->assertSame('Persona B Editada', $tenantB->fresh()->name);
    }

    #[Test]
    public function wizard_de_creacion_en_batch_no_hereda_valores_del_item_anterior(): void
    {
        $invoker = app(AssistantToolInvoker::class);

        // Dos creates incompletos -> la cola del wizard los recorre por
        // turnos. El primero se diligencia completo; el segundo debe
        // abrirse VACIO salvo lo que el modelo ya extrajo de su peticion.
        $first = $invoker->invokeBatch([
            ['intent' => 'create_tenant', 'params' => ['name' => 'Primera Persona']],
            ['intent' => 'create_tenant', 'params' => ['name' => 'Segunda Persona']],
        ], $this->user->id);

        $this->assertNotNull($first->pendingForm);

        $second = $invoker->submitCreateForm([
            'name' => 'Primera Persona',
            'document' => '100200300',
            'phone_number' => '3009998877',
            'email' => 'primera@mail.com',
        ], $this->user->id);

        $this->assertNotNull($second->pendingForm);
        $fields = collect($second->pendingForm['fields'])->keyBy('key');

        // El form del segundo inquilino trae SU nombre y nada del
        // primero: ni documento, ni telefono, ni correo heredados.
        $this->assertSame('Segunda Persona', $fields->get('name')['value'] ?? null);
        $this->assertSame('', $fields->get('document')['value'] ?? null);
        $this->assertSame('', $fields->get('phone_number')['value'] ?? null);
        $this->assertSame('', $fields->get('email')['value'] ?? null);

        // La sesion tampoco conserva los valores del item anterior.
        $session = session('assistant.createform.'.$this->user->id);
        $this->assertSame('tenant', $session['entity'] ?? null);
        $this->assertArrayNotHasKey('100200300', $session['values'] ?? []);
        $this->assertSame('Segunda Persona', $session['values']['name'] ?? null);
    }

    #[Test]
    public function wizard_de_update_en_batch_rehidrata_cada_entidad_independiente(): void
    {
        $tenantA = Tenant::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Cliente Alpha',
            'phone_number' => '3000000001',
        ]);
        $tenantB = Tenant::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Cliente Beta',
            'phone_number' => '3000000002',
        ]);

        $invoker = app(AssistantToolInvoker::class);

        // Encola los dos updates sin campo: cada uno abre su
        // clarificacion y el batch los recorre con UN solo wizard.
        $first = $invoker->invokeBatch([
            ['intent' => 'update_tenant', 'params' => ['tenant_name' => 'Cliente Alpha']],
            ['intent' => 'update_tenant', 'params' => ['tenant_name' => 'Cliente Beta']],
        ], $this->user->id);

        $this->assertNotNull($first->pendingForm);
        $this->assertStringContainsString('Alpha', $first->pendingForm['prompt'] ?? '');

        // Diligencia el primero cambiando el nombre.
        $second = $invoker->submitUpdateForm(['name' => 'Alpha Renombrado'], $this->user->id);

        // El siguiente paso es el SEGUNDO inquilino: su formulario se
        // rehidrato desde su propio registro, no del edit recien hecho.
        $this->assertNotNull($second->pendingForm);
        $this->assertStringContainsString('Beta', $second->pendingForm['prompt'] ?? '');
        $this->assertSame('update_clarify', session('assistant.form.'.$this->user->id)['form'] ?? null);
        $this->assertSame($tenantB->id, (int) (session('assistant.form.'.$this->user->id)['data']['entity_id'] ?? 0));

        // El select de estado u otro campo con valor actual refleja a B,
        // nunca el nombre que se acaba de escribir para A (que ni siquiera
        // esta persistido todavia).
        $this->assertSame('Cliente Beta', $tenantB->fresh()->name);
        $this->assertSame('Cliente Alpha', $tenantA->fresh()->name);
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
        // Solo ids del catalogo son elegibles: el modelo elegido define
        // el proveedor de la llamada y el fallback nunca lo cruza.
        $this->user->update(['assistant_model' => 'gemini-3.6-flash']);

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

        Http::assertSent(fn ($request) => str_contains($request->url(), 'gemini-3.6-flash'));
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
        // Con wizard activo las respuestas se clasifican: el router
        // devuelve el intent de creacion solo para la primera pregunta;
        // los pasos del wizard caen en 'unknown' y van al handler.
        $this->fakeGeminiRouter([
            'crea un acta' => [
                'domain' => 'app',
                'intent' => 'create_delivery_act',
                'params' => ['rental_name' => 'Local norte'],
                'confidence' => 0.9,
            ],
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

        $this->fakeGeminiRouter([
            'crea un acta' => [
                'domain' => 'app',
                'intent' => 'create_delivery_act',
                'params' => ['rental_name' => 'Local norte'],
                'confidence' => 0.9,
            ],
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

        $this->fakeGeminiRouter([
            'crea un acta' => [
                'domain' => 'app',
                'intent' => 'create_delivery_act',
                'params' => ['rental_name' => 'Local norte'],
                'confidence' => 0.9,
            ],
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
    public function la_ia_anula_factura_y_desactiva_arriendo(): void
    {
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

        $this->fakeGeminiRouter([
            'anula' => [
                'domain' => 'app',
                'intent' => 'annul_invoice',
                'params' => ['invoice_number' => 'FV-2026-0042'],
                'confidence' => 0.95,
            ],
            'desactiva' => [
                'domain' => 'app',
                'intent' => 'update_rental',
                'params' => ['rental_name' => 'Local norte', 'is_active' => false],
                'confidence' => 0.95,
            ],
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
    public function la_ia_reconoce_estado_y_sincronizacion_ipc(): void
    {
        $this->fakeGeminiRouter([
            'sincroniza' => [
                'domain' => 'app',
                'intent' => 'sync_ipc',
                'params' => [],
                'confidence' => 0.95,
            ],
            'ipc' => [
                'domain' => 'app',
                'intent' => 'ipc_status',
                'params' => [],
                'confidence' => 0.95,
            ],
        ]);

        $status = $this->assistant()->ask('como esta el ipc', [], $this->user->id);
        $this->assertSame('ipc_status', $status->intent);

        $sync = $this->assistant()->ask('sincroniza el ipc', [], $this->user->id);
        $this->assertSame('sync_ipc', $sync->action['type']);
    }

    #[Test]
    public function la_ia_cubre_update_documento_metricas_ajustes_export_y_ficha(): void
    {
        $tenantName = (string) $this->rental->tenant->name;

        // Documento descargable.
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

        $this->fakeGeminiRouter([
            'telefono' => [
                'domain' => 'app',
                'intent' => 'update_tenant',
                'params' => ['tenant_name' => $tenantName, 'phone_number' => '3009998877'],
                'confidence' => 0.95,
            ],
            'comprobante' => [
                'domain' => 'app',
                'intent' => 'generate_document',
                'params' => ['doc_type' => 'payment_receipt', 'invoice_number' => 'FV-2026-0077'],
                'confidence' => 0.95,
            ],
            'peores deudores' => [
                'domain' => 'app', 'intent' => 'top_debtors', 'params' => [], 'confidence' => 0.9,
            ],
            'tasa de recaudo' => [
                'domain' => 'app', 'intent' => 'collection_rate', 'params' => [], 'confidence' => 0.9,
            ],
            'reajuste' => [
                'domain' => 'app', 'intent' => 'record_adjustment',
                'params' => ['rental_name' => 'Local norte'], 'confidence' => 0.9,
            ],
            'ajustes del negocio' => [
                'domain' => 'app', 'intent' => 'show_settings', 'params' => [], 'confidence' => 0.9,
            ],
            'csv' => [
                'domain' => 'app', 'intent' => 'export_csv',
                'params' => ['section' => 'tenants'], 'confidence' => 0.9,
            ],
            'cuéntame' => [
                'domain' => 'app', 'intent' => 'entity_detail',
                'params' => ['entity_type' => 'rental', 'name' => 'Local norte'], 'confidence' => 0.9,
            ],
        ]);

        // Update puntual de inquilino.
        $update = $this->assistant()->ask("cambia el telefono del inquilino {$tenantName} a 3009998877", [], $this->user->id);
        $this->assertSame('update_tenant', $update->action['type']);
        $this->assertSame('3009998877', $update->action['params']['phone_number']);

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

    #[Test]
    public function el_preview_de_documento_muestra_tipo_formato_y_objetivo_legibles(): void
    {
        $this->actingAs($this->user);

        $invoice = Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'number' => 'FV-2027-0005',
            'amount' => 800000,
            'status' => InvoiceStatus::EMITIDA,
            'issued_at' => today()->toDateString(),
            'due_at' => today()->addDays(5)->toDateString(),
            'period' => today()->format('Y-m'),
        ]);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'generate_document',
            'params' => ['doc_type' => 'invoice', 'invoice_number' => 'FV-2027-0005', 'format' => 'text'],
            'confidence' => 0.9,
        ]);

        Livewire::test(AssistantChat::class)
            ->set('input', 'genera la factura de FV-2027-0005')
            ->call('send')
            ->call('previewAction', 1)
            ->assertSet('preview.rows.0.label', 'el tipo de documento')
            ->assertSet('preview.rows.0.badge', 'document')
            ->assertSet('preview.rows.0.value', 'Factura')
            ->assertSet('preview.rows.1.label', 'el formato')
            ->assertSet('preview.rows.2.label', 'la referencia')
            ->assertSet('preview.rows.2.value', 'FV-2027-0005');
    }

    // ---------------------------------------------------------------
    // Eleccion flexible de entidad: "cualquiera", candidatos y chunks
    // ---------------------------------------------------------------

    #[Test]
    public function update_con_cualquiera_elige_un_registro_y_avisa(): void
    {
        $this->actingAs($this->user);

        $reply = app(AssistantToolInvoker::class)->invoke('update_property', [
            'property_name' => 'cualquiera',
            'address' => 'Cra 10 #5-20',
        ], $this->user->id);

        // El usuario delego la eleccion: se propone UNA propiedad concreta
        // y la advertencia queda visible en el preview.
        $this->assertSame('update_property', $reply->action['type']);
        $this->assertNotEmpty($reply->action['warnings']);
        $this->assertSame('Cra 10 #5-20', $reply->action['params']['address']);
    }

    #[Test]
    public function entidad_ambigua_lista_candidatos_y_pick_candidate_resuelve(): void
    {
        $this->actingAs($this->user);

        // Dos inquilinos con el mismo nombre: el update no debe adivinar.
        $first = \App\Models\Tenant::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Camila Torres',
            'phone_number' => '3111111111',
        ]);
        $second = \App\Models\Tenant::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Camila Torres',
            'phone_number' => '3222222222',
        ]);

        $reply = app(AssistantToolInvoker::class)->invoke('update_tenant', [
            'tenant_name' => 'Camila',
            'phone_number' => '3009998877',
        ], $this->user->id);

        $this->assertNull($reply->action);
        $this->assertCount(2, $reply->data['candidates']);
        $this->assertSame(
            ['intent' => 'update_tenant', 'name_key' => 'tenant_name'],
            array_intersect_key($reply->data['pending_resolution'], ['intent' => 1, 'name_key' => 1]),
        );

        // Flujo real: el mensaje genera la respuesta ambigua (mensaje
        // indice 1) y el chip elegido re-ejecuta con _entity_ref.
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'update_tenant',
            'params' => ['tenant_name' => 'Camila', 'phone_number' => '3009998877'],
            'confidence' => 0.9,
        ]);

        Livewire::test(AssistantChat::class)
            ->set('input', 'cambia el teléfono de Camila a 3009998877')
            ->call('send')
            ->call('pickCandidate', 1, 'Tenant:'.$second->id)
            ->assertSee('Camila Torres');

        $resolved = app(AssistantToolInvoker::class)->invoke('update_tenant', [
            'tenant_name' => 'Camila Torres',
            'phone_number' => '3009998877',
            '_entity_ref' => 'Tenant:'.$second->id,
        ], $this->user->id);

        $this->assertSame('update_tenant', $resolved->action['type']);
        $this->assertSame($second->id, $resolved->action['params']['tenant_id']);
        $this->assertSame('3009998877', $resolved->action['params']['phone_number']);
    }

    #[Test]
    public function batch_grande_se_procesa_por_chunks_con_continue(): void
    {
        $this->actingAs($this->user);

        $requests = [];
        for ($i = 1; $i <= 15; $i++) {
            $requests[] = [
                'intent' => 'create_tenant',
                'params' => ['name' => "Inquilino $i", 'document' => "Doc$i", 'phone_number' => "300000000$i"],
            ];
        }

        $reply = app(AssistantToolInvoker::class)->invokeBatch($requests, $this->user->id);

        // 15 acciones > BATCH_CHUNK (12): se proponen 12 y 3 quedan en cola.
        $this->assertSame('batch', $reply->action['type']);
        $this->assertCount(12, $reply->action['items']);
        $this->assertStringContainsString('continuar', mb_strtolower($reply->text));

        // "continuar" propone el siguiente grupo y libera la cola.
        $next = app(AssistantToolInvoker::class)->invoke('continue_batch', [], $this->user->id);
        $this->assertSame('batch', $next->action['type']);
        $this->assertCount(3, $next->action['items']);
    }

    #[Test]
    public function preview_ipc_con_inquilino_ambiguo_ofrece_candidatos(): void
    {
        $this->actingAs($this->user);

        // El fixture ya tiene un arriendo con inquilino; creamos otro con
        // el MISMO nombre de inquilino para forzar la ambiguedad.
        $tenant = \App\Models\Tenant::factory()->create([
            'user_id' => $this->user->id,
            'name' => $this->rental->tenant->name,
        ]);
        $property = \App\Models\Property::factory()->create(['user_id' => $this->user->id]);
        Rental::factory()->create([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'name' => 'Otro arriendo',
            'is_active' => true,
        ]);

        $reply = app(AssistantToolInvoker::class)->invoke('preview_adjustment_ipc', [
            'rental_name' => $this->rental->tenant->name,
        ], $this->user->id);

        // Dos arriendos comparten inquilino: candidatos, no adivinanza.
        $this->assertNull($reply->action);
        $this->assertNotEmpty($reply->data['candidates']);
    }

    // ---------------------------------------------------------------
    // Vias libres: query_data (SQL del modelo) y general_answer
    // ---------------------------------------------------------------

    #[Test]
    public function query_data_ejecuta_sql_valido_y_devuelve_tabla(): void
    {
        $calls = 0;
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-test');

        Http::fake(function ($request) use (&$calls) {
            $calls++;

            // 1) clasificacion -> query_data con SQL real sobre el fixture
            if ($calls === 1) {
                return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'domain' => 'app',
                    'intent' => 'query_data',
                    'params' => ['sql' => 'SELECT name AS inquilino, document AS cedula FROM tenants WHERE user_id = '.$this->user->id.' LIMIT 10'],
                    'confidence' => 0.9,
                ])]]]]]]);
            }

            // 2) draft: la IA narra los resultados
            return Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Encontré tus inquilinos.']]]]]]);
        });

        $reply = $this->assistant()->ask('dame una consulta libre de inquilinos', [], $this->user->id);

        $this->assertNotNull($reply->data['results'] ?? null);
        $this->assertSame($this->rental->tenant->name, $reply->data['results'][0]['inquilino']);
        $this->assertSame('Encontré tus inquilinos.', $reply->text);
    }

    #[Test]
    public function query_data_rechaza_escritura_y_reintenta_una_vez(): void
    {
        $tenantsBefore = Tenant::count();
        $calls = 0;
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-test');

        Http::fake(function ($request) use (&$calls) {
            $calls++;

            // Clasificacion (y el reintento) siempre devuelve el mismo
            // SQL malicioso: debe ser rechazado las dos veces.
            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'domain' => 'app',
                'intent' => 'query_data',
                'params' => ['sql' => 'DELETE FROM tenants'],
                'confidence' => 0.9,
            ])]]]]]]);
        });

        $reply = $this->assistant()->ask('borra todo', [], $this->user->id);

        $this->assertStringContainsString('No pude completar', $reply->text);
        // 1 clasificacion + 1 reintento de clasificacion: el SQL nunca corre.
        $this->assertSame(2, $calls);
        $this->assertSame($tenantsBefore, Tenant::count());
    }

    #[Test]
    public function query_data_anida_relaciones_cuando_el_modelo_declara_nest(): void
    {
        foreach (['FV-2026-9001', 'FV-2026-9002'] as $i => $number) {
            Invoice::factory()->create([
                'rental_id' => $this->rental->id,
                'user_id' => $this->user->id,
                'number' => $number,
                'amount' => 500000 + $i,
                'status' => InvoiceStatus::EMITIDA,
                'issued_at' => today()->toDateString(),
                'due_at' => today()->addDays(5)->toDateString(),
                'period' => today()->format('Y-m'),
            ]);
        }

        Tenant::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Sin Facturas',
        ]);

        $reply = app(AssistantToolInvoker::class)->invoke('query_data', [
            'sql' => 'SELECT t.name AS inquilino, i.number AS factura, i.amount AS valor '
                .'FROM tenants t '
                .'LEFT JOIN rentals r ON r.tenant_id = t.id AND r.user_id = '.$this->user->id.' '
                .'LEFT JOIN invoices i ON i.rental_id = r.id '
                .'WHERE t.user_id = '.$this->user->id,
            'question' => 'mis inquilinos y por cada uno sus facturas',
            'nest' => [
                'parent' => ['inquilino'],
                'child' => ['factura', 'valor'],
                'as' => 'facturas',
            ],
        ], $this->user->id);

        $rows = $reply->data['results'] ?? [];
        $this->assertCount(2, $rows);

        $conFacturas = collect($rows)->firstWhere('inquilino', $this->rental->tenant->name);
        $this->assertCount(2, $conFacturas['facturas']);
        $this->assertSame('FV-2026-9001', $conFacturas['facturas'][0]['factura']);

        // LEFT JOIN sin hijo: la fila aparece con la tabla vacia, no se pierde.
        $sinFacturas = collect($rows)->firstWhere('inquilino', 'Sin Facturas');
        $this->assertSame([], $sinFacturas['facturas']);
    }

    #[Test]
    public function query_data_con_nest_invalido_cae_a_la_tabla_plana(): void
    {
        $reply = app(AssistantToolInvoker::class)->invoke('query_data', [
            'sql' => 'SELECT name AS inquilino, document AS cedula FROM tenants WHERE user_id = '.$this->user->id,
            'question' => 'inquilinos',
            // Alias que no existe en el SELECT: nest no es usable.
            'nest' => ['parent' => ['inquilino'], 'child' => ['columna_fantasma'], 'as' => 'hijos'],
        ], $this->user->id);

        $rows = $reply->data['results'] ?? [];
        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('inquilino', $rows[0]);
        $this->assertArrayNotHasKey('hijos', $rows[0]);
    }

    #[Test]
    public function query_data_anida_varias_relaciones_en_tablas_separadas(): void
    {
        // Arriendo con 2 facturas (1:N) + inquilino y propiedad (1:1):
        // el JOIN repite las columnas del "uno" por cada factura; el
        // nest debe deduplicarlas y separar cada entidad en su tabla.
        foreach (['FV-2026-9101', 'FV-2026-9102'] as $number) {
            Invoice::factory()->create([
                'rental_id' => $this->rental->id,
                'user_id' => $this->user->id,
                'number' => $number,
                'amount' => 700000,
                'status' => InvoiceStatus::EMITIDA,
                'issued_at' => today()->toDateString(),
                'due_at' => today()->addDays(5)->toDateString(),
                'period' => today()->format('Y-m'),
            ]);
        }

        $reply = app(AssistantToolInvoker::class)->invoke('query_data', [
            'sql' => 'SELECT r.monthly_amount AS canon, t.name AS inquilino, t.document AS doc_inquilino, '
                .'p.name AS propiedad, p.address AS dir_propiedad, i.number AS factura, i.amount AS valor '
                .'FROM rentals r '
                .'LEFT JOIN tenants t ON t.id = r.tenant_id '
                .'LEFT JOIN properties p ON p.id = r.property_id '
                .'LEFT JOIN invoices i ON i.rental_id = r.id '
                .'WHERE r.user_id = '.$this->user->id,
            'question' => 'mis arriendos con su inquilino, propiedad y facturas',
            'nest' => [
                'parent' => ['canon'],
                'children' => [
                    ['as' => 'inquilino', 'columns' => ['inquilino', 'doc_inquilino']],
                    ['as' => 'propiedad', 'columns' => ['propiedad', 'dir_propiedad']],
                    ['as' => 'facturas', 'columns' => ['factura', 'valor']],
                ],
            ],
        ], $this->user->id);

        $rows = $reply->data['results'] ?? [];
        $this->assertCount(1, $rows);
        $row = $rows[0];

        // Cada relacion en su propia tabla anidada.
        $this->assertSame($this->rental->tenant->name, $row['inquilino'][0]['inquilino']);
        $this->assertSame($this->rental->property->name, $row['propiedad'][0]['propiedad']);
        $this->assertCount(2, $row['facturas']);

        // La 1:1 no se duplica por las 2 facturas del 1:N.
        $this->assertCount(1, $row['inquilino']);
        $this->assertCount(1, $row['propiedad']);

        // Las columnas de cada tabla solo traen las suyas: la del
        // inquilino no filtra campos de propiedad ni de factura.
        $this->assertArrayNotHasKey('propiedad', $row['inquilino'][0]);
        $this->assertArrayNotHasKey('factura', $row['inquilino'][0]);
        $this->assertArrayNotHasKey('inquilino', $row['facturas'][0]);
    }

    #[Test]
    public function query_data_exige_user_id_en_tablas_del_usuario(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'query_data',
            // SQL valido pero sin scope: el guardia lo rechaza y el
            // reintento recibe el mismo payload -> fallo controlado.
            'params' => ['sql' => 'SELECT * FROM rentals LIMIT 5'],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('consulta libre sin filtro', [], $this->user->id);

        $this->assertStringContainsString('No pude completar', $reply->text);
        $this->assertNull($reply->data);
    }

    #[Test]
    public function general_answer_devuelve_el_texto_del_modelo(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'general_answer',
            'params' => ['answer' => 'El IPC es el índice de precios al consumidor: se usa para reajustar cánones.'],
            'confidence' => 0.95,
        ]);

        $reply = $this->assistant()->ask('qué es el IPC', [], $this->user->id);

        $this->assertStringContainsString('índice de precios', $reply->text);
        // Sin tabla ni acción: solo texto.
        $this->assertNull($reply->data);
        $this->assertNull($reply->action);
    }

    #[Test]
    public function query_data_permite_tablas_globales_sin_user_id(): void
    {
        $calls = 0;
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-test');

        Http::fake(function ($request) use (&$calls) {
            $calls++;

            if ($calls === 1) {
                return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'domain' => 'app',
                    'intent' => 'query_data',
                    // ipc_series es global: no lleva user_id.
                    'params' => ['sql' => 'SELECT COUNT(*) AS total FROM ipc_series'],
                    'confidence' => 0.9,
                ])]]]]]]);
            }

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Resumen.']]]]]]);
        });

        $reply = $this->assistant()->ask('cuántos datos IPC hay', [], $this->user->id);

        $this->assertNotNull($reply->data['results'] ?? null);
        $this->assertArrayHasKey('total', $reply->data['results'][0]);
    }

    #[Test]
    public function app_guide_start_devuelve_pasos_accionables(): void
    {
        $reply = app(AssistantToolInvoker::class)->invoke('app_guide', [
            'topic' => 'start',
        ], $this->user->id);

        $guide = $reply->data['guide'] ?? null;

        $this->assertNotNull($guide);
        $this->assertNotEmpty($guide['steps']);

        // Cada paso tiene titulo y al menos uno trae accion (url o prompt).
        $this->assertNotEmpty($guide['steps'][0]['title']);
        $this->assertNotEmpty($guide['steps'][0]['action'] ?? null);
    }

    #[Test]
    public function app_guide_tema_desconocido_cae_al_onboarding(): void
    {
        $reply = app(AssistantToolInvoker::class)->invoke('app_guide', [
            'topic' => 'tema_que_no_existe',
        ], $this->user->id);

        // topic invalido -> 'start' (onboarding), no error.
        $this->assertNotNull($reply->data['guide'] ?? null);
        $this->assertNotEmpty($reply->data['guide']['steps']);
    }

    #[Test]
    public function app_guide_llega_como_intent_desde_gemini(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'app_guide',
            'params' => ['topic' => 'collections'],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('cómo hago para cobrar a mis inquilinos', [], $this->user->id);

        $this->assertNotNull($reply->data['guide'] ?? null);
        $this->assertNotEmpty($reply->data['guide']['steps']);
    }

    // ---------------------------------------------------------------
    // Analisis predictivo (business_insights)
    // ---------------------------------------------------------------

    #[Test]
    public function business_insights_calcula_pronostico_con_pagos_reales(): void
    {
        // Dos meses completos con recaudo: el mes en curso NO cuenta.
        Payment::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'amount' => 900000,
            'date' => today()->subMonth()->toDateString(),
        ]);
        Payment::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'amount' => 300000,
            'date' => today()->subMonths(2)->toDateString(),
        ]);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'business_insights',
            'params' => [],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('que pasaria si sigo asi con los mismos ingresos', [], $this->user->id);

        $insights = $reply->data['insights'] ?? null;
        $this->assertNotNull($insights);
        $this->assertTrue($insights['forecast']['has_history']);
        $this->assertSame(3, $insights['forecast']['window_months']);
        // Promedio de los ultimos 3 meses completos (900k + 300k + 0)/3.
        $this->assertEqualsWithDelta(400000, $insights['forecast']['projected_next_month'], 1);
        $this->assertNotEmpty($insights['tenants']);
        $this->assertArrayHasKey('risk_score', $insights['tenants'][0]);
        $this->assertArrayHasKey('recommendations', $insights);
    }

    #[Test]
    public function business_insights_reporta_mora_y_danados_del_arriendo(): void
    {
        Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'number' => 'FV-2026-0099',
            'amount' => 800000,
            'status' => InvoiceStatus::VENCIDA,
            'issued_at' => today()->subMonths(2)->toDateString(),
            'due_at' => today()->subDays(10)->toDateString(),
            'period' => today()->subMonth()->format('Y-m'),
        ]);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'business_insights',
            'params' => [],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('vale la pena tener a este inquilino', [], $this->user->id);

        $tenant = $reply->data['insights']['tenants'][0] ?? null;
        $this->assertNotNull($tenant);
        $this->assertGreaterThan(0, $tenant['overdue_balance']);
        $this->assertContains('overdue', $tenant['flags']);
        // La recomendacion debe citar la mora, no ser genérica.
        $reasons = implode(' ', array_column($reply->data['insights']['recommendations'], 'reason'));
        $this->assertStringContainsString('días de atraso', $reasons);
    }

    #[Test]
    public function business_insights_sin_historial_lo_dice_explicito(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'business_insights',
            'params' => [],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('predice mis ingresos del proximo mes', [], $this->user->id);

        // Sin historial el pronostico debe ser cero y la bandera apagada:
        // la tarjeta muestra el aviso de datos insuficientes.
        $this->assertFalse($reply->data['insights']['forecast']['has_history']);
        $this->assertEquals(0, $reply->data['insights']['forecast']['projected_next_month']);
    }

    #[Test]
    public function business_insights_cae_al_camino_determinista_si_el_reporte_falla(): void
    {
        // Gemini clasifica el intent, pero si la composición del reporte
        // dinámico no se puede validar, el ejecutor determinista sigue
        // produciendo los datos de insights con evidencia real.
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'business_insights',
            'params' => [],
            'confidence' => 0.9,
        ]);

        $reply = app(DataAssistantInterface::class)->ask('que puntos puedo mejorar de mi negocio', [], $this->user->id);

        $this->assertNotNull($reply->data['insights'] ?? null);
    }

    #[Test]
    public function business_insights_no_mezcla_datos_de_otro_usuario(): void
    {
        $other = User::factory()->create();
        Payment::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $other->id,
            'amount' => 999999,
            'date' => today()->subMonth()->toDateString(),
        ]);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'business_insights',
            'params' => [],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('que pasaria si sigo asi', [], $this->user->id);

        // El pago del otro usuario no debe infla el pronostico.
        $this->assertFalse($reply->data['insights']['forecast']['has_history']);
    }

    #[Test]
    public function las_actas_de_un_arriendo_solo_muestran_las_de_esa_entidad(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'list_delivery_acts',
            'params' => ['rental_name' => (string) $this->rental->tenant->name],
            'confidence' => 0.9,
        ]);

        // Segundo arriendo con su propio acta: el listado acotado no debe
        // mezclarlo.
        $otherRental = Rental::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Bodega sur',
            'is_active' => true,
        ]);

        DeliveryAct::factory()->create([
            'user_id' => $this->user->id,
            'rental_id' => $this->rental->id,
            'tenant_name' => (string) $this->rental->tenant->name,
        ]);
        DeliveryAct::factory()->create([
            'user_id' => $this->user->id,
            'rental_id' => $otherRental->id,
            'tenant_name' => (string) $otherRental->tenant->name,
        ]);

        $reply = $this->assistant()->ask(
            'muéstrame las actas del arriendo de '.$this->rental->tenant->name,
            [],
            $this->user->id,
        );

        $this->assertNotNull($reply->data['acts'] ?? null);
        $this->assertCount(1, $reply->data['acts']);
        $this->assertSame((string) $this->rental->tenant->name, $reply->data['acts'][0]['tenant']);
        $this->assertStringContainsString(
            (string) $this->rental->tenant->name,
            (string) ($reply->data['_titles']['acts'] ?? ''),
        );
    }

    #[Test]
    public function cambiar_el_nombre_de_inquilino_propone_update_con_alias(): void
    {
        $tenantName = (string) $this->rental->tenant->name;

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'update_tenant',
            'params' => ['tenant_name' => $tenantName, 'name' => 'Nombre Nuevo'],
            'confidence' => 0.95,
        ]);

        $reply = $this->assistant()->ask("cambia el nombre de {$tenantName} a Nombre Nuevo", [], $this->user->id);

        $this->assertSame('update_tenant', $reply->action['type'] ?? null);
        $this->assertSame('Nombre Nuevo', $reply->action['params']['name'] ?? null);

        $done = app(AssistantActionExecutor::class)->execute($reply->action, $this->user->id);

        $this->assertSame('Nombre Nuevo', $this->rental->tenant->fresh()->name);
        $this->assertStringContainsString('ahora es Nombre Nuevo', $done->text);
    }

    #[Test]
    public function el_detalle_de_entidad_trae_titulo_contextual(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'entity_detail',
            'params' => ['entity_type' => 'rental', 'name' => 'Local norte'],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('cuéntame del arriendo de Local norte', [], $this->user->id);

        $this->assertSame('Local norte', $reply->data['_titles']['rental_detail'] ?? null
            ? trim(str_replace('Arriendo: ', '', (string) $reply->data['_titles']['rental_detail']))
            : null);
    }

    #[Test]
    public function cancelar_el_pensamiento_descarta_la_respuesta(): void
    {
        $this->actingAs($this->user);
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-test');

        // El nonce se escribe MIENTRAS la IA "piensa" — dentro del fake
        // HTTP, simulando un cancelPending llegando en un request
        // concurrente (cada request Livewire es una instancia distinta,
        // por eso la marca vive en cache y no en una propiedad).
        Http::fake(function () {
            // send() ya creo el chat antes de llamar a la IA, asi que la
            // clave de cancelacion usa el id real (no 'new').
            $chatId = \App\Models\AssistantChat::query()
                ->where('user_id', $this->user->id)
                ->latest('id')
                ->value('id') ?? 'new';

            \Illuminate\Support\Facades\Cache::put(
                'assistant.cancel.'.$this->user->id.'.'.$chatId,
                'mark-1',
            );

            return Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode([
                        'domain' => 'app',
                        'intent' => 'unknown',
                        'params' => [],
                        'confidence' => 0.9,
                    ])]]]],
                ],
            ]);
        });

        $chat = Livewire::test(AssistantChat::class)
            ->set('input', 'cuanto me deben')
            ->call('send');

        $messages = $chat->get('messages');
        $last = end($messages);
        $beforeLast = $messages[count($messages) - 2];

        // El mensaje del usuario queda persistido; la respuesta de la IA
        // se descarta y en su lugar va la burbuja de cancelado.
        $this->assertSame('user', $beforeLast['role']);
        $this->assertSame('assistant', $last['role']);
        $this->assertSame('cancelled', $last['notice']);
        $this->assertSame(__('assistant.replies.request_cancelled'), $last['text']);
    }

    #[Test]
    public function interrupt_send_cancela_la_respuesta_en_vuelo_y_envia_ya(): void
    {
        $this->actingAs($this->user);
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-test');

        // Primera respuesta: el nonce cambia a mitad del ask (como si un
        // interruptSend hubiera llegado), asi que se descarta.
        Http::fake(function () {
            $chatId = \App\Models\AssistantChat::query()
                ->where('user_id', $this->user->id)
                ->latest('id')
                ->value('id') ?? 'new';

            \Illuminate\Support\Facades\Cache::put(
                'assistant.cancel.'.$this->user->id.'.'.$chatId,
                'interrupt-mark',
            );

            return Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode([
                        'domain' => 'app',
                        'intent' => 'unknown',
                        'params' => [],
                        'confidence' => 0.9,
                    ])]]]],
                ],
            ]);
        });

        $chat = Livewire::test(AssistantChat::class)
            ->set('input', 'primera pregunta')
            ->call('send');

        // interruptSend escribe un nonce nuevo y procesa la pregunta por
        // el pipeline normal: su propio send() toma ese nonce como base
        // y no se descarta a si mismo.
        Http::fake(function () {
            return Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode([
                        'domain' => 'app',
                        'intent' => 'unknown',
                        'params' => [],
                        'confidence' => 0.9,
                    ])]]]],
                ],
            ]);
        });

        $chat->call('interruptSend', 'segunda pregunta forzada');

        $messages = $chat->get('messages');
        $texts = array_column($messages, 'text');

        $this->assertContains('primera pregunta', $texts);
        $this->assertContains('segunda pregunta forzada', $texts);
        $this->assertContains(__('assistant.replies.request_cancelled'), $texts);
    }

    #[Test]
    public function update_sin_campo_abre_el_asistente_guiado_de_dos_pasos(): void
    {
        $tenantName = (string) $this->rental->tenant->name;

        // Gemini clasifica el update con entidad pero sin campo: el
        // sistema NO adivina, abre la clarificacion guiada. Con el
        // asistente activo las respuestas se clasifican igual: el router
        // devuelve el intent solo para la primera pregunta y 'unknown'
        // para los pasos, que van al handler del formulario.
        $this->fakeGeminiRouter([
            'actualiza a' => [
                'domain' => 'app',
                'intent' => 'update_tenant',
                'params' => ['tenant_name' => $tenantName],
                'confidence' => 0.9,
            ],
        ]);

        $first = $this->assistant()->ask("actualiza a {$tenantName}", [], $this->user->id);

        $this->assertStringContainsString($tenantName, $first->text);
        $this->assertNotNull($first->pendingForm);
        $this->assertSame('update_clarify', session('assistant.form.'.$this->user->id)['form'] ?? null);

        // Paso 1: el usuario nombra el campo con palabras naturales.
        $second = $this->assistant()->ask('el nombre', [], $this->user->id);

        $this->assertStringContainsString('nombre', \Illuminate\Support\Str::lower($second->text));
        $this->assertSame('update_value', session('assistant.form.'.$this->user->id)['form'] ?? null);

        // Paso 2: el mensaje ES el valor nuevo; sale la propuesta.
        $third = $this->assistant()->ask('Nombre Guiado', [], $this->user->id);

        $this->assertSame('update_tenant', $third->action['type'] ?? null);
        $this->assertSame('Nombre Guiado', $third->action['params']['name'] ?? null);
        $this->assertNull(session('assistant.form.'.$this->user->id));

        app(AssistantActionExecutor::class)->execute($third->action, $this->user->id);
        $this->assertSame('Nombre Guiado', $this->rental->tenant->fresh()->name);
    }

    #[Test]
    public function update_guiado_sirve_para_cualquier_campo_de_cualquier_entidad(): void
    {
        // Mismo mecanismo para propiedad: campo direccion.
        $property = \App\Models\Property::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Apto 301',
        ]);

        $this->fakeGeminiRouter([
            'actualiza la' => [
                'domain' => 'app',
                'intent' => 'update_property',
                'params' => ['property_name' => 'Apto 301'],
                'confidence' => 0.9,
            ],
        ]);

        $this->assistant()->ask('actualiza la propiedad Apto 301', [], $this->user->id);
        $this->assistant()->ask('la direccion', [], $this->user->id);
        $reply = $this->assistant()->ask('Cra 7 #12-30', [], $this->user->id);

        $this->assertSame('update_property', $reply->action['type'] ?? null);
        $this->assertSame('Cra 7 #12-30', $reply->action['params']['address'] ?? null);
    }

    #[Test]
    public function update_guiado_recolecta_varios_campos_en_una_sola_propuesta(): void
    {
        $tenantName = (string) $this->rental->tenant->name;

        $this->fakeGeminiRouter([
            'actualiza a' => [
                'domain' => 'app',
                'intent' => 'update_tenant',
                'params' => ['tenant_name' => $tenantName],
                'confidence' => 0.9,
            ],
        ]);

        $this->assistant()->ask("actualiza a {$tenantName}", [], $this->user->id);

        // El usuario nombra DOS campos de una vez: ya no se pregunta cual,
        // se encola y pide el primer valor.
        $second = $this->assistant()->ask('nombre y teléfono', [], $this->user->id);

        $this->assertSame('update_value', session('assistant.form.'.$this->user->id)['form'] ?? null);
        $this->assertSame(1, $second->pendingForm['step'] ?? null);
        $this->assertSame(2, $second->pendingForm['total_steps'] ?? null);
        $this->assertSame('text', $second->pendingForm['input']['kind'] ?? null);

        // Primer valor: falta el segundo campo.
        $third = $this->assistant()->ask('Nombre Guiado', [], $this->user->id);

        $this->assertSame(2, $third->pendingForm['step'] ?? null);
        $this->assertSame(2, $third->pendingForm['total_steps'] ?? null);
        $this->assertNull($third->action);

        // Segundo valor: sale UNA propuesta con los dos cambios.
        $fourth = $this->assistant()->ask('3009998877', [], $this->user->id);

        $this->assertSame('update_tenant', $fourth->action['type'] ?? null);
        $this->assertSame('Nombre Guiado', $fourth->action['params']['name'] ?? null);
        $this->assertSame('3009998877', $fourth->action['params']['phone_number'] ?? null);
        $this->assertNull(session('assistant.form.'.$this->user->id));
    }

    #[Test]
    public function update_guiado_input_tipado_sale_del_kind_del_campo(): void
    {
        $tenantName = (string) $this->rental->tenant->name;

        $this->fakeGeminiRouter([
            'actualiza el' => [
                'domain' => 'app',
                'intent' => 'update_rental',
                'params' => ['rental_name' => $this->rental->name],
                'confidence' => 0.9,
            ],
        ]);

        $this->assistant()->ask("actualiza el arriendo {$this->rental->name}", [], $this->user->id);
        $reply = $this->assistant()->ask('la fecha de inicio', [], $this->user->id);

        $this->assertSame('date', $reply->pendingForm['input']['kind'] ?? null);
    }

    #[Test]
    public function update_guiado_cancela_con_cancelar(): void
    {
        $tenantName = (string) $this->rental->tenant->name;

        $this->fakeGeminiRouter([
            'actualiza a' => [
                'domain' => 'app',
                'intent' => 'update_tenant',
                'params' => ['tenant_name' => $tenantName],
                'confidence' => 0.9,
            ],
        ]);

        $this->assistant()->ask("actualiza a {$tenantName}", [], $this->user->id);
        $reply = $this->assistant()->ask('cancela', [], $this->user->id);

        $this->assertNull(session('assistant.form.'.$this->user->id));
        $this->assertNull($reply->action);
    }

    #[Test]
    public function una_peticion_nueva_durante_un_wizard_no_es_secuestrada(): void
    {
        $tenantName = (string) $this->rental->tenant->name;

        // Wizard de clarificacion abierto; luego el usuario pregunta otra
        // cosa del negocio. La intencion real debe ganar sobre el
        // formulario activo en vez de quedar tragada por el wizard.
        $this->fakeGeminiRouter([
            'actualiza a' => [
                'domain' => 'app',
                'intent' => 'update_tenant',
                'params' => ['tenant_name' => $tenantName],
                'confidence' => 0.9,
            ],
            'cuánto me deben' => [
                'domain' => 'app',
                'intent' => 'pending_balance',
                'params' => [],
                'confidence' => 0.95,
            ],
        ]);

        $this->assistant()->ask("actualiza a {$tenantName}", [], $this->user->id);
        $this->assertSame('update_clarify', session('assistant.form.'.$this->user->id)['form'] ?? null);

        $reply = $this->assistant()->ask('cuánto me deben en total', [], $this->user->id);

        $this->assertSame('pending_balance', $reply->intent);
        // El wizard sigue vivo en sesion: el usuario puede retomarlo.
        $this->assertSame('update_clarify', session('assistant.form.'.$this->user->id)['form'] ?? null);
    }

    // ---------------------------------------------------------------
    // Insights por foco, guia por tema y resultado en el mismo mensaje
    // ---------------------------------------------------------------

    #[Test]
    public function business_insights_cambia_secciones_segun_el_foco(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'business_insights',
            'params' => [],
            'confidence' => 0.9,
        ]);

        $improve = $this->assistant()->ask('que puntos puedo mejorar de mi negocio', [], $this->user->id);
        $risk = $this->assistant()->ask('analiza a fondo mi portafolio y dime que riesgos tengo', [], $this->user->id);
        $income = $this->assistant()->ask('que pasaria si sigo asi con los mismos ingresos', [], $this->user->id);

        $improveData = $improve->data['insights'];
        $this->assertSame('improve', $improveData['focus']);
        $this->assertFalse($improveData['sections']['forecast']);
        $this->assertTrue($improveData['sections']['tenants']);
        $this->assertTrue($improveData['sections']['recommendations']);

        $riskData = $risk->data['insights'];
        $this->assertSame('risk', $riskData['focus']);
        $this->assertFalse($riskData['sections']['forecast']);

        $incomeData = $income->data['insights'];
        $this->assertSame('income', $incomeData['focus']);
        $this->assertTrue($incomeData['sections']['forecast']);
        $this->assertFalse($incomeData['sections']['tenants']);
    }

    #[Test]
    public function business_insights_de_entidad_oculta_el_pronostico_del_portafolio(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'business_insights',
            'params' => [],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('vale la pena tener a '.$this->rental->tenant->name, [], $this->user->id);
        $insights = $reply->data['insights'];

        $this->assertSame('tenant', $insights['focus']);
        $this->assertFalse($insights['sections']['forecast']);
        $this->assertNotEmpty($insights['tenants']);
    }

    #[Test]
    public function app_guide_infiere_el_tema_por_la_pregunta(): void
    {
        $setup = app(AssistantToolInvoker::class)->invoke('app_guide', [
            'topic' => 'start',
            '_question' => 'guíame para registrar mis propiedades e inquilinos',
        ], $this->user->id);

        $this->assertSame('Registrar propiedades e inquilinos', $setup->data['guide']['title']);
        $this->assertCount(5, $setup->data['guide']['steps']);

        $collections = app(AssistantToolInvoker::class)->invoke('app_guide', [
            'topic' => '',
            '_question' => 'guíame para cobrar a mis inquilinos',
        ], $this->user->id);

        $this->assertStringContainsString('Recaudo', $collections->data['guide']['title']);
    }

    #[Test]
    public function app_guide_sin_tema_concreto_sigue_siendo_la_onboarding(): void
    {
        $reply = app(AssistantToolInvoker::class)->invoke('app_guide', [
            'topic' => 'start',
            '_question' => 'soy nuevo, ¿qué hago primero?',
        ], $this->user->id);

        $this->assertStringContainsString('Primeros pasos', $reply->data['guide']['title']);
        $this->assertCount(10, $reply->data['guide']['steps']);
    }

    #[Test]
    public function confirmar_tras_otro_mensaje_resuelve_en_el_mismo_burbuja(): void
    {
        $this->actingAs($this->user);
        $this->fakeGeminiRouter([
            'ana ruiz' => [
                'domain' => 'app',
                'intent' => 'create_tenant',
                'params' => [
                    'name' => 'Ana Ruiz',
                    'document' => '555',
                    'phone_number' => '3001112233',
                ],
                'confidence' => 0.95,
            ],
            'mis inquilinos' => [
                'domain' => 'app',
                'intent' => 'list_tenants',
                'params' => [],
                'confidence' => 0.9,
            ],
        ]);

        $component = Livewire::test(AssistantChat::class)
            ->set('input', 'agrega un inquilino llamado Ana Ruiz CC 555 tel 3001112233')
            ->call('send')
            ->assertSee('Ana Ruiz')
            ->set('input', 'mis inquilinos')
            ->call('send');

        $before = count($component->get('messages'));

        $component->call('confirmAction', 1);

        $messages = $component->get('messages');
        $this->assertCount($before, $messages);
        $this->assertNull($messages[1]['action']);
        $this->assertNotNull($messages[1]['resolved_action']);
        $this->assertStringNotContainsString('Voy a', $messages[1]['text']);
        $this->assertDatabaseHas('tenants', [
            'user_id' => $this->user->id,
            'name' => 'Ana Ruiz',
        ]);
    }

    #[Test]
    public function documento_generado_queda_en_el_mismo_mensaje_que_lo_propuso(): void
    {
        $this->actingAs($this->user);

        // El estado de cuenta exige facturas en el periodo: sin ellas la
        // propuesta se bloquea para no entregar un documento vacio.
        Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'number' => 'FV-TEST-0001',
            'amount' => 800000,
            'status' => InvoiceStatus::EMITIDA,
            'issued_at' => today()->startOfMonth()->toDateString(),
            'due_at' => today()->addDays(5)->toDateString(),
            'period' => today()->format('Y-m'),
        ]);

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'generate_document',
            'params' => [
                'document_type' => 'monthly_statement',
                'rental_name' => $this->rental->name,
                'period' => today()->format('Y-m'),
            ],
            'confidence' => 0.9,
        ]);

        $component = Livewire::test(AssistantChat::class)
            ->set('input', 'genera el estado de cuenta del arriendo')
            ->call('send')
            ->set('input', 'mis inquilinos')
            ->call('send');

        $before = count($component->get('messages'));
        $component->call('confirmAction', 1);

        $messages = $component->get('messages');
        $this->assertCount($before, $messages);
        $this->assertNull($messages[1]['action']);
        $this->assertNotNull($messages[1]['resolved_action']);
        $this->assertNotNull($messages[1]['data']['file']['token'] ?? null);
        $this->assertNotEmpty($messages[1]['data']['file']['filename'] ?? null);
    }

    #[Test]
    public function update_sin_datos_muestra_el_formulario_vacio_con_todos_los_campos(): void
    {
        $tenantName = (string) $this->rental->tenant->name;

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'update_tenant',
            'params' => ['tenant_name' => $tenantName],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask("actualiza a {$tenantName}", [], $this->user->id);

        // El pendingForm trae TODOS los campos editables con su tipo:
        // el usuario llena solo lo que quiere cambiar.
        $fields = collect($reply->pendingForm['fields'] ?? [])->keyBy('key');

        $this->assertSame('update_clarify', session('assistant.form.'.$this->user->id)['form'] ?? null);
        $this->assertSame('form', session('assistant.form.'.$this->user->id)['data']['mode'] ?? null);
        $this->assertTrue($fields->has('name'));
        $this->assertTrue($fields->has('document'));
        $this->assertTrue($fields->has('phone_number'));
        $this->assertTrue($fields->has('email'));
        $this->assertSame('text', $fields->get('name')['kind'] ?? null);

        // Submit del form: solo viajan los campos diligenciados; el
        // vacio no se toca y sale la propuesta con diff.
        $proposal = app(AssistantToolInvoker::class)->submitUpdateForm([
            'name' => '',
            'document' => '',
            'phone_number' => '3001112233',
            'email' => 'nuevo@correo.com',
        ], $this->user->id);

        $this->assertSame('update_tenant', $proposal->action['type'] ?? null);
        $this->assertSame('3001112233', $proposal->action['params']['phone_number'] ?? null);
        $this->assertSame('nuevo@correo.com', $proposal->action['params']['email'] ?? null);
        $this->assertArrayNotHasKey('name', $proposal->action['params']);
        $this->assertNull(session('assistant.form.'.$this->user->id));
    }

    #[Test]
    public function lookup_data_resuelve_la_referencia_antes_de_proponer_la_accion(): void
    {
        Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'number' => 'FV-2026-0001',
            'amount' => 800000,
            'status' => InvoiceStatus::EMITIDA,
            'issued_at' => today()->startOfMonth()->toDateString(),
            'due_at' => today()->addDays(5)->toDateString(),
            'period' => today()->format('Y-m'),
        ]);

        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-test');

        // El modelo primero pide consultar la BD (lookup_data) y, con las
        // filas reales, devuelve la accion ya resuelta.
        $call = 0;
        Http::fake(function () use (&$call) {
            $call++;
            $payload = $call === 1
                ? [
                    'domain' => 'app',
                    'intent' => 'lookup_data',
                    'confidence' => 0.9,
                    'params' => ['sql' => 'SELECT i.number, i.due_at FROM invoices i WHERE i.user_id = '.((int) $this->user->id).' AND i.status IN (\'emitida\',\'vencida\') ORDER BY i.due_at LIMIT 5'],
                ]
                : [
                    'domain' => 'app',
                    'intent' => 'propose_record_payment',
                    'confidence' => 0.9,
                    'params' => ['invoice_number' => 'FV-2026-0001', 'amount' => 100000],
                ];

            return Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($payload)]]]]],
            ]);
        });

        $reply = $this->assistant()->ask('registra un pago a la factura mas antigua', [], $this->user->id);

        $this->assertSame('record_payment', $reply->action['type'] ?? null);
        Http::assertSentCount(2);
    }

    #[Test]
    public function el_digest_del_portafolio_incluye_las_facturas_pendientes(): void
    {
        Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'number' => 'FV-2026-0009',
            'amount' => 800000,
            'status' => InvoiceStatus::EMITIDA,
            'issued_at' => today()->startOfMonth()->toDateString(),
            'due_at' => today()->addDays(5)->toDateString(),
            'period' => today()->format('Y-m'),
        ]);

        $digest = app(\App\Services\Assistant\PortfolioContextBuilder::class)->digestFor($this->user->id);
        $pending = collect($digest->pendingInvoices)->keyBy('number');

        $this->assertTrue($pending->has('FV-2026-0009'));
        $this->assertSame(800000.0, $pending->get('FV-2026-0009')['balance']);
        $this->assertSame($this->rental->name, $pending->get('FV-2026-0009')['rental']);
    }

    #[Test]
    public function create_tenant_sin_datos_abre_el_formulario_dinamico(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'create_tenant',
            'params' => ['name' => 'Beatriz Ríos'],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('registra un inquilino nuevo', [], $this->user->id);

        $this->assertSame('create_tenant', $reply->pendingForm['form'] ?? null);

        $fields = collect($reply->pendingForm['fields'] ?? [])->keyBy('key');

        $this->assertSame(['name', 'document', 'phone_number', 'email'], $fields->keys()->all());
        // Lo que Gemini ya extrajo llega pre-llenado; el resto vacio.
        $this->assertSame('Beatriz Ríos', $fields->get('name')['value'] ?? null);
        $this->assertSame('', $fields->get('document')['value'] ?? null);
        $this->assertSame('tel', $fields->get('phone_number')['kind'] ?? null);
        $this->assertSame('email', $fields->get('email')['kind'] ?? null);
        $this->assertTrue($fields->get('name')['required'] ?? false);
        $this->assertFalse($fields->get('email')['required'] ?? true);

        $this->assertSame('tenant', session('assistant.createform.'.$this->user->id)['entity'] ?? null);
    }

    #[Test]
    public function submit_create_form_completa_los_datos_y_propone_la_accion(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'create_tenant',
            'params' => ['name' => 'Beatriz Ríos'],
            'confidence' => 0.9,
        ]);

        $this->assistant()->ask('registra un inquilino nuevo', [], $this->user->id);

        // El usuario diligencia el form: nombre ya venia, el resto lo escribe.
        $proposal = app(AssistantToolInvoker::class)->submitForm([
            'name' => 'Beatriz Ríos',
            'document' => '1122334455',
            'phone_number' => '3005556677',
            'email' => '',
        ], $this->user->id);

        $this->assertSame('create_tenant', $proposal->action['type'] ?? null);
        $this->assertSame('Beatriz Ríos', $proposal->action['params']['name'] ?? null);
        $this->assertSame('1122334455', $proposal->action['params']['document'] ?? null);
        $this->assertSame('3005556677', $proposal->action['params']['phone_number'] ?? null);
        $this->assertNull(session('assistant.createform.'.$this->user->id));
    }

    #[Test]
    public function cancel_form_limpia_tambien_la_sesion_de_creacion(): void
    {
        $this->actingAs($this->user);

        $chat = \App\Models\AssistantChat::query()->create([
            'user_id' => $this->user->id,
            'title' => null,
        ]);

        // Batch de creacion en curso: el boton Cancelar del wizard debe
        // tirar la cola igual que la cancelacion por texto ("cancela").
        session()->put('assistant.createform.'.$this->user->id, [
            'entity' => 'tenant',
            'values' => ['name' => 'Parcial'],
            'queue' => [['entity' => 'tenant', 'params' => ['name' => 'Pendiente']]],
            'collected' => [],
            'step' => 1,
            'total' => 2,
        ]);
        session()->put('assistant.form.'.$this->user->id, ['form' => 'delivery_act', 'data' => []]);

        Livewire::test(AssistantChat::class)
            ->set('activeChatId', $chat->id)
            ->set('pendingForm', ['form' => 'create_tenant', 'step' => 1, 'total_steps' => 2, 'prompt' => 'x', 'fields' => []])
            ->call('cancelForm');

        $this->assertNull(session('assistant.createform.'.$this->user->id));
        $this->assertNull(session('assistant.form.'.$this->user->id));
    }

    #[Test]
    public function create_suelto_no_hereda_la_cola_de_un_batch_abandonado(): void
    {
        // Batch de 2 inquilinos abandonado a mitad de camino (sin
        // cancelar): una peticion nueva de creacion no debe resucitar
        // la cola — solo conserva los valores del mismo formulario.
        session()->put('assistant.createform.'.$this->user->id, [
            'entity' => 'tenant',
            'values' => ['name' => 'Parcial'],
            'queue' => [['entity' => 'tenant', 'params' => ['name' => 'Pendiente']]],
            'collected' => [],
            'step' => 1,
            'total' => 2,
        ]);

        $reply = app(\App\Services\Assistant\AssistantToolInvoker::class)
            ->invoke('create_tenant', ['name' => 'Nuevo Inquilino'], $this->user->id);

        $this->assertSame(1, $reply->pendingForm['step'] ?? null);
        $this->assertSame(1, $reply->pendingForm['total_steps'] ?? null);

        $session = app(\App\Services\Assistant\AssistantFormSession::class)
            ->getCreate($this->user->id);

        $this->assertSame([], $session['queue']);
        $this->assertSame([], $session['collected']);
        $this->assertSame('Nuevo Inquilino', $session['values']['name'] ?? null);
    }

    #[Test]
    public function el_wizard_permite_volver_al_paso_anterior(): void
    {
        $invoker = app(\App\Services\Assistant\AssistantToolInvoker::class);

        $reply = $invoker->invokeBatch([
            ['intent' => 'create_tenant', 'params' => ['name' => 'Uno']],
            ['intent' => 'create_tenant', 'params' => ['name' => 'Dos']],
        ], $this->user->id);

        $this->assertSame(1, $reply->pendingForm['step'] ?? null);
        $this->assertSame(2, $reply->pendingForm['total_steps'] ?? null);

        // Diligencia el primero completo: avanza al segundo.
        $reply = $invoker->submitForm([
            '__form' => 'create_tenant',
            'name' => 'Uno',
            'document' => '111',
            'phone_number' => '3001112233',
        ], $this->user->id);

        $this->assertSame(2, $reply->pendingForm['step'] ?? null);

        // "Anterior": vuelve al primero con lo enviado y la cola intacta.
        $back = $invoker->previousFormStep($this->user->id);

        $this->assertSame(1, $back->pendingForm['step'] ?? null);
        $this->assertSame(2, $back->pendingForm['total_steps'] ?? null);

        $session = app(\App\Services\Assistant\AssistantFormSession::class)
            ->getCreate($this->user->id);

        $this->assertSame('Uno', $session['values']['name'] ?? null);
        $this->assertSame('111', $session['values']['document'] ?? null);
        $this->assertCount(1, $session['queue']);
        // La accion del item en curso no queda recolectada: se vuelve a
        // generar al reenviar el formulario.
        $this->assertCount(0, $session['collected']);
    }

    #[Test]
    public function create_rental_form_tiene_los_campos_con_sus_tipos_reales(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'create_rental',
            'params' => ['tenant_name' => 'Beatriz Ríos'],
            'confidence' => 0.9,
        ]);

        $reply = $this->assistant()->ask('agenda un arriendo nuevo', [], $this->user->id);

        $this->assertSame('create_rental', $reply->pendingForm['form'] ?? null);

        $fields = collect($reply->pendingForm['fields'] ?? [])->keyBy('key');

        $this->assertSame('number', $fields->get('monthly_amount')['kind'] ?? null);
        $this->assertSame('date', $fields->get('start_date')['kind'] ?? null);
        $this->assertSame('select', $fields->get('billing_cadence')['kind'] ?? null);
        $this->assertSame('select', $fields->get('rental_type')['kind'] ?? null);
        $this->assertNotEmpty($fields->get('billing_cadence')['options'] ?? []);
        $this->assertSame('Beatriz Ríos', $fields->get('tenant_name')['value'] ?? null);
    }

    // ---------------------------------------------------------------
    // Opciones A-D y graficas del reporte
    // ---------------------------------------------------------------

    #[Test]
    public function el_modelo_puede_proponer_opciones_abcd_cuando_la_pregunta_es_ambigua(): void
    {
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'unknown',
            'params' => [],
            'confidence' => 0.9,
            'options' => [
                'la factura de Camila Torres',
                'el estado de cuenta de Camila Torres',
                'el comprobante de pago de Camila Torres',
                'la carta de reajuste de Camila Torres',
                'una quinta que debe ignorarse',
            ],
        ]);

        $reply = $this->assistant()->ask('dame el documento de camila', [], $this->user->id);

        // Max 4 opciones y texto plano sanitizado.
        $this->assertCount(4, $reply->options);
        $this->assertSame('la factura de Camila Torres', $reply->options[0]);
    }

    #[Test]
    public function las_opciones_sobreviven_al_mensaje_del_chat(): void
    {
        $this->actingAs($this->user);
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'unknown',
            'params' => [],
            'confidence' => 0.9,
            'options' => ['primera lectura', 'segunda lectura'],
        ]);

        Livewire::test(AssistantChat::class)
            ->set('input', 'ayudame con esto')
            ->call('send')
            ->assertSee('primera lectura')
            ->assertSee('segunda lectura');

        $this->assertSame(
            ['primera lectura', 'segunda lectura'],
            AssistantMessage::query()->latest('id')->first()->payload['options'] ?? null,
        );
    }

    #[Test]
    public function documento_ambiguo_ofrece_cuatro_opciones_como_respuestas(): void
    {
        $reply = app(AssistantToolInvoker::class)->invoke('generate_document', [
            'doc_type' => 'documento',
            'rental_name' => 'Local norte',
        ], $this->user->id);

        $this->assertCount(4, $reply->options);
        $this->assertStringContainsString('factura', $reply->options[0]);
        $this->assertStringContainsString('estado de cuenta', $reply->options[1]);
    }

    #[Test]
    public function el_reporte_puede_incluir_graficas_saneadas(): void
    {
        // El mismo payload sirve para classify (ignora 'report') y para
        // classifyReport (lee 'report' directo, sin rondas de lookup).
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'business_insights',
            'params' => [],
            'confidence' => 0.9,
            'report' => [
                'title' => 'Recaudo por mes',
                'intro' => 'Así va tu recaudo.',
                'sections' => [[
                    'title' => 'Evolución',
                    'blocks' => [
                        [
                            'type' => 'chart',
                            'chart' => 'bar',
                            'title' => 'Pagos por mes',
                            'labels' => ['2026-08', '2026-09', '2026-10'],
                            'values' => [1000000, 1500000, 900000],
                            'unit' => '$',
                        ],
                        [
                            'type' => 'chart',
                            'chart' => 'pie',
                            'labels' => ['a', 'b'],
                            'values' => [1, 2],
                        ],
                    ],
                ]],
            ],
        ]);

        $reply = $this->assistant()->ask('analiza mi recaudo', [], $this->user->id);

        $blocks = $reply->data['report']['sections'][0]['blocks'] ?? [];

        // La grafica valida queda; la de tipo desconocido se descarta.
        $this->assertCount(1, $blocks);
        $this->assertSame('chart', $blocks[0]['type']);
        $this->assertSame('bar', $blocks[0]['chart']);
        $this->assertSame(['2026-08', '2026-09', '2026-10'], $blocks[0]['labels']);
        $this->assertSame([1000000.0, 1500000.0, 900000.0], $blocks[0]['values']);
    }

    // ---------------------------------------------------------------
    // Documento personalizado (custom_document)
    // ---------------------------------------------------------------

    #[Test]
    public function custom_document_compone_la_especificacion_y_propone_la_accion(): void
    {
        // El mismo payload sirve para classify (lee intent/params) y
        // para el compositor de documentos (lee 'document' directo).
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'custom_document',
            'params' => ['format' => 'pdf'],
            'confidence' => 0.9,
            'document' => [
                'title' => 'Estado del portafolio',
                'intro' => 'Resumen general del negocio.',
                'fields' => ['Periodo' => 'Noviembre 2026'],
                'sections' => [[
                    'title' => 'Cartera activa',
                    'text' => 'Tienes varios arriendos activos.',
                    'table' => [
                        'headers' => ['Arriendo', 'Canon'],
                        'rows' => [['Local norte', '$800.000']],
                    ],
                ]],
            ],
        ]);

        $reply = $this->assistant()->ask('hazme un pdf con el estado de mi portafolio', [], $this->user->id);

        $this->assertSame('generate_document', $reply->action['type']);
        $this->assertSame('custom', $reply->action['params']['kind']);
        $this->assertSame('pdf', $reply->action['params']['format']);
        $this->assertSame('Estado del portafolio', $reply->action['params']['spec']['title']);
        $this->assertNotEmpty($reply->action['params']['spec']['sections']);
    }

    #[Test]
    public function custom_document_sin_especificacion_cae_al_fallback(): void
    {
        // Si el modelo clasifica custom_document pero el compositor no
        // logra producir spec (o se invoca directo sin spec), el
        // invoker responde el mensaje de fallback en vez de romper.
        $reply = app(AssistantToolInvoker::class)->invoke('custom_document', [], $this->user->id);

        $this->assertNull($reply->action);
        $this->assertNotSame('', trim($reply->text));
    }

    #[Test]
    public function custom_document_se_entrega_en_los_tres_formatos(): void
    {
        $service = app(\App\Documents\DocumentService::class);

        $spec = [
            'title' => 'Carta de cobro especial',
            'intro' => 'Carta dirigida al inquilino.',
            'sections' => [[
                'title' => 'Detalle',
                'text' => 'Se detalla el saldo.',
                'table' => ['headers' => ['Concepto', 'Valor'], 'rows' => [['Saldo', '$500.000']]],
            ]],
        ];

        $text = $service->customDocumentFor($spec, 'text');
        $this->assertStringEndsWith('.txt', $text->filename());
        $this->assertStringContainsString('Carta de cobro especial', $text->content());
        $this->assertStringContainsString('Detalle', $text->content());
        $this->assertStringContainsString('$500.000', $text->content());

        $json = $service->customDocumentFor($spec, 'json');
        $this->assertStringEndsWith('.json', $json->filename());
        $decoded = json_decode($json->content(), true);
        $this->assertSame('Carta de cobro especial', $decoded['title']);
        $this->assertNotEmpty($decoded['sections']);

        $pdf = $service->customDocumentFor($spec, 'pdf');
        $this->assertStringEndsWith('.pdf', $pdf->filename());
        $this->assertStringStartsWith('%PDF', $pdf->content());
    }

    #[Test]
    public function custom_document_saneada_descarta_lo_que_no_es_util(): void
    {
        // El spec viene de params persistidos: se sanea en la frontera
        // y lo vacio/no estructurado no llega al documento.
        $document = \App\Documents\CustomDocument::fromSpec([
            'title' => str_repeat('T', 300),
            'sections' => [
                ['title' => '', 'text' => '', 'fields' => [], 'table' => null],
                'no-es-array',
                ['title' => 'Válida', 'text' => 'Contenido'],
            ],
            'fields' => ['' => 'sin etiqueta', 'Ok' => 'valor'],
        ], new \App\Documents\Rendering\PlainTextRenderer);

        $body = $document->body();

        $this->assertLessThanOrEqual(141, mb_strlen($body->title));
        $this->assertCount(1, $body->sections);
        $this->assertSame('Válida', $body->sections[0]['title']);
        $this->assertSame(['Ok' => 'valor'], $body->fields);
    }

    #[Test]
    public function ejecutar_custom_document_genera_el_archivo(): void
    {
        $result = app(AssistantActionExecutor::class)->execute([
            'type' => 'generate_document',
            'params' => [
                'kind' => 'custom',
                'format' => 'text',
                'spec' => [
                    'title' => 'Reporte de prueba',
                    'sections' => [['title' => 'Uno', 'text' => 'Hola']],
                ],
            ],
        ], $this->user->id);

        $this->assertNotNull($result->data['file']['token'] ?? null);
        $this->assertStringEndsWith('.txt', $result->data['file']['filename']);
    }

    // ---------------------------------------------------------------
    // Megaplan: relaciones, regenerar, feedback, briefing
    // ---------------------------------------------------------------

    #[Test]
    public function entity_relations_anida_propiedad_con_su_inquilino(): void
    {
        $this->rental->tenant->update(['name' => 'Ana Prueba']);
        $this->rental->property->update(['name' => 'Casa Naciente']);

        $reply = app(AssistantToolInvoker::class)->invoke('entity_relations', [
            'entity_type' => 'property',
            'name' => 'Casa Naciente',
            'relations' => ['tenant'],
        ], $this->user->id);

        $rows = $reply->data['results'] ?? [];

        $this->assertNotEmpty($rows);
        $this->assertSame('Casa Naciente', $rows[0]['name'] ?? $rows[0]['property'] ?? null);
        $this->assertNotEmpty($rows[0]['tenant'] ?? []);
    }

    #[Test]
    public function entity_relations_anida_arriendo_con_sus_facturas(): void
    {
        Invoice::factory()->create([
            'user_id' => $this->user->id,
            'rental_id' => $this->rental->id,
            'number' => 'F-TEST-1',
            'status' => InvoiceStatus::EMITIDA,
        ]);

        $reply = app(AssistantToolInvoker::class)->invoke('entity_relations', [
            'entity_type' => 'rental',
            'name' => 'Local norte',
            'relations' => ['invoices'],
        ], $this->user->id);

        $rows = $reply->data['results'] ?? [];

        $this->assertNotEmpty($rows);
        $invoices = $rows[0]['invoices'] ?? [];
        $this->assertNotEmpty($invoices);
        $this->assertSame('F-TEST-1', $invoices[0]['number'] ?? null);
    }

    #[Test]
    public function entity_relations_sin_params_responde_uso(): void
    {
        $reply = app(AssistantToolInvoker::class)->invoke('entity_relations', [], $this->user->id);

        $this->assertNotEmpty($reply->text);
        $this->assertNull($reply->data['results'] ?? null);
    }

    #[Test]
    public function regenerar_rehace_la_ultima_respuesta_sin_duplicar(): void
    {
        $this->actingAs($this->user);
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'unknown',
            'params' => [],
            'confidence' => 0.9,
        ]);

        $chat = Livewire::test(AssistantChat::class)
            ->set('input', 'hola')
            ->call('send');

        $before = count($chat->get('messages'));

        // Segunda pregunta para tener dos turnos y regenerar el ultimo.
        $chat->set('input', 'otra pregunta')->call('send');
        $afterSend = count($chat->get('messages'));

        $chat->call('regenerateLast');

        $messages = $chat->get('messages');
        $last = end($messages);
        $prev = $messages[count($messages) - 2];

        // Mismo numero de mensajes: la respuesta vieja se reemplazo.
        $this->assertCount($afterSend, $messages);
        $this->assertSame('otra pregunta', $prev['text']);
        $this->assertSame('assistant', $last['role']);
    }

    #[Test]
    public function feedback_se_guarda_y_se_alterna(): void
    {
        $this->actingAs($this->user);
        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'unknown',
            'params' => [],
            'confidence' => 0.9,
        ]);

        $chat = Livewire::test(AssistantChat::class)
            ->set('input', 'hola')
            ->call('send');

        $messages = $chat->get('messages');
        $last = end($messages);
        $messageId = $last['id'];

        $chat->call('setFeedback', $messageId, 'up');
        $this->assertSame('up', AssistantMessage::findOrFail($messageId)->payload['feedback'] ?? null);

        // Segundo clic en el mismo valor lo quita.
        $chat->call('setFeedback', $messageId, 'up');
        $this->assertNull(AssistantMessage::findOrFail($messageId)->payload['feedback'] ?? null);

        $chat->call('setFeedback', $messageId, 'down');
        $this->assertSame('down', AssistantMessage::findOrFail($messageId)->payload['feedback'] ?? null);
    }

    #[Test]
    public function delete_chat_no_borra_el_briefing(): void
    {
        $this->actingAs($this->user);

        $briefing = \App\Models\AssistantChat::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Briefing diario',
            'kind' => \App\Models\AssistantChat::KIND_BRIEFING,
        ]);

        Livewire::test(AssistantChat::class)->call('deleteChat', $briefing->id);

        $this->assertDatabaseHas('assistant_chats', ['id' => $briefing->id]);
    }

    #[Test]
    public function assistant_brief_crea_chat_protegido_y_es_idempotente(): void
    {
        $this->artisan('assistant:brief')->assertSuccessful();

        $chat = \App\Models\AssistantChat::query()
            ->where('user_id', $this->user->id)
            ->where('kind', \App\Models\AssistantChat::KIND_BRIEFING)
            ->first();

        $this->assertNotNull($chat);
        $this->assertSame('Briefing diario', $chat->title);
        $this->assertSame(1, AssistantMessage::query()->where('chat_id', $chat->id)->count());

        // Segunda corrida el mismo dia no duplica el mensaje.
        $this->artisan('assistant:brief')->assertSuccessful();
        $this->assertSame(1, AssistantMessage::query()->where('chat_id', $chat->id)->count());
    }

    #[Test]
    public function reporte_se_renderiza_a_pdf(): void
    {
        $pdf = app(\App\Services\Assistant\AssistantReportPdf::class)->render([
            'title' => 'Analisis de prueba',
            'intro' => 'Resumen.',
            'sections' => [[
                'title' => 'Datos',
                'blocks' => [
                    ['type' => 'metrics', 'items' => [
                        ['label' => 'Vencido', 'value' => '1M', 'tone' => 'danger'],
                    ]],
                    ['type' => 'chart', 'chart' => 'bar', 'labels' => ['Ene', 'Feb'], 'values' => [10, 20]],
                    ['type' => 'chart', 'chart' => 'donut', 'labels' => ['A', 'B'], 'values' => [30, 70]],
                    ['type' => 'table', 'columns' => ['A', 'B'], 'rows' => [['x', 'y']]],
                    ['type' => 'callouts', 'items' => [['title' => 'Ojo', 'text' => 'Riesgo', 'tone' => 'warning']]],
                ],
            ]],
        ]);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(2000, strlen($pdf));
    }

    #[Test]
    public function navigator_resuelve_entidad_a_url_filtrada(): void
    {
        $tenant = $this->rental->tenant;
        $tenant->update(['name' => 'Bruno Navigator']);

        // Un inquilino con arriendos resuelve a la ficha del arriendo
        // (resume inquilino + facturas); uno sin arriendos cae a la
        // lista de inquilinos filtrada por nombre.
        $single = app(\App\Services\Assistant\AssistantNavigator::class)
            ->urlFor('tenants', 'Bruno Navigator', null, null, $this->user->id);
        $this->assertStringContainsString('/rentals/', $single['url']);

        Tenant::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Sin Arriendo',
        ]);

        $resolved = app(\App\Services\Assistant\AssistantNavigator::class)
            ->urlFor('tenants', 'Sin Arriendo', null, null, $this->user->id);

        $this->assertNotNull($resolved['url'] ?? null);
        $this->assertStringContainsString('tableSearch', $resolved['url']);
    }

    // ---------------------------------------------------------------
    // Modo exhaustivo y proveedor OpenAI-compatible
    // ---------------------------------------------------------------

    #[Test]
    public function el_flag_de_epoca_de_la_ia_expande_el_reporte(): void
    {
        // La IA decide la profundidad: con "deep"=true el sanitizer
        // acepta hasta 20 secciones; sin el flag el tope normal es 6.
        $sections = array_map(
            fn (int $i): array => ['title' => "Sección {$i}", 'blocks' => [['type' => 'text', 'text' => 'Texto']]],
            range(1, 10),
        );

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'business_insights',
            'params' => [],
            'confidence' => 0.9,
            'deep' => true,
            'report' => ['title' => 'Análisis', 'intro' => 'Intro', 'sections' => $sections],
        ]);

        $reply = $this->assistant()->ask('dame un análisis exhaustivo del portafolio', [], $this->user->id);

        $this->assertCount(10, $reply->data['report']['sections'] ?? []);
    }

    #[Test]
    public function sin_flag_de_epoca_el_reporte_queda_en_el_tope_normal(): void
    {
        $sections = array_map(
            fn (int $i): array => ['title' => "Sección {$i}", 'blocks' => [['type' => 'text', 'text' => 'Texto']]],
            range(1, 10),
        );

        $this->fakeGemini([
            'domain' => 'app',
            'intent' => 'business_insights',
            'params' => [],
            'confidence' => 0.9,
            'report' => ['title' => 'Análisis', 'intro' => 'Intro', 'sections' => $sections],
        ]);

        $reply = $this->assistant()->ask('analiza mi recaudo', [], $this->user->id);

        $this->assertCount(6, $reply->data['report']['sections'] ?? []);
    }

    #[Test]
    public function driver_openai_compatible_usa_chat_completions(): void
    {
        config()->set('services.ai.driver', 'openai_compatible');
        config()->set('services.ai.base_url', 'https://api.groq.com/openai/v1');
        config()->set('services.ai.key', 'gsk_test');
        config()->set('services.ai.model', 'openai/gpt-oss-120b');
        // Sin clave de Gemini: el driver openai debe bastar por si solo.
        config()->set('services.gemini.key', '');

        $sent = null;

        Http::fake(function ($request) use (&$sent) {
            $sent = $request;

            return Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'domain' => 'app',
                        'intent' => 'list_tenants',
                        'params' => [],
                        'confidence' => 0.95,
                    ])],
                ]],
            ]);
        });

        $reply = $this->assistant()->ask('lista los inquilinos', [], $this->user->id);

        $this->assertNotNull($reply);
        $this->assertNotSame('', $reply->text);

        $this->assertNotNull($sent);
        $this->assertStringContainsString('/chat/completions', $sent->url());
        $this->assertSame('Bearer gsk_test', $sent->headers()['Authorization'][0] ?? null);

        $payload = json_decode((string) $sent->body(), true);
        $this->assertSame('openai/gpt-oss-120b', $payload['model']);
        $this->assertArrayHasKey('messages', $payload);
        $this->assertSame('system', $payload['messages'][0]['role']);
    }

    #[Test]
    public function driver_openai_compatible_traduce_modo_json(): void
    {
        config()->set('services.ai.driver', 'openai_compatible');
        config()->set('services.ai.base_url', 'https://api.groq.com/openai/v1');
        config()->set('services.ai.key', 'gsk_test');
        config()->set('services.ai.model', 'openai/gpt-oss-120b');
        config()->set('services.gemini.key', '');

        $sent = null;

        Http::fake(function ($request) use (&$sent) {
            $sent = $request;

            return Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'domain' => 'app',
                        'intent' => 'list_tenants',
                        'params' => [],
                        'confidence' => 0.95,
                    ])],
                ]],
            ]);
        });

        $this->assistant()->ask('lista los inquilinos', [], $this->user->id);

        $payload = json_decode((string) $sent->body(), true);

        // La clasificacion pide JSON: debe llegar como response_format.
        $this->assertSame('json_object', $payload['response_format']['type'] ?? null);
    }
}
