# PDF Export Setup

## Installation

To enable PDF export functionality, install the DomPDF package:

```bash
composer require barryvdh/laravel-dompdf
```

## Configuration

After installation, the PDF export will automatically use DomPDF. If DomPDF is not installed, the system will fall back to HTML output that can be printed from the browser.

## Usage

The PDF export endpoint is available at:

```
POST /api/branch-manager/ledger/export-pdf
```

### Request Body:

```json
{
    "view": "detailed",
    "startDate": "2024-12-01",
    "endDate": "2024-12-15",
    "transactionType": "Total Sales"
}
```

### Response:

-   If DomPDF is installed: Returns PDF file download
-   If DomPDF is not installed: Returns HTML that can be printed

## Features

-   Professional PDF formatting
-   Transaction history with filters
-   Branch manager information
-   Date range and type filters
-   Color-coded amounts (green for positive, red for negative)
