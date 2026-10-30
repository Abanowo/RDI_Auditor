<?php

namespace App\Services\Senasica;

use App\Models\Auditoria;
use App\Models\Empresas;
use App\Models\Exportacion;
use App\Models\Importacion;
use Illuminate\Support\Facades\Schema;

/**
 * Datos fiscales del RECEPTOR de la factura de SENASICA = el CLIENTE de la operación.
 *
 * Las columnas de la tabla de clientes (Empresas) se detectan solas buscando
 * nombres comunes. Si en tu tabla se llaman distinto, ponlas en
 * config/senasica.php -> 'columnas_cliente'.
 */
class ReceptorCliente
{
    /** Nombres de columna que se prueban, en orden, si no se configuran */
    private const CANDIDATAS = [
        'nombre'        => ['razon_social', 'nombre_fiscal', 'razon_social_fiscal', 'nombre'],
        'rfc'           => ['rfc', 'rfc_cliente', 'rfc_fiscal'],
        'regimen'       => ['regimen_fiscal', 'clave_regimen_fiscal', 'regimen', 'regimen_fiscal_id'],
        'codigo_postal' => ['codigo_postal_fiscal', 'cp_fiscal', 'codigo_postal', 'cp', 'cod_postal'],
        'uso_cfdi'      => ['uso_cfdi', 'clave_uso_cfdi'],
        'correo'        => ['correo_facturacion', 'email_facturacion', 'correo', 'email'],
    ];

    private ?array $columnas = null;

    /**
     * @return array{receptor: array, cliente: string, faltantes: array}
     */
    public function paraPago(Auditoria $pago): array
    {
        $porDefecto = config('senasica.receptor', []);

        $operacion = null;
        if ($pago->operacion_id) {
            $operacion = str_contains((string) $pago->operation_type, 'Importacion')
                ? Importacion::with('cliente')->find($pago->operacion_id)
                : Exportacion::with('cliente')->find($pago->operacion_id);
        }

        $cliente = $operacion ? $operacion->cliente : null;

        $receptor = [
            'nombre'        => null,
            'rfc'           => null,
            'regimen'       => null,
            'codigo_postal' => null,
            // De la configuración solo se toman los valores "de uso", nunca la identidad fiscal
            'uso_cfdi'      => $porDefecto['uso_cfdi'] ?? 'G03',
            'correo'        => $porDefecto['correo'] ?? null,
            'metodo_pago'   => $porDefecto['metodo_pago'] ?? '03',
        ];

        if ($cliente) {
            foreach ($this->columnas() as $campo => $columna) {
                if (!$columna) {
                    continue;
                }
                $valor = trim((string) ($cliente->{$columna} ?? ''));
                if ($valor !== '') {
                    $receptor[$campo] = $valor;
                }
            }
        }

        $receptor['rfc'] = $receptor['rfc'] ? strtoupper(preg_replace('/[^A-Za-z0-9&Ññ]/u', '', $receptor['rfc'])) : null;
        $receptor['nombre'] = $receptor['nombre'] ? $this->limpiarRazonSocial($receptor['nombre']) : null;
        $receptor['regimen'] = $this->normalizarRegimen($receptor['regimen'], $receptor['rfc']);

        $faltantes = [];
        foreach (['nombre' => 'Nombre/Razón social', 'rfc' => 'RFC', 'regimen' => 'Régimen fiscal'] as $campo => $etiqueta) {
            if (empty($receptor[$campo])) {
                $faltantes[] = $etiqueta;
            }
        }

        return [
            'receptor'  => $receptor,
            'cliente'   => $cliente ? (string) ($cliente->nombre ?? '') : '',
            'faltantes' => $faltantes,
        ];
    }

    /**
     * Columnas reales de la tabla de clientes para cada dato.
     */
    public function columnas(): array
    {
        if ($this->columnas !== null) {
            return $this->columnas;
        }

        $tabla = (new Empresas())->getTable();
        $configuradas = config('senasica.columnas_cliente', []);
        $this->columnas = [];

        foreach (self::CANDIDATAS as $campo => $candidatas) {
            $this->columnas[$campo] = null;

            if (!empty($configuradas[$campo])) {
                $this->columnas[$campo] = $configuradas[$campo];
                continue;
            }

            foreach ($candidatas as $columna) {
                if (Schema::hasColumn($tabla, $columna)) {
                    $this->columnas[$campo] = $columna;
                    break;
                }
            }
        }

        return $this->columnas;
    }

    /**
     * CFDI 4.0: el nombre va como en la Constancia, SIN el régimen societario.
     * "COMERCIALIZADORA DEL PACIFICO, S.A. DE C.V." -> "COMERCIALIZADORA DEL PACIFICO"
     */
    public function limpiarRazonSocial(string $nombre): string
    {
        $n = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $nombre)));

        $sufijos = [
            'S\.?\s*A\.?\s*P\.?\s*I\.?\s*DE\s*C\.?\s*V\.?',
            'S\.?\s*A\.?\s*B\.?\s*DE\s*C\.?\s*V\.?',
            'S\.?\s*DE\s*R\.?\s*L\.?\s*DE\s*C\.?\s*V\.?',
            'S\.?\s*DE\s*R\.?\s*L\.?\s*MI\.?',
            'S\.?\s*DE\s*R\.?\s*L\.?',
            'S\.?\s*P\.?\s*R\.?\s*DE\s*R\.?\s*L\.?(\s*DE\s*C\.?\s*V\.?)?',
            'S\.?\s*A\.?\s*DE\s*C\.?\s*V\.?',
            'S\.?\s*C\.?\s*DE\s*R\.?\s*L\.?(\s*DE\s*C\.?\s*V\.?)?',
            'S\.?\s*A\.?',
            'S\.?\s*C\.?',
            'A\.?\s*C\.?',
        ];

        foreach ($sufijos as $sufijo) {
            $limpio = preg_replace('/[,\s]+' . $sufijo . '\s*$/u', '', $n);
            if ($limpio !== null && $limpio !== $n && mb_strlen($limpio) >= 3) {
                $n = $limpio;
                break;
            }
        }

        return trim($n, " ,.");
    }

    /**
     * Devuelve la clave del SAT (601, 612, 626...).
     * Sin dato: persona moral (RFC de 12) -> 601; persona física -> null (se debe capturar).
     */
    public function normalizarRegimen(?string $regimen, ?string $rfc): ?string
    {
        if ($regimen && preg_match('/\b(6\d{2})\b/', $regimen, $m)) {
            return $m[1];
        }
        if ($regimen && trim($regimen) !== '') {
            return trim($regimen); // texto, el script lo busca dentro de las opciones
        }
        if ($rfc && strlen($rfc) === 12) {
            return '601'; // General de Ley Personas Morales
        }
        return null;
    }
}
