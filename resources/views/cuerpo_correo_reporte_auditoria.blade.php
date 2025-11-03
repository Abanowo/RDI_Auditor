<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Auditoría</title>
</head>

<body style="font-family: Arial, sans-serif; line-height: 1.6;">

    <h2>Reporte de Auditoría Automático</h2>

    <p>Hola,</p>

    <p>Se ha completado un proceso de auditoría. Los reportes generados se encuentran adjuntos a este correo.</p>

    <ul>
        <li><strong>Estado de cuenta:</strong> {{ $tarea->nombre_archivo }}</li>

        <li><strong>Banco:</strong> {{ $tarea->banco }}</li>
        <li><strong>Sucursal:</strong> {{ $tarea->sucursal }}</li>
        <li><strong>Fecha de Creacion:</strong> {{ $tarea->created_at->format('d/m/Y H:i') }}</li>
        <li><strong>Fecha de Proceso:</strong> {{ $tarea->updated_at->format('d/m/Y H:i') }}</li>
    </ul>
    @if(!empty($tarea->pedimentos_descartados))
        @php
            // El modelo ya convierte 'pedimentos_descartados' en un array
            $descartados = $tarea->pedimentos_descartados;
            $lista_descartados = $descartados ? array_keys($descartados) : [];
        @endphp

        @if(count($lista_descartados) > 0)
            <div
                style="padding: 10px; background-color: #FFFBE6; border: 1px solid #FFE58F; border-radius: 4px; margin-top: 15px;">
                <strong style="color: #D46B08;">Aviso:</strong> Se descartaron los siguientes pedimentos (no se encontraron en
                la base de datos):
                <ul style="margin-top: 5px; padding-left: 20px;">
                    @foreach($lista_descartados as $pedimento)
                        <li>{{ $pedimento }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
    <p>
        <strong>Reportes Adjuntos:</strong>
        <br>
        - {{ $tarea->nombre_reporte_impuestos }} [Reporte de Impuestos - Facturados]
        <br>
        - {{ $tarea->nombre_reporte_pendientes }} [Reporte de Impuestos - Pendientes]
    </p>

    <hr>
    <p style="font-size: 0.8em; color: #777;">
        Este es un correo generado automáticamente. Por favor, no respondas a este mensaje.
    </p>

</body>

</html>