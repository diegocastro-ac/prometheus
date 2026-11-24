<?php

namespace App\Documents\Rendering;

/**
 * Convierte un bloque "chart" del reporte del asistente en SVG inline.
 *
 * El chat pinta las graficas con divs y SVG en el navegador (Chart.js no
 * existe en dompdf): aqui se regenera la misma informacion como SVG
 * plano — rect, polyline, circle y text — que el renderizador SVG de
 * dompdf (php-svg-lib) si sabe dibujar. Las etiquetas van dentro del
 * SVG para que la grafica sea autosuficiente en el PDF.
 */
final class ReportChartSvg
{
    private const PALETTE = ['#475569', '#94a3b8', '#0ea5e9', '#f59e0b', '#14b8a6', '#8b5cf6'];

    private const WIDTH = 640;

    public static function render(array $block): string
    {
        $labels = array_values(array_map(
            fn (mixed $label): string => trim(htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8')),
            (array) ($block['labels'] ?? []),
        ));
        $values = array_values(array_map('floatval', (array) ($block['values'] ?? [])));

        if (count($values) < 2) {
            return '';
        }

        return match ((string) ($block['chart'] ?? 'bar')) {
            'line' => self::line($labels, $values),
            'donut' => self::donut($labels, $values),
            default => self::bar($labels, $values),
        };
    }

    /**
     * Mismo formato abreviado que usa el chat (12k, 1.2M) para que el
     * PDF y la burbuja digan la cifra igual.
     */
    public static function formatValue(float $value): string
    {
        $abs = abs($value);

        if ($abs >= 1_000_000) {
            return rtrim(rtrim(number_format($value / 1_000_000, 1, ',', '.'), '0'), ',').'M';
        }

        if ($abs >= 10_000) {
            return rtrim(rtrim(number_format($value / 1_000, 1, ',', '.'), '0'), ',').'k';
        }

        if ($abs >= 1000) {
            return number_format($value, 0, ',', '.');
        }

        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }

    /**
     * @param  list<string>  $labels
     * @param  list<float>  $values
     */
    private static function bar(array $labels, array $values): string
    {
        $height = 230;
        $padTop = 26;
        $padBottom = 36;
        $plotHeight = $height - $padTop - $padBottom;
        $count = count($values);
        $slot = self::WIDTH / $count;
        $barWidth = min(56.0, $slot * 0.62);
        $max = max(max($values), 0.0001);
        $maxIndex = array_search(max($values), $values, true);

        $svg = ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.self::WIDTH.' '.$height.'" width="'.self::WIDTH.'" height="'.$height.'">'];
        $svg[] = '<line x1="0" y1="'.($height - $padBottom).'" x2="'.self::WIDTH.'" y2="'.($height - $padBottom).'" stroke="#e2e8f0" stroke-width="1"/>';

        foreach ($values as $i => $value) {
            $barHeight = max(2.0, ($value / $max) * $plotHeight);
            $x = ($i * $slot) + (($slot - $barWidth) / 2);
            $y = ($height - $padBottom) - $barHeight;
            $color = $i === $maxIndex ? '#334155' : '#94a3b8';
            $cx = $x + ($barWidth / 2);

            $svg[] = '<rect x="'.round($x, 1).'" y="'.round($y, 1).'" width="'.round($barWidth, 1).'" height="'.round($barHeight, 1).'" rx="2" fill="'.$color.'"/>';
            $svg[] = self::text($cx, $y - 6, self::formatValue($value), 9, '#475569');
            $svg[] = self::text($cx, $height - 12, self::shortLabel($labels[$i] ?? '', 12), 8, '#94a3b8');
        }

        $svg[] = '</svg>';

        return implode('', $svg);
    }

    /**
     * @param  list<string>  $labels
     * @param  list<float>  $values
     */
    private static function line(array $labels, array $values): string
    {
        $width = self::WIDTH;
        $height = 210;
        $padX = 30.0;
        $padY = 24.0;
        $count = count($values);
        $min = min(min($values), 0.0);
        $max = max($values);
        $range = max($max - $min, 0.0001);
        $baseY = $height - $padY;

        $points = [];

        foreach ($values as $i => $value) {
            $x = $count === 1 ? $width / 2 : $padX + ($i / ($count - 1)) * ($width - 2 * $padX);
            $y = $padY + (1 - (($value - $min) / $range)) * ($baseY - $padY);
            $points[] = [$x, $y];
        }

        $linePath = '';
        $areaPath = '';

        foreach ($points as $i => [$x, $y]) {
            $command = $i === 0 ? 'M' : 'L';
            $linePath .= $command.round($x, 1).','.round($y, 1).' ';
        }

        $firstX = $points[0][0];
        $lastX = $points[$count - 1][0];
        $areaPath = trim($linePath).' L'.round($lastX, 1).','.$baseY.' L'.round($firstX, 1).','.$baseY.' Z';

        $svg = ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$width.' '.$height.'" width="'.$width.'" height="'.$height.'">'];

        // Rejilla horizontal tenue en min/medio/max para dar escala.
        foreach ([0.0, 0.5, 1.0] as $t) {
            $gy = $baseY - ($t * ($baseY - $padY));
            $svg[] = '<line x1="'.$padX.'" y1="'.round($gy, 1).'" x2="'.($width - $padX).'" y2="'.round($gy, 1).'" stroke="#f1f5f9" stroke-width="1"/>';
        }

        $svg[] = '<path d="'.$areaPath.'" fill="#64748b" fill-opacity="0.08"/>';
        $svg[] = '<path d="'.trim($linePath).'" fill="none" stroke="#475569" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';

        foreach ($points as [$x, $y]) {
            $svg[] = '<circle cx="'.round($x, 1).'" cy="'.round($y, 1).'" r="3" fill="#ffffff" stroke="#475569" stroke-width="1.5"/>';
        }

        $svg[] = self::text($firstX, $baseY + 16, self::shortLabel($labels[0] ?? '', 14), 8, '#94a3b8', 'start');
        $svg[] = self::text($lastX, $baseY + 16, self::shortLabel($labels[$count - 1] ?? '', 14), 8, '#94a3b8', 'end');
        $svg[] = '</svg>';

        return implode('', $svg);
    }

    /**
     * @param  list<string>  $labels
     * @param  list<float>  $values
     */
    private static function donut(array $labels, array $values): string
    {
        $size = 170;
        $cx = $cy = 85.0;
        $r = 56.0;
        $stroke = 22.0;
        $outer = $r + ($stroke / 2);
        $inner = max(1.0, $r - ($stroke / 2));
        $total = array_sum($values);

        if ($total <= 0) {
            return '';
        }

        $svg = ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$size.' '.$size.'" width="'.$size.'" height="'.$size.'">'];
        $svg[] = '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="none" stroke="#f1f5f9" stroke-width="'.$stroke.'"/>';

        // El donut se dibuja como poligonales rellenos (arco externo
        // + arco interno invertido): dompdf no soporta stroke-dasharray,
        // que es lo que usaba la version de circulos y por eso el PDF
        // salia de un solo color.
        $angle = -M_PI_2;

        foreach ($values as $i => $value) {
            $frac = $value / $total;
            $sweep = $frac * 2 * M_PI;
            $end = $angle + $sweep;
            $color = self::PALETTE[$i % count(self::PALETTE)];

            $segments = max(2, (int) ceil($frac * 96));
            $step = $sweep / $segments;

            $d = '';

            for ($s = 0; $s <= $segments; $s++) {
                $a = $angle + ($s * $step);
                $x = $cx + ($outer * cos($a));
                $y = $cy + ($outer * sin($a));
                $d .= ($s === 0 ? 'M' : 'L').round($x, 1).','.round($y, 1).' ';
            }

            for ($s = $segments; $s >= 0; $s--) {
                $a = $angle + ($s * $step);
                $x = $cx + ($inner * cos($a));
                $y = $cy + ($inner * sin($a));
                $d .= 'L'.round($x, 1).','.round($y, 1).' ';
            }

            $svg[] = '<path d="'.trim($d).' Z" fill="'.$color.'"/>';

            $angle = $end;
        }

        // El total va en HTML junto a la leyenda: el texto dentro del SVG
        // depende de la fuente que dompdf resuelva y puede salir cortado.
        $svg[] = '</svg>';

        return implode('', $svg);
    }

    /**
     * Leyenda HTML del donut: cuadro de color + etiqueta + valor, en la
     * misma tabla de dos columnas que usa el resto del documento.
     *
     * @param  list<string>  $labels
     * @param  list<float>  $values
     * @return list<array{color: string, label: string, value: string}>
     */
    public static function donutLegend(array $labels, array $values): array
    {
        $legend = [];

        foreach ($values as $i => $value) {
            $legend[] = [
                'color' => self::PALETTE[$i % count(self::PALETTE)],
                'label' => $labels[$i] ?? '',
                'value' => self::formatValue($value),
            ];
        }

        return $legend;
    }

    private static function text(float $x, float $y, string $content, int $size, string $fill, string $anchor = 'middle'): string
    {
        return '<text x="'.round($x, 1).'" y="'.round($y, 1).'" font-family="Helvetica, Arial, sans-serif" font-size="'.$size.'" fill="'.$fill.'" text-anchor="'.$anchor.'">'.$content.'</text>';
    }

    private static function shortLabel(string $label, int $max): string
    {
        $label = trim($label);

        return mb_strlen($label) > $max ? mb_substr($label, 0, $max - 1).'…' : $label;
    }
}
