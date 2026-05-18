@php
    /** @var \App\Domain\MobilePayment\Models\PaymentReceipt $receipt */
    $brand = config('brand.name', 'Zelta');
    $forPdf = $forPdf ?? false;

    $amount = (string) $receipt->amount;
    if (str_contains($amount, '.')) {
        $amount = rtrim(rtrim($amount, '0'), '.');
    }

    $txHash = is_string($receipt->tx_hash) ? $receipt->tx_hash : '';
    $network = is_string($receipt->network) ? $receipt->network : '';

    $pdfUrl = $receipt->pdf_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($receipt->pdf_path) : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Payment Receipt — {{ $brand }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #1a1a2e;
            background: #f4f5f7;
            font-size: 14px;
            line-height: 1.5;
        }
        .page { width: 100%; padding: 32px 16px; }
        .card {
            width: 480px;
            max-width: 100%;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #e6e8eb;
            border-radius: 12px;
        }
        .header {
            background: #0b0b23;
            color: #ffffff;
            padding: 28px 32px;
            border-radius: 12px 12px 0 0;
            text-align: center;
        }
        .brand { font-size: 16px; font-weight: bold; letter-spacing: 1px; }
        .title { font-size: 12px; color: #9aa0b4; margin-top: 4px; text-transform: uppercase; letter-spacing: 2px; }
        .amount-box { text-align: center; padding: 28px 32px 8px; }
        .amount { font-size: 34px; font-weight: bold; color: #0b0b23; }
        .amount-asset { font-size: 18px; color: #6b7280; }
        .merchant { text-align: center; padding: 0 32px 24px; color: #6b7280; font-size: 15px; }
        .rows { padding: 8px 32px 24px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 10px 0; border-bottom: 1px solid #f0f1f3; vertical-align: top; }
        td.label { color: #9aa0b4; font-size: 13px; width: 40%; }
        td.value { color: #1a1a2e; font-size: 13px; text-align: right; font-weight: bold; }
        td.value.mono { font-family: 'Courier New', monospace; font-weight: normal; word-break: break-all; }
        tr:last-child td { border-bottom: none; }
        .footer {
            padding: 20px 32px 28px;
            border-top: 1px solid #f0f1f3;
            color: #9aa0b4;
            font-size: 11px;
            text-align: center;
        }
        .download {
            display: block;
            margin: 0 32px 24px;
            padding: 12px;
            background: #0b0b23;
            color: #ffffff;
            text-decoration: none;
            text-align: center;
            border-radius: 8px;
            font-weight: bold;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="card">
            <div class="header">
                <div class="brand">{{ $brand }}</div>
                <div class="title">Payment Receipt</div>
            </div>

            <div class="amount-box">
                <span class="amount">{{ $amount }}</span>
                <span class="amount-asset">{{ $receipt->asset }}</span>
            </div>
            <div class="merchant">{{ $receipt->merchant_name }}</div>

            <div class="rows">
                <table>
                    <tr>
                        <td class="label">Date</td>
                        <td class="value">{{ $receipt->transaction_at->format('M j, Y · g:i A') }}</td>
                    </tr>
                    @if ($network !== '')
                        <tr>
                            <td class="label">Network</td>
                            <td class="value">{{ $network }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td class="label">Network fee</td>
                        <td class="value">{{ $receipt->network_fee }}</td>
                    </tr>
                    @if ($txHash !== '')
                        <tr>
                            <td class="label">Transaction</td>
                            <td class="value mono">{{ $txHash }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td class="label">Receipt ID</td>
                        <td class="value mono">{{ $receipt->public_id }}</td>
                    </tr>
                </table>
            </div>

            @if (! $forPdf && $pdfUrl)
                <a class="download" href="{{ $pdfUrl }}">Download PDF</a>
            @endif

            <div class="footer">
                This receipt confirms a transaction settled on-chain.<br>
                Generated by {{ $brand }}.
            </div>
        </div>
    </div>
</body>
</html>
