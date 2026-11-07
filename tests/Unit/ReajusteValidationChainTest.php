<?php

namespace Tests\Unit;

use App\Models\Rental;
use App\Services\Adjustment\Validation\AdjustmentContext;
use App\Services\Adjustment\Validation\ReajusteRule;
use App\Services\Adjustment\Validation\ReajusteValidationChain;
use App\Services\Adjustment\Validation\RuleResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La cadena del reajuste tiene dos recorridos distintos.
 *
 * En modo decision (check/handle) se corta en el primer bloqueo porque las
 * reglas posteriores asumen que el reajuste ya tiene los datos minimos. En modo
 * auditoria (runAll/messages) se ejecuta todo aunque haya un bloqueo, porque la
 * pantalla necesita mostrar tambien los avisos que el arrendador debe revisar.
 */
class ReajusteValidationChainTest extends TestCase
{
    private function context(): AdjustmentContext
    {
        return new AdjustmentContext(
            rental: new Rental,
            effectiveFrom: new \DateTimeImmutable('2026-01-01'),
            ipc: null,
            requestedRent: 1_000_000,
        );
    }

    private function rule(string $name, RuleResult $result): RecordingReajusteRule
    {
        return new RecordingReajusteRule($name, $result);
    }

    #[Test]
    public function handle_corta_en_la_primera_regla_bloqueante(): void
    {
        $blocker = $this->rule('blocker', RuleResult::blocking('Falta el IPC.'));
        $after = $this->rule('after', RuleResult::warning('Aviso posterior.'));
        $blocker->setNext($after);
        $chain = new ReajusteValidationChain($blocker);

        $summary = $chain->check($this->context());

        $this->assertTrue($summary->isBlocking());
        $this->assertSame('Falta el IPC.', $summary->message);
        $this->assertSame(1, $blocker->calls);
        $this->assertSame(0, $after->calls);
    }

    #[Test]
    public function run_all_recorre_toda_la_cadena_aunque_haya_un_bloqueo(): void
    {
        $blocker = $this->rule('blocker', RuleResult::blocking('Falta el IPC.'));
        $after = $this->rule('after', RuleResult::warning('Aviso posterior.'));
        $blocker->setNext($after);
        $chain = new ReajusteValidationChain($blocker);

        $entries = $chain->runAll($this->context());

        $this->assertCount(2, $entries);
        $this->assertSame(1, $blocker->calls);
        $this->assertSame(1, $after->calls);
        $this->assertSame(
            ['blocker', 'after'],
            array_map(fn (array $entry): string => $entry['rule']->name(), $entries),
        );
    }

    #[Test]
    public function messages_conserva_los_avisos_posteriores_a_un_bloqueo(): void
    {
        $blocker = $this->rule('blocker', RuleResult::blocking('Falta el IPC.'));
        $after = $this->rule('after', RuleResult::warning('Aviso posterior.'));
        $blocker->setNext($after);
        $chain = new ReajusteValidationChain($blocker);

        $messages = $chain->messages($this->context());

        $this->assertSame(['blocking', 'warning'], array_column($messages, 'severity'));
        $this->assertSame(['Falta el IPC.', 'Aviso posterior.'], array_column($messages, 'message'));
    }

    #[Test]
    public function un_aviso_no_corta_la_cadena(): void
    {
        $warning = $this->rule('warning', RuleResult::warning('Aviso inicial.'));
        $after = $this->rule('after', RuleResult::info('Contexto posterior.'));
        $warning->setNext($after);
        $chain = new ReajusteValidationChain($warning);

        $summary = $chain->check($this->context());

        $this->assertTrue($summary->isWarning());
        $this->assertSame(1, $warning->calls);
        $this->assertSame(1, $after->calls);
    }

    #[Test]
    public function rule_names_respeta_el_orden_de_set_next(): void
    {
        $first = $this->rule('first', RuleResult::passes());
        $second = $this->rule('second', RuleResult::passes());
        $third = $this->rule('third', RuleResult::passes());

        $first->setNext($second)->setNext($third);

        $chain = new ReajusteValidationChain($first);

        $this->assertSame(['first', 'second', 'third'], $chain->ruleNames());
    }
}

final class RecordingReajusteRule extends ReajusteRule
{
    public int $calls = 0;

    public function __construct(
        private readonly string $id,
        private readonly RuleResult $result,
    ) {}

    public function article(): string
    {
        return 'Art. de prueba';
    }

    public function check(AdjustmentContext $context): RuleResult
    {
        $this->calls++;

        return $this->result;
    }

    public function name(): string
    {
        return $this->id;
    }
}
