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
