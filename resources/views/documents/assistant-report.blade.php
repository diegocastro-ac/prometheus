<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $report['title'] ?? __('assistant.report.fallback_title') }}</title>
    <style>
        @page {
            size: A4;
            margin: 10mm;
            @bottom-center {
                content: "{{ $settings->businessName() }} @if($settings->taxId()) | {{ $settings->taxId() }} @endif | {{ __('document.page') }} " counter(page) " {{ __('document.of') }} " counter(pages);
                font-family: Arial, sans-serif;
                font-size: 9px;
                color: #6b7280;
            }
        }
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 11px;
            color: #1f2937;
            margin: 0;
            padding: 0;
            background: #fff;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 25px;
            border-bottom: 4px solid #f59e0b;
            margin-bottom: 26px;
        }
        .company-info { flex: 1; padding-right: 40px; }
        .company-header { display: flex; align-items: center; gap: 15px; margin-bottom: 10px; }
        .logo { max-height: 50px; max-width: 140px; }
        .company-name {
            font-family: 'Arial', sans-serif;
            font-size: 24px;
            font-weight: bold;
            color: #b45309;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .company-details { font-size: 11px; color: #4b5563; margin: 0; line-height: 1.6; }
        .doc-meta { text-align: right; min-width: 250px; }
        .doc-title {
            font-family: 'Arial', sans-serif;
            font-size: 20px;
            font-weight: bold;
            color: #1f2937;
            margin: 0 0 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .doc-subtitle {
            font-family: 'Arial', sans-serif;
            font-size: 10px;
            font-weight: bold;
            color: #b45309;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin: 0 0 4px;
        }
        .doc-date { font-size: 11px; color: #6b7280; margin: 0; }
        .report-intro {
            font-size: 12px;
            color: #374151;
            line-height: 1.6;
            margin: 0 0 24px;
        }
        .section { margin-bottom: 26px; }
        .section + .section { page-break-inside: avoid; }
        .section-title {
            font-family: 'Arial', sans-serif;
            font-size: 13px;
            font-weight: bold;
            color: #1f2937;
            text-transform: uppercase;
            padding-bottom: 7px;
            border-bottom: 3px solid #d1d5db;
            margin-bottom: 14px;
            letter-spacing: 1px;
        }
        .block-title {
            font-family: 'Arial', sans-serif;
            font-size: 10px;
            font-weight: bold;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 8px;
        }
        .block-text { font-size: 11.5px; line-height: 1.65; color: #1f2937; margin: 0 0 12px; }
        .block-text strong { color: #111827; }
        .block-text ul, .block-text ol { margin: 6px 0 12px 18px; padding: 0; }
        .block-text li { margin-bottom: 3px; }
        .metrics-table { width: 100%; border-collapse: collapse; margin: 10px 0 16px; }
        .metrics-table td {
            border: 1px solid #e5e7eb;
            padding: 9px 10px;
            vertical-align: top;
            width: 33.33%;
        }
        .metric-label {
            font-family: 'Arial', sans-serif;
            font-size: 8.5px;
            font-weight: bold;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin: 0 0 3px;
        }
        .metric-label .dot {
            display: inline-block;
            width: 6px; height: 6px;
            border-radius: 50%;
            margin-right: 4px;
        }
        .metric-value { font-family: 'Arial', sans-serif; font-size: 14px; font-weight: bold; color: #111827; margin: 0; }
        .metric-hint { font-size: 9px; color: #9ca3af; margin: 2px 0 0; }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0 16px;
            font-size: 10px;
        }
        .table th {
            background: #f59e0b;
            color: #ffffff;
            padding: 8px 9px;
            text-align: left;
            font-family: 'Arial', sans-serif;
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .table td {
            border: 1px solid #d1d5db;
            padding: 7px 9px;
            vertical-align: top;
        }
        .table tr:nth-child(even) { background: #f9fafb; }
        .chart-box { margin: 8px 0 18px; }
        .chart-title { font-size: 10px; font-weight: bold; color: #6b7280; margin: 0 0 6px; }
        .chart-img { width: 100%; }
        .chart-img-sm { width: 170px; }
        .chart-legend {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 10px;
        }
        .chart-legend td { padding: 3px 8px 3px 0; vertical-align: middle; }
        .chart-swatch {
            display: inline-block;
            width: 8px; height: 8px;
            border-radius: 2px;
        }
        .chart-total {
            font-family: 'Arial', sans-serif;
            font-size: 16px;
            font-weight: bold;
            color: #111827;
            margin: 0;
        }
        .chart-total-label {
            font-size: 9px;
            color: #9ca3af;
            margin: 0;
        }
        .steps { margin: 8px 0 16px; padding: 0; }
        .step { display: table; width: 100%; margin-bottom: 10px; }
        .step-num {
            display: table-cell;
            width: 22px;
            vertical-align: top;
        }
        .step-num span {
            display: inline-block;
            width: 18px; height: 18px;
            border-radius: 50%;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            font-family: 'Arial', sans-serif;
            font-size: 9px;
            font-weight: bold;
            color: #4b5563;
            text-align: center;
            line-height: 18px;
        }
        .step-body { display: table-cell; vertical-align: top; padding-left: 8px; }
        .step-title { font-family: 'Arial', sans-serif; font-size: 10.5px; font-weight: bold; color: #111827; margin: 0 0 2px; }
        .step-text { font-size: 10.5px; line-height: 1.55; color: #4b5563; margin: 0; }
        .callouts { margin: 8px 0 16px; }
        .callout {
            border-left: 3px solid #0ea5e9;
            background: #f9fafb;
            padding: 9px 12px;
            margin-bottom: 8px;
        }
        .callout.danger { border-left-color: #ef4444; }
        .callout.warning { border-left-color: #f59e0b; }
        .callout.success { border-left-color: #10b981; }
        .callout-title {
            font-family: 'Arial', sans-serif;
            font-size: 10px;
            font-weight: bold;
            color: #111827;
            margin: 0 0 3px;
        }
        .callout-text { font-size: 10.5px; line-height: 1.55; color: #4b5563; margin: 0; }
        .list-block { margin: 8px 0 16px; padding-left: 18px; }
        .list-block li { font-size: 11px; line-height: 1.6; margin-bottom: 4px; }
        .footer-note {
            margin-top: 28px;
            padding-top: 12px;
            border-top: 1px solid #d1d5db;
            font-size: 9px;
            color: #6b7280;
            text-align: center;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    @php
        $report = $report ?? [];
        $fmtValue = fn (float $v): string => \App\Documents\Rendering\ReportChartSvg::formatValue($v);
    @endphp

    <div class="header">
        <div class="company-info">
            <div class="company-header">
                @if($settings->logoPath())
                    <img src="{{ asset($settings->logoPath()) }}" alt="" class="logo">
                @else
                    <img src="/favicon.png" alt="" class="logo">
                @endif
                <h1 class="company-name">{{ $settings->businessName() }}</h1>
            </div>
            <p class="company-details">
                {{ $settings->taxId() }}<br>
                {{ $settings->address() }}<br>
                @if($settings->phone()) {{ __('document.phone') }}: {{ $settings->phone() }}<br>@endif
                @if($settings->email()) {{ $settings->email() }}@endif
            </p>
        </div>
        <div class="doc-meta">
            <p class="doc-subtitle">{{ __('assistant.report.pdf_badge') }}</p>
            <h2 class="doc-title">{{ $report['title'] ?? __('assistant.report.fallback_title') }}</h2>
            <p class="doc-date">{{ now()->format('d/m/Y H:i') }}</p>
        </div>
    </div>

    @if (($report['intro'] ?? '') !== '')
        <p class="report-intro">{{ $report['intro'] }}</p>
    @endif

    @foreach (($report['sections'] ?? []) as $section)
        <div class="section">
            @if (($section['title'] ?? '') !== '')
                <h3 class="section-title">{{ $section['title'] }}</h3>
            @endif

            @foreach (($section['blocks'] ?? []) as $block)
                @switch($block['type'] ?? '')
                    @case('text')
                        <div class="block-text">{!! \Illuminate\Support\Str::markdown((string) ($block['text'] ?? ''), ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                        @break

                    @case('metrics')
                        @php
                            $items = array_values($block['items'] ?? []);
                            $chunks = array_chunk($items, 3);
                            $tones = ['danger' => '#ef4444', 'warning' => '#f59e0b', 'success' => '#10b981', 'info' => '#0ea5e9'];
                        @endphp
                        <table class="metrics-table">
                            @foreach ($chunks as $chunk)
                                <tr>
                                    @foreach ($chunk as $metric)
                                        <td>
                                            <p class="metric-label">
                                                @if (isset($tones[$metric['tone'] ?? '']))
                                                    <span class="dot" style="background-color: {{ $tones[$metric['tone']] }};"></span>
                                                @endif
                                                {{ $metric['label'] ?? '' }}
                                            </p>
                                            <p class="metric-value">{{ $metric['value'] ?? '' }}</p>
                                            @if (($metric['hint'] ?? '') !== '')
                                                <p class="metric-hint">{{ $metric['hint'] }}</p>
                                            @endif
                                        </td>
                                    @endforeach
                                    @for ($i = count($chunk); $i < 3; $i++)
                                        <td></td>
                                    @endfor
                                </tr>
                            @endforeach
                        </table>
                        @break

                    @case('table')
                        @if (! empty($block['columns']) && ! empty($block['rows']))
                            @if (($block['title'] ?? '') !== '')
                                <p class="block-title">{{ $block['title'] }}</p>
                            @endif
                            <table class="table">
                                <thead>
                                    <tr>
                                        @foreach ($block['columns'] as $column)
                                            <th>{{ $column }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($block['rows'] as $row)
                                        <tr>
                                            @foreach ($row as $cell)
                                                <td>{{ $cell }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                        @break

                    @case('chart')
                        @php
                            $chartSvg = \App\Documents\Rendering\ReportChartSvg::render($block);
                            $chartKind = (string) ($block['chart'] ?? 'bar');
                            $chartValues = array_map('floatval', $block['values'] ?? []);
                            $chartTotal = array_sum($chartValues);
                        @endphp
                        @if ($chartSvg !== '')
                            <div class="chart-box">
                                @if (($block['title'] ?? '') !== '')
                                    <p class="chart-title">{{ $block['title'] }}</p>
                                @endif
                                @if ($chartKind === 'donut')
                                    <table style="width: 100%; border-collapse: collapse;">
                                        <tr>
                                            <td style="width: 45%; vertical-align: middle;">
                                                <img class="chart-img-sm" src="data:image/svg+xml;base64,{{ base64_encode($chartSvg) }}" alt="">
                                            </td>
                                            <td style="vertical-align: middle;">
                                                <p class="chart-total">{{ $fmtValue($chartTotal) }}</p>
                                                <p class="chart-total-label">{{ __('assistant.report.chart_total') }}</p>
                                                <table class="chart-legend">
                                                    @foreach (\App\Documents\Rendering\ReportChartSvg::donutLegend($block['labels'] ?? [], $block['values'] ?? []) as $legendItem)
                                                        <tr>
                                                            <td style="width: 14px;"><span class="chart-swatch" style="background-color: {{ $legendItem['color'] }};"></span></td>
                                                            <td>{{ $legendItem['label'] }}</td>
                                                            <td style="text-align: right; font-weight: bold;">{{ $legendItem['value'] }}</td>
                                                        </tr>
                                                    @endforeach
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                @else
                                    <img class="chart-img" src="data:image/svg+xml;base64,{{ base64_encode($chartSvg) }}" alt="">
                                @endif
                            </div>
                        @endif
                        @break

                    @case('steps')
                        <div class="steps">
                            @foreach (($block['items'] ?? []) as $stepItem)
                                <div class="step">
                                    <div class="step-num"><span>{{ $loop->iteration }}</span></div>
                                    <div class="step-body">
                                        <p class="step-title">{{ $stepItem['title'] ?? '' }}</p>
                                        @if (($stepItem['text'] ?? '') !== '')
                                            <p class="step-text">{{ $stepItem['text'] }}</p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @break

                    @case('callouts')
                        <div class="callouts">
                            @foreach (($block['items'] ?? []) as $callout)
                                <div class="callout {{ in_array($callout['tone'] ?? 'info', ['danger', 'warning', 'success'], true) ? $callout['tone'] : '' }}">
                                    @if (($callout['title'] ?? '') !== '')
                                        <p class="callout-title">{{ $callout['title'] }}</p>
                                    @endif
                                    @if (($callout['text'] ?? '') !== '')
                                        <p class="callout-text">{{ $callout['text'] }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        @break

                    @case('list')
                        <ul class="list-block">
                            @foreach (($block['items'] ?? []) as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                        @break
                @endswitch
            @endforeach
        </div>
    @endforeach

    <div class="footer-note">
        {{ __('assistant.report.pdf_footer') }}<br>
        {{ __('assistant.disclaimer') }}
    </div>
</body>
</html>
