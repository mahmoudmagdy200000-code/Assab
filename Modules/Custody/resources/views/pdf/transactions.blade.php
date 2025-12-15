<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction History Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 20px;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
        }

        .info {
            margin-bottom: 20px;
        }

        .info table {
            width: 100%;
            border-collapse: collapse;
        }

        .info td {
            padding: 5px;
        }

        .info td:first-child {
            font-weight: bold;
            width: 150px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .amount-positive {
            color: #28a745;
        }

        .amount-negative {
            color: #dc3545;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>Personal Custody Balance - Transaction History</h1>
    </div>

    <div class="info">
        <table>
            <tr>
                <td>Branch Manager:</td>
                <td>{{ $branchManager->name ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td>View Type:</td>
                <td>{{ ucfirst($view) }}</td>
            </tr>
            <tr>
                <td>Total Transactions:</td>
                <td>{{ $totalTransactions }}</td>
            </tr>
            @if (!empty($filters['startDate']) || !empty($filters['endDate']))
                <tr>
                    <td>Date Range:</td>
                    <td>
                        @if (!empty($filters['startDate']))
                            From: {{ $filters['startDate'] }}
                        @endif
                        @if (!empty($filters['endDate']))
                            To: {{ $filters['endDate'] }}
                        @endif
                    </td>
                </tr>
            @endif
            @if (!empty($filters['transactionType']))
                <tr>
                    <td>Transaction Type:</td>
                    <td>{{ $filters['transactionType'] }}</td>
                </tr>
            @endif
            <tr>
                <td>Generated At:</td>
                <td>{{ $generatedAt }}</td>
            </tr>
        </table>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date & Time</th>
                <th>Transaction Type</th>
                <th>Cashier/Brand Owner</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transactions as $transaction)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($transaction['dateTime'])->format('Y-m-d H:i:s') }}</td>
                    <td>{{ $transaction['transactionType'] }}</td>
                    <td>
                        @if (isset($transaction['cashierName']))
                            {{ $transaction['cashierName'] }}
                        @elseif(isset($transaction['brandOwnerName']))
                            {{ $transaction['brandOwnerName'] }}
                        @else
                            -
                        @endif
                    </td>
                    <td
                        class="{{ str_starts_with($transaction['amount'], '+') ? 'amount-positive' : 'amount-negative' }}">
                        {{ $transaction['amount'] }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" style="text-align: center;">No transactions found</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <p>This report was generated on {{ $generatedAt }}</p>
        <p>© {{ date('Y') }} - All rights reserved</p>
    </div>
</body>

</html>
