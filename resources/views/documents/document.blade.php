<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $body->title }}</title>
    <style>
        @page {
            size: A4;
            margin: 10mm;
            @bottom-center {
                content: "{{ $settings->businessName() }} @if($settings->taxId()) | {{ $settings->taxId() }} @endif @if($settings->address()) | {{ $settings->address() }} @endif | {{ __('document.page') }} " counter(page) " {{ __('document.of') }} " counter(pages);
                font-family: Arial, sans-serif;
                font-size: 9px;
                color: #6b7280;
            }
        }
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 12px;
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
            margin-bottom: 30px;
        }
        .company-info {
            flex: 1;
            padding-right: 40px;
        }
        .company-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 10px;
        }
        .logo {
            max-height: 50px;
            max-width: 140px;
        }
        .company-name {
            font-family: 'Arial', sans-serif;
            font-size: 24px;
            font-weight: bold;
            color: #b45309;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .company-details {
            font-size: 11px;
            color: #4b5563;
            margin: 0;
            line-height: 1.6;
        }
        .doc-meta {
            text-align: right;
            min-width: 250px;
        }
        .doc-title {
            font-family: 'Arial', sans-serif;
            font-size: 22px;
            font-weight: bold;
            color: #1f2937;
            margin: 0 0 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .doc-date {
            font-size: 11px;
            color: #6b7280;
            margin: 0;
        }
        .section {
            margin-bottom: 30px;
        }
        .section-title {
            font-family: 'Arial', sans-serif;
            font-size: 14px;
            font-weight: bold;
            color: #1f2937;
            text-transform: uppercase;
            padding-bottom: 8px;
            border-bottom: 3px solid #d1d5db;
            margin-bottom: 18px;
            letter-spacing: 1px;
        }
        .section-text {
            font-size: 12px;
            color: #1f2937;
            margin: 0 0 14px;
            line-height: 1.6;
        }
        .fields-table {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0;
        }
        .fields-table th {
            width: 35%;
            text-align: left;
            font-family: 'Arial', sans-serif;
            font-size: 10px;
            font-weight: bold;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
        }
        .fields-table td {
            font-size: 12px;
            color: #1f2937;
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .table th {
            background: #f59e0b;
            color: white;
            padding: 14px;
            text-align: left;
            font-weight: bold;
            font-size: 11px;
            text-transform: uppercase;
            font-family: 'Arial', sans-serif;
            letter-spacing: 0.5px;
        }
        .table td {
            border: 1px solid #d1d5db;
            padding: 14px;
            font-size: 12px;
        }
        .table tr:nth-child(even) {
            background: #f9fafb;
        }
        .warning {
            background: #fef3c7;
            padding: 18px;
            margin: 18px 0;
            border: 2px solid #f59e0b;
            font-size: 11px;
            color: #92400e;
        }
        .note {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            padding: 12px;
            margin: 15px 0;
            font-size: 10px;
            color: #4b5563;
        }
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #d1d5db;
            font-size: 9px;
            color: #6b7280;
            text-align: center;
        }
        .footer-text {
            margin: 0;
        }
    </style>
</head>
<body>
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
            <h2 class="doc-title">{{ $body->title }}</h2>
            <p class="doc-date">{{ now()->format('M d, Y') }}</p>
        </div>
    </div>

    @if($body->fields !== [])
        <div class="section">
            <table class="fields-table">
                @foreach($body->fields as $label => $value)
                    <tr>
                        <th>{{ $label }}</th>
                        <td>{{ $value }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if($body->headers !== [] && $body->rows !== [])
        <div class="section">
            <table class="table">
                <thead>
                    <tr>
                        @foreach($body->headers as $header)
                            <th>{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($body->rows as $row)
                        <tr>
                            @foreach($row as $cell)
                                <td>{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Secciones de documentos compuestos (personalizados): titulo
         opcional, texto, campos y tabla propia por seccion. --}}
    @foreach($body->sections as $section)
        <div class="section">
            @if(($section['title'] ?? '') !== '')
                <h3 class="section-title">{{ $section['title'] }}</h3>
            @endif

            @if(($section['text'] ?? '') !== '')
                <p class="section-text">{{ $section['text'] }}</p>
            @endif

            @if(! empty($section['fields']))
                <table class="fields-table">
                    @foreach($section['fields'] as $label => $value)
                        <tr>
                            <th>{{ $label }}</th>
                            <td>{{ $value }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif

            @if(! empty($section['table']['headers']) && ! empty($section['table']['rows']))
                <table class="table">
                    <thead>
                        <tr>
                            @foreach($section['table']['headers'] as $header)
                                <th>{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($section['table']['rows'] as $row)
                            <tr>
                                @foreach($row as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach

    {{-- Los avisos van despues de la tabla y nunca se omiten: el incremento
         por encima del IPC solo opera con acuerdo escrito y el arrendatario
         tiene que enterarse. Se escapan porque el texto del aviso incluye la
         URL de la fuente, que es dato capturado y no contenido de confianza. --}}
    @foreach($body->warnings as $warning)
        <div class="warning">{{ $warning }}</div>
    @endforeach

    @foreach($body->notes as $note)
        <div class="note">{{ $note }}</div>
    @endforeach

    @if($body->footer !== null)
        <div class="footer">
            <p class="footer-text">{{ $body->footer }}</p>
        </div>
    @endif
</body>
</html>
