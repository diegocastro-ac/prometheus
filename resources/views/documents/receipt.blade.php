<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Receipt</title>
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
        .receipt-meta {
            text-align: right;
            min-width: 250px;
        }
        .receipt-title {
            font-family: 'Arial', sans-serif;
            font-size: 28px;
            font-weight: bold;
            color: #1f2937;
            margin: 0 0 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .receipt-number {
            font-size: 16px;
            font-weight: bold;
            color: #1f2937;
            margin: 0 0 8px;
        }
        .receipt-dates {
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
            background: #10b981;
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
            background: #ecfdf5;
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
            color: #065f46;
            border-top: 2px solid #10b981;
            padding-top: 14px;
            margin-top: 10px;
        }
        .status-section {
            background: #ecfdf5;
            padding: 18px;
            margin: 15px 0;
            border: 2px solid #10b981;
        }
        .status-title {
            font-family: 'Arial', sans-serif;
            font-size: 16px;
            font-weight: bold;
            color: #065f46;
            text-transform: uppercase;
            margin-bottom: 12px;
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
        <div class="receipt-meta">
            <h2 class="receipt-title">{{ __('document.payment_receipt') }}</h2>
            <p class="receipt-number">{{ __('document.invoice_number') }} {{ $document->invoice()->number }}</p>
            <p class="receipt-dates">
                {{ __('document.payment_date') }}: {{ $document->invoice()->paid_at?->format('M d, Y') }}
            </p>
        </div>
    </div>

    <div class="status-section">
        <div class="status-title">{{ __('document.payment_status') }}: {{ __('document.paid') }}</div>
        <div class="info-grid-3">
            <div>
                <div class="info-label-small">{{ __('document.invoice_amount') }}</div>
                <div class="info-value-small">{{ $settings->formatMoney($document->invoice()->amount) }}</div>
            </div>
            <div>
                <div class="info-label-small">{{ __('document.paid_amount') }}</div>
                <div class="info-value-small">{{ $settings->formatMoney($document->invoice()->paidAmount()) }}</div>
            </div>
            <div>
                <div class="info-label-small">{{ __('document.outstanding_balance') }}</div>
                <div class="info-value-small">{{ $settings->formatMoney($document->invoice()->balance()) }}</div>
            </div>
        </div>
    </div>

    <div class="section">
        <h3 class="section-title">{{ __('document.payment_details') }}</h3>
        <div class="info-grid">
            <div class="info-box">
                <div class="info-label">{{ __('document.invoice_number') }}</div>
                <div class="info-value">{{ $document->invoice()->number }}</div>
            </div>
            <div class="info-box">
                <div class="info-label">{{ __('document.payment_date') }}</div>
                <div class="info-value">{{ $document->invoice()->paid_at?->format('M d, Y') }}</div>
            </div>
        </div>
    </div>

    <div class="section">
        <h3 class="section-title">{{ __('document.payer_information') }}</h3>
        <div class="info-grid">
            <div class="info-box">
                <div class="info-label">{{ __('document.customer') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->tenant->name ?? 'Not specified' }}</div>
                @if($document->invoice()->rental->tenant->document)
                <div class="info-label" style="margin-top: 8px;">{{ __('document.document_id') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->tenant->document }}</div>
                @endif
            </div>
            <div class="info-box">
                <div class="info-label">{{ __('document.property') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->name }}</div>
                <div class="info-label" style="margin-top: 8px;">{{ __('document.contract') }}</div>
                <div class="info-value">{{ $document->invoice()->rental->id }}</div>
            </div>
        </div>
        <div class="info-box" style="margin-top: 12px;">
            <div class="info-label">{{ __('document.service_address') }}</div>
            <div class="info-value">{{ $document->invoice()->rental->property->address ?? 'Not specified' }}</div>
        </div>
    </div>

    <div class="section">
        <h3 class="section-title">{{ __('document.payment_details') }}</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('document.description') }}</th>
                    <th>{{ __('document.period') }}</th>
                    <th class="text-right">{{ __('document.amount_paid') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $document->invoice()->conceptLabel() }}</td>
                    <td>{{ $document->invoice()->period }}</td>
                    <td class="text-right">{{ $settings->formatMoney($document->invoice()->paidAmount()) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="totals-box">
            <div class="total-row.grand-total">
                <span>{{ __('document.total_paid') }}</span>
                <span>{{ $settings->formatMoney($document->invoice()->paidAmount()) }}</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h3 class="section-title">{{ __('document.payment_breakdown') }}</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('document.date') }}</th>
                    <th>{{ __('document.amount') }}</th>
                    <th>{{ __('document.method') }}</th>
                    <th>{{ __('document.reference') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($document->invoice()->payments as $payment)
                <tr>
                    <td>{{ $payment->date?->format('m/d/Y') }}</td>
                    <td class="text-right">{{ $settings->formatMoney($payment->amount) }}</td>
                    <td>{{ $payment->method }}</td>
                    <td>{{ $payment->reference ?? '-' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if(isset($body->meta()['receipt_image']) && $body->meta()['receipt_image'])
    <div class="section">
        <h3 class="section-title">{{ __('document.receipt_image') }}</h3>
        <div style="text-align: center; padding: 20px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px;">
            <img src="{{ $body->meta()['receipt_image'] }}" alt="Receipt Image" style="max-width: 100%; max-height: 300px; border-radius: 4px;">
        </div>
    </div>
    @endif

    @if($body->notes !== [])
    <div class="section">
        <h3 class="section-title">{{ __('document.notes') }}</h3>
        <div class="info-box" style="background: #fffbeb; border-left-color: #f59e0b;">
            @foreach($body->notes as $note)
                <p style="margin: 5px 0; font-size: 10px; color: #92400e;">{{ $note }}</p>
            @endforeach
        </div>
    </div>
    @endif

    <div class="footer">
        <p class="footer-text">{{ $settings->legalFooter() }}</p>
        <p class="footer-text">{{ __('document.generated_by') }} {{ now()->format('d M Y \a\t H:i') }}</p>
    </div>
</body>
</html>