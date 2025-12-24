<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #4CAF50;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }
        .content {
            background-color: #f9f9f9;
            padding: 30px;
            border-radius: 0 0 5px 5px;
        }
        .message {
            background-color: white;
            padding: 20px;
            border-left: 4px solid #4CAF50;
            margin: 20px 0;
        }
        .footer {
            text-align: center;
            margin-top: 20px;
            color: #666;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Assab Notification</h1>
    </div>
    <div class="content">
        <h2>{{ $title }}</h2>
        <div class="message">
            <p>{{ $message }}</p>
        </div>
        @if(!empty($data))
            <div style="margin-top: 20px;">
                <h3>Details:</h3>
                <ul>
                    @foreach($data as $key => $value)
                        <li><strong>{{ ucfirst(str_replace('_', ' ', $key)) }}:</strong> {{ $value }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
    <div class="footer">
        <p>This is an automated notification from Assab System.</p>
        <p>&copy; {{ date('Y') }} Assab. All rights reserved.</p>
    </div>
</body>
</html>

