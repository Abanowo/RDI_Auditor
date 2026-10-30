<?php

namespace App\Services\Senasica;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Timbra o descarga la factura de un Pago de Derecho en el portal de SENASICA.
 * Ejecuta scripts/senasica/autofactura.mjs (Playwright) y guarda los archivos
 * en storage/app/senasica/facturas/{pedimento}/{llave}.pdf|.xml
 */
class AutofacturaSenasica
{
    /**
     * @param string      $llavePago
     * @param string      $numOperacion
     * @param string      $carpetaPedimento  Nombre de carpeta (ej. 7 dígitos del pedimento)
     * @param string|null $debugDir          Ruta absoluta para capturas/HTML de cada paso
     * @param array       $receptor          Datos fiscales del cliente (ReceptorCliente::paraPago)
     *
     * @return array{estado:string, mensaje:string, ruta_pdf:?string, ruta_xml:?string, uuid:?string}
     */
    public function procesar(string $llavePago, string $numOperacion, string $carpetaPedimento, ?string $debugDir = null, array $receptor = []): array
    {
        $llaveArchivo = preg_replace('/[^A-Za-z0-9]/', '', $llavePago);
        $dirTemporal = storage_path('app/senasica/tmp/' . $llaveArchivo . '_' . uniqid());

        $parametros = [
            'url'          => config('senasica.url'),
            'urlLogin'     => config('senasica.url_login'),
            'cuenta'       => config('senasica.cuenta'),
            'numOperacion' => $numOperacion,
            'llavePago'    => $llaveArchivo,
            'receptor'     => $receptor,
            'dirDescarga'  => $dirTemporal,
            'headless'     => filter_var(config('senasica.headless'), FILTER_VALIDATE_BOOLEAN),
            'debugDir'     => $debugDir,
            'canal'        => config('senasica.canal') ?: null,
        ];

        $proceso = new Process([
            config('senasica.node', 'node'),
            config('senasica.script'),
            json_encode($parametros, JSON_UNESCAPED_UNICODE),
        ]);
        $proceso->setTimeout(config('senasica.timeout', 240));

        try {
            $proceso->run();
        } catch (\Throwable $e) {
            $this->borrarCarpeta($dirTemporal);
            return $this->resultado('error', 'No se pudo ejecutar el navegador: ' . $e->getMessage());
        }

        $resultado = $this->leerResultado($proceso->getOutput());

        if ($resultado === null) {
            $detalle = trim($proceso->getErrorOutput()) ?: trim($proceso->getOutput());
            $this->borrarCarpeta($dirTemporal);
            return $this->resultado('error', 'El script no devolvió resultado. ' . mb_substr($detalle, 0, 500));
        }

        // Mover los archivos a su carpeta final y validar
        $carpetaFinal = trim(config('senasica.carpeta', 'senasica/facturas'), '/') . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $carpetaPedimento);
        $rutaPdf = $this->moverArchivo($resultado['pdf'] ?? null, $carpetaFinal . '/' . $llaveArchivo . '.pdf');
        $rutaXml = $this->moverArchivo($resultado['xml'] ?? null, $carpetaFinal . '/' . $llaveArchivo . '.xml');
        $this->borrarCarpeta($dirTemporal);

        $uuid = $rutaXml ? $this->extraerUuid(Storage::get($rutaXml)) : null;

        return $this->resultado(
            (string) ($resultado['estado'] ?? 'error'),
            (string) ($resultado['mensaje'] ?? ''),
            $rutaPdf,
            $rutaXml,
            $uuid
        );
    }

    /**
     * El script imprime el JSON en la última línea con contenido.
     */
    private function leerResultado(string $salida): ?array
    {
        $lineas = array_reverse(preg_split('/\r\n|\r|\n/', trim($salida)));
        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if ($linea === '' || $linea[0] !== '{') {
                continue;
            }
            $json = json_decode($linea, true);
            if (is_array($json) && isset($json['estado'])) {
                return $json;
            }
        }
        return null;
    }

    private function moverArchivo(?string $origenAbsoluto, string $destinoRelativo): ?string
    {
        if (!$origenAbsoluto || !is_file($origenAbsoluto) || filesize($origenAbsoluto) === 0) {
            return null;
        }
        Storage::put($destinoRelativo, file_get_contents($origenAbsoluto));
        return $destinoRelativo;
    }

    private function extraerUuid(?string $xml): ?string
    {
        if (!$xml) {
            return null;
        }
        if (preg_match('/TimbreFiscalDigital[^>]+UUID=["\']([0-9A-Fa-f\-]{36})["\']/s', $xml, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }

    private function borrarCarpeta(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $archivo) {
            @unlink($archivo);
        }
        @rmdir($dir);
    }

    private function resultado(string $estado, string $mensaje, ?string $pdf = null, ?string $xml = null, ?string $uuid = null): array
    {
        return [
            'estado'   => $estado,
            'mensaje'  => $mensaje,
            'ruta_pdf' => $pdf,
            'ruta_xml' => $xml,
            'uuid'     => $uuid,
        ];
    }
}
