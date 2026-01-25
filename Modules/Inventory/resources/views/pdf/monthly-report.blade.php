<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Inventory Report - {{ $branchName ?? 'Branch' }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; margin: 20px; }
        .header { text-align: center; margin-bottom: 24px; border-bottom: 2px solid #333; padding-bottom: 8px; }
        .header h1 { margin: 0; font-size: 18px; }
        .header .sub { color: #666; font-size: 12px; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .summary { margin-bottom: 16px; }
        .summary td:first-child { font-weight: bold; width: 180px; }
        .footer { margin-top: 24px; text-align: center; font-size: 10px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Complete Monthly Inventory Report</h1>
        <div class="sub">{{ $period ?? '' }} | {{ $branchName ?? 'Branch' }}</div>
    </div>

    <table class="summary">
        <tr><td>Status</td><td>{{ $status ?? '-' }}</td></tr>
        <tr><td>Products Complete</td><td>{{ $productsComplete ?? 0 }} / {{ $productsTotal ?? 0 }} ({{ $productsPct ?? 0 }}%)</td></tr>
        <tr><td>Time Taken</td><td>{{ $timeTaken ?? '-' }}</td></tr>
        <tr><td>Participants</td><td>{{ $participantsCount ?? 0 }} Staff</td></tr>
        <tr><td>Total Inventory Value</td><td>{{ $totalValueFormatted ?? '0' }}</td></tr>
    </table>

    <h3>Inventoried Products</h3>
    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th>Quantity</th>
                <th>Unit</th>
                <th>Unit Price</th>
                <th>Line Value</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($products ?? [] as $p)
            <tr>
                <td>{{ $p['item_name'] ?? '-' }}</td>
                <td>{{ $p['quantity'] ?? 0 }}</td>
                <td>{{ $p['unit'] ?? '-' }}</td>
                <td>{{ $p['unit_price'] ?? 0 }}</td>
                <td>{{ $p['line_value'] ?? 0 }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Generated at {{ $generatedAt ?? now()->format('Y-m-d H:i:s') }}
    </div>
</body>
</html>
