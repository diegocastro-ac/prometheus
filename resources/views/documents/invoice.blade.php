<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice</title>
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
        .invoice-meta {
            text-align: right;
            min-width: 250px;
        }
        .invoice-title {
            font-family: 'Arial', sans-serif;
            font-size: 28px;
            font-weight: bold;
            color: #1f2937;
            margin: 0 0 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .invoice-number {
            font-size: 18px;
            font-weight: bold;
            color: #1f2937;
            margin: 0 0 8px;
        }
        .invoice-dates {
            font-size: 11px;
            color: #6b7280;
            margin: 0;
            line-height: 1.5;
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
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .info-box {
            background: #ffffff;
            padding: 15px;
            border: 1px solid #e5e7eb;
        }
        .info-label {
            font-size: 10px;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 4px;
            font-weight: bold;
            letter-spacing: 0.5px;
        }
        .info-value {
            font-size: 13px;
            color: #1f2937;
            font-weight: 500;
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
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .totals-box {
            background: #f9fafb;
            padding: 18px;
            margin-top: 18px;
            border: 1px solid #e5e7eb;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            font-size: 12px;
        }
        .total-row.grand-total {
            font-size: 16px;
            font-weight: bold;
            color: #1f2937;
            border-top: 3px solid #f59e0b;
            padding-top: 14px;
            margin-top: 10px;
        }
        .summary-box {
            background: #fef3c7;
            padding: 18px;
            margin: 18px 0;
            border: 2px solid #f59e0b;
        }
        .summary-title {
            font-family: 'Arial', sans-serif;
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 15px;
            color: #b45309;
            letter-spacing: 1px;
        }
        .info-grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }
        .info-label-small {
            font-size: 9px;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 3px;
            font-weight: bold;
        }
        .info-value-small {
            font-size: 11px;
            color: #1f2937;
        }
        .status-section {
            padding: 15px;
            margin-bottom: 25px;
            border: 2px solid;
            background: #ffffff;
        }
        .status-section.issued {
            border-color: #3b82f6;
            background: #eff6ff;
        }
        .status-section.paid {
            border-color: #10b981;
            background: #ecfdf5;
        }
        .status-section.overdue {
            border-color: #dc2626;
            background: #fef2f2;
        }
        .status-section.voided {
            border-color: #6b7280;
            background: #f3f4f6;
        }
        .status-title {
            font-family: 'Arial', sans-serif;
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 12px;
            color: #1f2937;
            letter-spacing: 1px;
        }
        .status-section.issued .status-title {
            color: #1e40af;
        }
        .status-section.paid .status-title {
            color: #065f46;
        }
        .status-section.overdue .status-title {
            color: #991b1b;
        }
        .status-section.voided .status-title {
            color: #4b5563;
        }
        .notes {
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
            margin: 0 0 5px;
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
        <div class="invoice-meta">
            <h2 class="invoice-title">{{ __('document.invoice') }}</h2>
            <p class="invoice-number">{{ __('document.invoice_number') }} {{ $document->invoice()->number }}</p>
            <p class="invoice-dates">
                {{ __('document.date') }}: {{ $document->invoice()->issued_at?->format('M d, Y') }}<br>
                {{ __('document.due_date') }}: {{ $document->invoice()->due_at?->format('M d, Y') }}
            </p>
        </div>
    </div>

    <div class="section">
        <h3 class="section-title">{{ __('document.customer_information') }}</h3>
        <div class="info-grid">
            <div class="info-box">
                <div class="info-label">{{ __('document.customer_name') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->tenant->name ?? 'Not specified' }}</div>
                @if($document->invoice()->rental->tenant->document)
                <div class="info-label" style="margin-top: 8px;">{{ __('document.document_id') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->tenant->document }}</div>
                @endif
                @if($document->invoice()->rental->tenant->phone_number)
                <div class="info-label" style="margin-top: 8px;">{{ __('document.phone') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->tenant->phone_number }}</div>
                @endif
                @if($document->invoice()->rental->tenant->email)
                <div class="info-label" style="margin-top: 8px;">{{ __('document.email') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->tenant->email }}</div>
                @endif
            </div>
            <div class="info-box">
                <div class="info-label">{{ __('document.contract_number') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->id }}</div>
                @if($document->invoice()->rental->start_date)
                <div class="info-label" style="margin-top: 8px;">{{ __('document.contract_start') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->start_date->format('M d, Y') }}</div>
                @endif
                @if($document->invoice()->rental->end_date)
                <div class="info-label" style="margin-top: 8px;">{{ __('document.contract_end') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->end_date->format('M d, Y') }}</div>
                @endif
                @if($document->invoice()->rental->total_months)
                <div class="info-label" style="margin-top: 8px;">{{ __('document.contract_duration') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->total_months }} {{ __('document.months') }}</div>
                @endif
            </div>
        </div>
    </div>

    <div class="section">
        <h3 class="section-title">{{ __('document.service_location') }}</h3>
        <div class="info-grid">
            <div class="info-box">
                <div class="info-label">{{ __('document.property_name') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->property->name ?? 'Not specified' }}</div>
                @if($document->invoice()->rental->property->description)
                <div class="info-label" style="margin-top: 8px;">{{ __('document.property_description') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->property->description }}</div>
                @endif
            </div>
            <div class="info-box">
                <div class="info-label">{{ __('document.service_address') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->property->address ?? 'Not specified' }}</div>
                @if($document->invoice()->rental->name)
                <div class="info-label" style="margin-top: 8px;">{{ __('document.rental_unit') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->name }}</div>
                @endif
                @if($document->invoice()->rental->total_persons)
                <div class="info-label" style="margin-top: 8px;">{{ __('document.occupants') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->total_persons }}</div>
                @endif
            </div>
        </div>
    </div>

    <div class="section">
        <h3 class="section-title">{{ __('document.billing_information') }}</h3>
        <div class="info-grid">
            <div class="info-box">
                <div class="info-label">{{ __('document.billing_period') }}</div>
                <div class="info-value">{{ $document->invoice()->period }}</div>
                @if($document->invoice()->rental->billing_cadence)
                <div class="info-label" style="margin-top: 8px;">{{ __('document.billing_cadence') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->billing_cadence->label() }}</div>
                @endif
            </div>
            <div class="info-box">
                <div class="info-label">{{ __('document.service_type') }}</div>
                <div class="info-value">{{ $document->invoice()->conceptLabel() }}</div>
                @if($document->invoice()->rental->monthly_amount)
                <div class="info-label" style="margin-top: 8px;">{{ __('document.monthly_rate') }}</div>
                <div class="info-value">{{ $settings->formatMoney($document->invoice()->rental->monthly_amount) }}</div>
                @endif
            </div>
        </div>
        @if($document->invoice()->rental->description)
        <div class="info-box" style="margin-top: 12px;">
            <div class="info-label">{{ __('document.contract_notes') }}</div>
            <div class="info-value">{{ $document->invoice()->rental->description }}</div>
        </div>
        @endif
    </div>

    <div class="section">
        <h3 class="section-title">{{ __('document.invoice_summary') }}</h3>
        <div class="summary-box">
            <div class="summary-title">{{ __('document.invoice_summary') }}</div>
            <div class="info-grid-3">
                <div>
                    <div class="info-label-small">{{ __('document.billing_period') }}</div>
                    <div class="info-value-small">{{ $document->invoice()->period }}</div>
                </div>
                <div>
                    <div class="info-label-small">{{ __('document.service_type') }}</div>
                    <div class="info-value-small">{{ $document->invoice()->conceptLabel() }}</div>
                </div>
                <div>
                    <div class="info-label-small">{{ __('document.billing_cadence') }}</div>
                    <div class="info-value-small">{{ $document->invoice()->rental->billing_cadence?->label() ?? 'Monthly' }}</div>
                </div>
            </div>
            @if($document->invoice()->rental->monthly_amount)
            <div class="info-grid-3" style="margin-top: 12px;">
                <div>
                    <div class="info-label-small">{{ __('document.monthly_rate') }}</div>
                    <div class="info-value-small">{{ $settings->formatMoney($document->invoice()->rental->monthly_amount) }}</div>
                </div>
                <div>
                    <div class="info-label-small">{{ __('document.contract_months') }}</div>
                    <div class="info-value-small">{{ $document->invoice()->rental->total_months ?? 'N/A' }}</div>
                </div>
                <div>
                    <div class="info-label-small">{{ __('document.occupants') }}</div>
                    <div class="info-value-small">{{ $document->invoice()->rental->total_persons ?? 'N/A' }}</div>
                </div>
            </div>
            @endif
        </div>
    </div>

    <div class="section">
        <h3 class="section-title">{{ __('document.invoice_details') }}</h3>
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 40%;">{{ __('document.description') }}</th>
                    <th style="width: 20%;">{{ __('document.period') }}</th>
                    <th style="width: 15%;">{{ __('document.rate') }}</th>
                    <th style="width: 25%;" class="text-right">{{ __('document.amount') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>{{ $document->invoice()->conceptLabel() }}</strong><br>
                        @if($document->invoice()->rental->property->name)
                        <span style="font-size: 10px; color: #6b7280;">{{ $document->invoice()->rental->property->name }}</span><br>
                        @endif
                        @if($document->invoice()->rental->name)
                        <span style="font-size: 10px; color: #6b7280;">{{ __('document.unit') }}: {{ $document->invoice()->rental->name }}</span>
                        @endif
                    </td>
                    <td>{{ $document->invoice()->period }}</td>
                    <td>
                        @if($document->invoice()->rental->monthly_amount)
                        {{ $settings->formatMoney($document->invoice()->rental->monthly_amount) }}
                        @else
                        -
                        @endif
                    </td>
                    <td class="text-right">{{ $settings->formatMoney($document->invoice()->amount) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="totals-box">
            <div class="total-row">
                <span>{{ __('document.subtotal') }}</span>
                <span>{{ $settings->formatMoney($document->invoice()->amount) }}</span>
            </div>
            <div class="total-row">
                <span>{{ __('document.tax') }} (0%)</span>
                <span>{{ $settings->formatMoney(0) }}</span>
            </div>
            <div class="total-row.grand-total">
                <span>{{ __('document.total_due') }}</span>
                <span>{{ $settings->formatMoney($document->invoice()->amount) }}</span>
            </div>
        </div>
    </div>

    <div class="section">
        @php
            $statusValue = $document->invoice()->status->value;
            $statusClass = match($statusValue) {
                'emitida' => 'issued',
                'pagada' => 'paid',
                'vencida' => 'overdue',
                'anulada' => 'voided',
                default => 'issued',
            };
        @endphp
        <div class="status-section {{ $statusClass }}">
            <div class="status-title">{{ __('invoice.statuses.'.$statusValue) }}</div>
            <div class="info-grid">
                <div>
                    <div class="info-label">{{ __('document.invoice_amount') }}</div>
                    <div class="info-value">{{ $settings->formatMoney($document->invoice()->amount) }}</div>
                </div>
                <div>
                    <div class="info-label">{{ __('document.paid_amount') }}</div>
                    <div class="info-value">{{ $settings->formatMoney($document->invoice()->paidAmount()) }}</div>
                </div>
            </div>
            @if($document->invoice()->balance() > 0)
            <div style="margin-top: 12px;">
                <div class="info-label">{{ __('document.outstanding_balance') }}</div>
                <div class="info-value" style="font-size: 16px;">{{ $settings->formatMoney($document->invoice()->balance()) }}</div>
            </div>
            @endif
            <div style="margin-top: 12px;">
                <div class="info-label">{{ __('document.due_date') }}</div>
                <div class="info-value">{{ $document->invoice()->due_at?->format('M d, Y') }}</div>
            </div>
        </div>
    </div>

    @if($body->headers !== [] && $body->rows !== [])
    <div class="section">
        <h3 class="section-title">{{ __('document.transaction_history') }}</h3>
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

    @if($body->notes !== [])
    <div class="section">
        <h3 class="section-title">{{ __('document.notes') }}</h3>
        <div class="notes">
            @foreach($body->notes as $note)
                <p style="margin: 5px 0;">{{ $note }}</p>
            @endforeach
        </div>
    </div>
    @endif

    @if($body->warnings !== [])
    <div class="notes" style="background: #fee2e2; border-left-color: #dc2626; color: #991b1b;">
        <strong>⚠ Important:</strong>
        @foreach($body->warnings as $warning)
            <p style="margin: 5px 0;">{{ $warning }}</p>
        @endforeach
    </div>
    @endif

    <div class="footer">
        <p class="footer-text">{{ $settings->legalFooter() }}</p>
        <p class="footer-text">{{ __('document.generated_by') }} {{ now()->format('d M Y \a\t H:i') }}</p>
    </div>
</body>
</html>