<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error en Reporte de Auditoría</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td style="padding: 20px 0;">
                <table align="center" border="0" cellpadding="0" cellspacing="0" width="600" style="border-collapse: collapse; border: 1px solid #cccccc; background-color: #ffffff;">
                    <!-- Cabecera de Error -->
                    <tr>
                        <td align="center" style="padding: 40px 0 30px 0; background-color: #dc3545; color: #ffffff; font-size: 24px; font-family: Arial, sans-serif;">
                            <b>⚠️ Ocurrió un Error en la Auditoría</b>
                        </td>
                    </tr>
                    <!-- Contenido Principal -->
                    <tr>
                        <td style="padding: 40px 30px; font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6;">
                            <p>Lamentamos informarte que se produjo un error durante el procesamiento automático de la auditoría. No se pudieron generar los reportes.</p>

                            <!-- Caja de Acción Sugerida -->
                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-top: 20px; margin-bottom: 20px;">
                                <tr>
                                    <td style="padding: 15px; background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px; color: #721c24;">
                                        <strong style="font-size: 16px;">Acción Sugerida para el Usuario:</strong>
                                        <p style="margin-top: 10px; margin-bottom: 0;">La causa más común de este tipo de errores es un problema con el archivo de estado de cuenta subido. Por favor, verifica lo siguiente:</p>
                                        <ul style="padding-left: 20px; margin-top: 10px; margin-bottom: 0;">
                                            <li>Que el archivo PDF <strong>no sea una imagen escaneada</strong> (el texto debe poder seleccionarse).</li>
                                            <li>Que el archivo no esté protegido por contraseña.</li>
                                            <li>Que el formato del archivo sea el correcto.</li>
                                        </ul>
                                        <p style="margin-top: 10px; margin-bottom: 0;">Si corriges el archivo, por favor, intenta subirlo de nuevo. El archivo original que causó el error se encuentra adjunto en este correo.</p>
                                    </td>
                                </tr>
                            </table>

                            <h4 style="margin-top: 30px; margin-bottom: 10px;">Detalles de la Tarea Fallida:</h4>
                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="font-family: Arial, sans-serif; font-size: 16px;">
                                <tr>
                                    <td width="150" valign="top"><strong>Estado de cuenta:</strong></td>
                                    <td>{{ $tarea->nombre_archivo }}</td>
                                </tr>
                                <tr>
                                    <td valign="top"><strong>Banco:</strong></td>
                                    <td>{{ $tarea->banco }}</td>
                                </tr>
                                <tr>
                                    <td valign="top"><strong>Sucursal:</strong></td>
                                    <td>{{ $tarea->sucursal }}</td>
                                </tr>
                                <tr>
                                    <td valign="top"><strong>Fecha de Fallo:</strong></td>
                                    <td>{{ now()->format('d/m/Y H:i') }}</td>
                                </tr>
                            </table>

                            <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eeeeee;">

                            <h4 style="margin-top: 20px; margin-bottom: 10px;">Información Técnica para el Desarrollador:</h4>
                            <p style="margin: 0;"><strong>Mensaje de la Excepción:</strong></p>
                            <p style="padding: 10px; background-color: #f9f2f4; border: 1px solid #f5c6cb; color: #721c24; font-family: monospace; font-size: 14px; white-space: pre-wrap; word-wrap: break-word;">{{ $exception->getMessage() }}</p>

                            <p style="margin: 15px 0 0 0;"><strong>Ruta del Error:</strong></p>
                            <p style="padding: 10px; background-color: #f0f0f0; border: 1px solid #ccc; font-family: monospace; font-size: 14px; white-space: pre-wrap; word-wrap: break-word;">{{ $exception->getFile() }} en la línea {{ $exception->getLine() }}</p>
                        </td>
                    </tr>
                    <!-- Pie de Página -->
                    <tr>
                        <td style="padding: 30px; background-color: #eeeeee; text-align: center; color: #777777; font-family: Arial, sans-serif; font-size: 12px;">
                            Este es un correo generado automáticamente.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
