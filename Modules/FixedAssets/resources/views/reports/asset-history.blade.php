<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Asset History Report - {{ $asset->code }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 12px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { font-size: 14px; margin: 16px 0 6px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #e5e7eb; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #f9fafb; }
        .meta td { border: none; padding: 2px 0; }
        .empty { color: #9ca3af; font-style: italic; }
    </style>
</head>
<body>
    <h1>{{ $asset->name }}</h1>
    <table class="meta">
        <tr><td>Asset Code</td><td>{{ $asset->code }}</td></tr>
        <tr><td>Location</td><td>{{ $payload['location'] ?: '—' }}</td></tr>
        <tr><td>Generated At</td><td>{{ $generatedAt->toDateTimeString() }}</td></tr>
    </table>

    <h2>Photos ({{ data_get($payload, 'photos.totalCount', 0) }})</h2>
    @if (data_get($payload, 'photos.totalCount', 0) > 0)
        <table>
            <thead><tr><th>Date</th><th>Updated By</th><th>Current</th></tr></thead>
            <tbody>
            @foreach ($payload['photos']['photos'] as $p)
                <tr>
                    <td>{{ $p['date'] ?: '—' }}</td>
                    <td>{{ $p['updatedBy'] ?: '—' }}</td>
                    <td>{{ $p['isCurrent'] ? 'Yes' : 'No' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p class="empty">No photo history.</p>
    @endif

    <h2>Status History ({{ data_get($payload, 'statusHistory.totalCount', 0) }})</h2>
    @if (data_get($payload, 'statusHistory.totalCount', 0) > 0)
        <table>
            <thead><tr><th>Date</th><th>Status</th><th>Note</th></tr></thead>
            <tbody>
            @foreach ($payload['statusHistory']['statuses'] as $s)
                <tr>
                    <td>{{ $s['date'] ?: '—' }}</td>
                    <td>{{ $s['status'] ?: '—' }}</td>
                    <td>{{ $s['note'] ?: '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p class="empty">No status history.</p>
    @endif

    <h2>Transfers ({{ data_get($payload, 'transfers.totalCount', 0) }})</h2>
    @if (data_get($payload, 'transfers.totalCount', 0) > 0)
        <table>
            <thead><tr><th>Date</th><th>From</th><th>To</th></tr></thead>
            <tbody>
            @foreach ($payload['transfers']['transfers'] as $t)
                <tr>
                    <td>{{ $t['date'] ?: '—' }}</td>
                    <td>{{ $t['fromLocation'] ?: '—' }}</td>
                    <td>{{ $t['toLocation'] ?: '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p class="empty">No transfers.</p>
    @endif

    <h2>Financial</h2>
    @if (data_get($payload, 'financial.items'))
        <table>
            <tbody>
            @foreach ($payload['financial']['items'] as $f)
                <tr><th style="width:35%">{{ $f['label'] }}</th><td>{{ $f['value'] }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p class="empty">No financial information.</p>
    @endif
</body>
</html>
