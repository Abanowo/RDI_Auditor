<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Auditor INTACTICS 2025</title>
    <link href="{{ mix('css/app.css') }}" rel="stylesheet">
</head>
<body class="antialiased">
    <div id="app">
        <lista-auditorias/>
    </div>

    <script src="{{ mix('js/app.js') }}" defer></script>
</body>
</html>
