<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Monthly Statement</title>
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
        .statement-meta {
            text-align: right;
            min-width: 250px;
        }
        .statement-title {
            font-family: 'Arial', sans-serif;
            font-size: 28px;
            font-weight: bold;
            color: #1f2937;
            margin: 0 0 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .statement-period {
            font-size: 12px;
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
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-badge.issued {
            background: #dbeafe;
            color: #1e40af;
        }
        .status-badge.paid {
            background: #d1fae5;
            color: #065f46;
        }
        .status-badge.overdue {
            background: #fee2e2;
            color: #991b1b;
        }
        .status-badge.voided {
            background: #f3f4f6;
            color: #4b5563;
        }
        .totals-box {
            background: #fef3c7;
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
            color: #b45309;
            border-top: 2px solid #f59e0b;
            padding-top: 14px;
            margin-top: 10px;
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
                <img src="{{ public_path('favicon.png') }}" alt="" class="logo">
                <h1 class="company-name">{{ $settings->businessName() }}</h1>
            </div>
            <p class="company-details">
                {{ $settings->taxId() }}<br>
                {{ $settings->address() }}<br>
                @if($settings->phone()) {{ __('document.phone') }}: {{ $settings->phone() }}<br>@endif
                @if($settings->email()) {{ $settings->email() }}@endif
            </p>
        </div>
        <div class="statement-meta">
            <h2 class="statement-title">{{ __('document.monthly_statement') }}</h2>
            <p class="statement-period">{{ __('document.period') }}: {{ $document->period() }}</p>
        </div>
    </div>

    <div class="section">
        <h3 class="section-title">{{ __('document.account_information') }}</h3>
        <div class="info-grid">
            <div class="info-box">
                <div class="info-label">{{ __('document.tenant') }}</div>
                <div class="info-value">{{ $document->tenantName() }}</div>
            </div>
            <div class="info-box">
                <div class="info-label">{{ __('document.property') }}</div>
                <div class="info-value">{{ $document->propertyName() }}</div>
            </div>
        </div>
    </div>

    <div class="section">
        <h3 class="section-title">{{ __('document.transaction_history') }}</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('document.invoice_number') }}</th>
                    <th>{{ __('document.period') }}</th>
                    <th>{{ __('document.concept') }}</th>
                    <th class="text-right">{{ __('document.amount') }}</th>
                    <th class="text-right">{{ __('document.paid') }}</th>
                    <th class="text-right">{{ __('document.balance') }}</th>
                    <th class="text-center">{{ __('document.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($document->transactions() as $transaction)
                <tr>
                    <td>{{ $transaction['number'] }}</td>
                    <td>{{ $transaction['period'] }}</td>
                    <td>{{ $transaction['concept'] }}</td>
                    <td class="text-right">{{ $settings->formatMoney($transaction['amount']) }}</td>
                    <td class="text-right">{{ $settings->formatMoney($transaction['paid']) }}</td>
                    <td class="text-right">{{ $settings->formatMoney($transaction['balance']) }}</td>
                    <td class="text-center">
                        <span class="status-badge {{ $transaction['status_class'] }}">{{ $transaction['status'] }}</span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals-box">
            <div class="total-row">
                <span>{{ __('document.total_invoiced') }}</span>
                <span>{{ $settings->formatMoney($document->totalInvoiced()) }}</span>
            </div>
            <div class="total-row">
                <span>{{ __('document.total_paid_total') }}</span>
                <span>{{ $settings->formatMoney($document->totalPaid()) }}</span>
            </div>
            <div class="total-row.grand-total">
                <span>{{ __('document.outstanding_balance_total') }}</span>
                <span>{{ $settings->formatMoney($document->outstandingBalance()) }}</span>
            </div>
        </div>
    </div>

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