<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use DOMElement;

class DofService
{
    /**
     * Obtiene el tipo de cambio oficial publicado en el DOF
     * usando directamente el enlace de indicadores.
     *
     * @param string|null $fechaPago (Formato YYYY-MM-DD)
     * @return float|null
     */
    public static function obtenerTipoCambioDof($fechaPago = null)
    {
        $fecha = $fechaPago ? Carbon::parse($fechaPago) : Carbon::now();

        $dfecha = $fecha->copy()->subDays(5)->format('d/m/Y');
        $hfecha = $fecha->format('d/m/Y');

        $cacheKey = "tc_dof_directo_" . $fecha->format('Y-m-d');

        return Cache::remember($cacheKey, 86400, function () use ($dfecha, $hfecha) {
            try {
                $url = "https://dof.gob.mx/indicadores_detalle.php";

                $response = Http::withoutVerifying()
                    ->timeout(10)
                    ->get($url, [
                        'cod_tipo_indicador' => 158,
                        'dfecha'             => $dfecha,
                        'hfecha'             => $hfecha,
                    ]);

                if (!$response->successful()) {
                    return null;
                }

                $html = $response->body();

                libxml_use_internal_errors(true);
                $dom = new DOMDocument();
                $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
                libxml_clear_errors();

                $xpath = new DOMXPath($dom);
                $rows = $xpath->query("//tr");
                $ultimoTipoCambio = null;

                if ($rows) {
                    foreach ($rows as $row) {
                        /** @var DOMElement $row */
                        if (!$row instanceof DOMElement) {
                            continue;
                        }

                        $cols = $row->getElementsByTagName('td');
                        if ($cols->length >= 2) {
                            $textoValor = trim($cols->item(1)->nodeValue);

                            if (is_numeric($textoValor) && (float)$textoValor > 10) {
                                $ultimoTipoCambio = (float) $textoValor;
                            }
                        }
                    }
                }

                return $ultimoTipoCambio;

            } catch (\Exception $e) {
                logger()->error("Error al consultar TC directamente en DOF: " . $e->getMessage());
                return null;
            }
        });
    }
}