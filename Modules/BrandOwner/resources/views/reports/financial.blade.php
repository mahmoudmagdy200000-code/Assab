<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #222; font-size: 12px; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin: 18px 0 6px; border-bottom: 1px solid #ccc; padding-bottom: 3px; }
        table { border-collapse: collapse; width: 100%; margin-top: 4px; }
        th, td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; }
        th { background: #f5f5f5; }
        .kv td:first-child { width: 45%; color: #555; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>

    @foreach ($sections as $section)
        <h2>{{ $section['heading'] ?? '' }}</h2>

        @if (! empty($section['rows']))
            <table class="kv">
                @foreach ($section['rows'] as $row)
                    <tr>
                        <td>{{ $row['label'] ?? '' }}</td>
                        <td>{{ $row['value'] ?? '' }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        @if (! empty($section['table']))
            <table>
                <thead>
                    <tr>
                        @foreach ($section['table']['headers'] ?? [] as $header)
                            <th>{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['table']['rows'] ?? [] as $tableRow)
                        <tr>
                            @foreach ($tableRow as $cell)
                                <td>{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
</body>
</html>
