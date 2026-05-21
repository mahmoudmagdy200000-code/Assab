<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .muted { color: #666; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 5px 7px; text-align: left; }
        th { background: #f3f3f3; }
        td.num { text-align: right; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="muted">{{ $details['period_label'] ?? '' }} &middot; Generated {{ now()->format('Y-m-d H:i') }}</div>

    <table>
        <thead>
            <tr>
                <th>Branch</th>
                <th>Location</th>
                <th>Opening Balance</th>
                <th>Cash In</th>
                <th>Cash Out</th>
                <th>Current Balance</th>
            </tr>
        </thead>
        <tbody>
        @forelse($details['branches'] ?? [] as $branch)
            @php($amounts = $branch['amounts'] ?? [])
            <tr>
                <td>{{ $branch['name'] ?? '-' }}</td>
                <td>{{ $branch['branch'] ?? '-' }}</td>
                <td class="num">{{ number_format($amounts['month_opening_balance'] ?? 0, 2) }}</td>
                <td class="num">{{ number_format($amounts['total_cash_in'] ?? 0, 2) }}</td>
                <td class="num">{{ number_format($amounts['total_cash_out'] ?? 0, 2) }}</td>
                <td class="num">{{ number_format($branch['current_balance'] ?? 0, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No data</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
