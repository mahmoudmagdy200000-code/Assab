<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        h2 { font-size: 13px; margin: 18px 0 6px; border-bottom: 1px solid #ccc; padding-bottom: 3px; }
        .muted { color: #666; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #ddd; padding: 5px 7px; text-align: left; }
        th { background: #f3f3f3; }
        td.num { text-align: right; }
    </style>
</head>
<body>
    @php($summary = $details['summary'] ?? [])
    <h1>{{ $title }}</h1>
    <div class="muted">Period: {{ $summary['period_label'] ?? '-' }} &middot; Generated {{ now()->format('Y-m-d H:i') }}</div>

    <h2>Summary</h2>
    <table>
        <tr><th>Branch</th><td>{{ $summary['branch']['name'] ?? '-' }}</td></tr>
        <tr><th>Total Requests</th><td>{{ $summary['total_requests'] ?? 0 }}</td></tr>
        <tr><th>Total Amount</th><td>{{ number_format($summary['total_amount'] ?? 0, 2) }}</td></tr>
        <tr><th>Trend</th><td>{{ $summary['trend_percent'] ?? '-' }} {{ $summary['trend_label'] ?? '' }}</td></tr>
    </table>

    <h2>Payment Methods</h2>
    <table>
        <thead><tr><th>Method</th><th>Count</th><th>Amount</th></tr></thead>
        <tbody>
        @forelse($details['payment_methods'] ?? [] as $pm)
            <tr><td>{{ $pm['method'] }}</td><td class="num">{{ $pm['count'] }}</td><td class="num">{{ number_format($pm['amount'], 2) }}</td></tr>
        @empty
            <tr><td colspan="3" class="muted">No data</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Top Suppliers</h2>
    <table>
        <thead><tr><th>Supplier</th><th>Amount</th></tr></thead>
        <tbody>
        @forelse($details['top_suppliers'] ?? [] as $s)
            <tr><td>{{ $s['name'] }}</td><td class="num">{{ number_format($s['amount'], 2) }}</td></tr>
        @empty
            <tr><td colspan="2" class="muted">No data</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Expense Ratios</h2>
    <table>
        <thead><tr><th>Type</th><th>Count</th><th>Amount</th></tr></thead>
        <tbody>
        @foreach($details['expense_ratios'] ?? [] as $r)
            <tr><td>{{ $r['type'] }}</td><td class="num">{{ $r['count'] }}</td><td class="num">{{ number_format($r['amount'], 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>

    <h2>Branch Comparisons</h2>
    <table>
        <thead><tr><th>Branch</th><th>Amount</th></tr></thead>
        <tbody>
        @forelse($details['branch_comparisons'] ?? [] as $b)
            <tr><td>{{ $b['name'] }}</td><td class="num">{{ number_format($b['amount'], 2) }}</td></tr>
        @empty
            <tr><td colspan="2" class="muted">No data</td></tr>
        @endforelse
        </tbody>
    </table>

    @php($log = $details['cash_transfer_log'] ?? [])
    <h2>Cash Transfer Log</h2>
    <div class="muted">
        Cash In: {{ number_format($log['total_cash_in'] ?? 0, 2) }} &middot;
        Cash Out: {{ number_format($log['total_cash_out'] ?? 0, 2) }} &middot;
        Count: {{ $log['count'] ?? 0 }}
    </div>
    <table>
        <thead><tr><th>Method</th><th>Branch</th><th>Date</th><th>Amount</th></tr></thead>
        <tbody>
        @forelse($log['logs'] ?? [] as $row)
            <tr>
                <td>{{ $row['method'] }} ({{ $row['sub_label'] }})</td>
                <td>{{ $row['branch'] }}</td>
                <td>{{ $row['date_time'] }}</td>
                <td class="num">{{ number_format($row['amount'], 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No data</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
