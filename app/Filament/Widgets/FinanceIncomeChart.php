<?php

namespace App\Filament\Widgets;

/**
 * El grafico de ingresos ocupa dos tercios del ancho en la pagina de
 * Finanzas. Es una subclase y no un cambio en IncomeChart porque en el
 * dashboard ese widget comparte fila con otros.
 */
class FinanceIncomeChart extends IncomeChart
{
    protected int|string|array $columnSpan = 2;
}
