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

        .download-btn-container {
            text-align: center;
            margin-bottom: 20px;
        }

        .download-pdf-btn {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 12px 24px;
            font-size: 16px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .download-pdf-btn:hover {
            background-color: #0056b3;
        }

        .download-pdf-btn:active {
            background-color: #004085;
        }

        @media print {
            .download-btn-container {
                display: none;
            }
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>

<body>
    <div class="download-btn-container">
        <button class="download-pdf-btn" onclick="downloadAsPDF()">Download as PDF</button>
    </div>

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

    <script>
        function downloadAsPDF() {
            const button = document.querySelector('.download-pdf-btn');
            const originalText = button.textContent;
            
            // Disable button and show loading
            button.disabled = true;
            button.textContent = 'Generating PDF...';
            
            // Get the element to convert (everything except the button)
            const element = document.body;
            const opt = {
                margin: [10, 10, 10, 10],
                filename: 'transaction_history_{{ $branchManager->id ?? "report" }}_{{ now()->format("Y-m-d_His") }}.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };

            // Generate PDF
            html2pdf().set(opt).from(element).save().then(function() {
                // Re-enable button
                button.disabled = false;
                button.textContent = originalText;
            }).catch(function(error) {
                console.error('Error generating PDF:', error);
                button.disabled = false;
                button.textContent = originalText;
                alert('Failed to generate PDF. Please try again or use the browser print function (Ctrl+P).');
            });
        }
    </script>
</body>

</html>
