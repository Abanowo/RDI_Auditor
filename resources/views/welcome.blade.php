<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Auditor INTACTICS 2025</title>
    <link href="{{ mix('css/app.css') }}" rel="stylesheet">

    <script>
        window.UsuarioActual = {!! json_encode(\App\Models\User::find(6)) !!} || {};//Linea de ejemplo para obtener el usuario actual
        /* window.UsuarioActual = {!! json_encode(auth()->user()) !!} || {}; */ //Linea de producción para obtener el usuario actual desde Laravel
    </script>
    
</head>
<body class="antialiased">
    <div id="app">
        <lista-auditorias/>
    </div>
    <style>
        html {
            font-size: 10px;
        }
    </style>
    <script src="{{ mix('js/app.js') }}" defer></script>
</body>
</html>
