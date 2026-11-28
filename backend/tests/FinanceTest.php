<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests de la matemática financiera (finance.php): neto/IVA/total, abonos
 * parciales y estado de cobro, retención de honorarios. Es la lógica de dinero
 * más delicada del sistema y la que más correcciones ha tenido.
 */
final class FinanceTest extends TestCase
{
    // ─── finance_clean_int (parseo de montos formateados) ────────
    public function testCleanIntParsesFormattedAmounts(): void
    {
        $this->assertSame(95000, finance_clean_int('$95.000'));
        $this->assertSame(1200,  finance_clean_int('1.200'));
        $this->assertSame(0,     finance_clean_int(''));
        $this->assertSame(0,     finance_clean_int(null));
        $this->assertSame(500,   finance_clean_int(500));
    }

    // ─── finance_calc_doc: FACTURA (neto → iva/total) ────────────
    public function testFacturaCalculaIvaYTotalSobreNeto(): void
    {
        $r = finance_calc_doc(['tipo_pago' => 'factura', 'neto' => 100000], 19.0);
        $this->assertSame('factura', $r['tipo']);
        $this->assertSame(100000, $r['neto']);
        $this->assertSame(19000,  $r['iva']);
        $this->assertSame(119000, $r['total']); // neto + iva
    }

    public function testFacturaRedondeaIva(): void
    {
        // 95.000 × 19% = 18.050 exacto; probamos un caso con redondeo real:
        $r = finance_calc_doc(['tipo_pago' => 'factura', 'neto' => 99999], 19.0);
        $this->assertSame(19000, $r['iva']); // round(18999.81) = 19000
        $this->assertSame(118999, $r['total']);
    }

    // ─── finance_calc_doc: BOLETA / DIRECTO (monto = total, sin IVA) ─
    public function testBoletaNoDesglosaIva(): void
    {
        $r = finance_calc_doc(['tipo_pago' => 'boleta', 'boleta_monto' => 80000], 19.0);
        $this->assertSame('boleta', $r['tipo']);
        $this->assertNull($r['neto']);
        $this->assertNull($r['iva']);
        $this->assertSame(80000, $r['total']);
    }

    public function testTipoInvalidoCaeAFactura(): void
    {
        $r = finance_calc_doc(['tipo_pago' => 'xxx', 'neto' => 10000], 19.0);
        $this->assertSame('factura', $r['tipo']);
        $this->assertSame(1900, $r['iva']);
    }

    // ─── finance_procesar_abonos: suma / estado / última fecha ───
    public function testAbonosSinPagoQuedaPendiente(): void
    {
        $r = finance_procesar_abonos(['tipo_pago' => 'boleta', 'boleta_monto' => 50000, 'abonos' => []]);
        $this->assertSame(0, $r['suma']);
        $this->assertSame('Pendiente', $r['estado']);
        $this->assertNull($r['maxFecha']);
    }

    public function testAbonoParcialEnBoleta(): void
    {
        $r = finance_procesar_abonos([
            'tipo_pago' => 'boleta', 'boleta_monto' => 100000,
            'abonos' => [['monto' => 40000, 'fecha' => '2026-01-10']],
        ]);
        $this->assertSame(40000, $r['suma']);
        $this->assertSame('Parcial', $r['estado']);
        $this->assertSame('2026-01-10', $r['maxFecha']);
    }

    public function testAbonosSumadosCompletanPago(): void
    {
        $r = finance_procesar_abonos([
            'tipo_pago' => 'boleta', 'boleta_monto' => 100000,
            'abonos' => [
                ['monto' => 60000, 'fecha' => '2026-01-10'],
                ['monto' => 40000, 'fecha' => '2026-02-05'],
            ],
        ]);
        $this->assertSame(100000, $r['suma']);
        $this->assertSame('Pagado', $r['estado']);
        $this->assertSame('2026-02-05', $r['maxFecha']); // fecha del último abono
    }

    public function testDirectoNuncaEsParcial(): void
    {
        // Un pago 'directo' con abono menor al monto igual queda Pagado (no Parcial).
        $r = finance_procesar_abonos([
            'tipo_pago' => 'directo', 'boleta_monto' => 100000,
            'abonos' => [['monto' => 30000, 'fecha' => '2026-01-10']],
        ]);
        $this->assertSame('Pagado', $r['estado']);
    }

    public function testAbonosIgnoraMontosInvalidos(): void
    {
        $r = finance_procesar_abonos([
            'tipo_pago' => 'boleta', 'boleta_monto' => 100000,
            'abonos' => [
                ['monto' => 0, 'fecha' => '2026-01-10'],       // monto 0 → ignorado
                ['monto' => 50000, 'fecha' => ''],             // sin fecha → ignorado
                ['monto' => 50000, 'fecha' => '2026-01-12'],   // válido
            ],
        ]);
        $this->assertSame(50000, $r['suma']);
        $this->assertCount(1, $r['abonos']);
    }

    public function testAbonosCompatibilidadPagoMontoLegacy(): void
    {
        // Sin abonos[] pero con pago_monto (cliente viejo) → se arma un abono.
        $r = finance_procesar_abonos([
            'tipo_pago' => 'boleta', 'boleta_monto' => 100000,
            'pago_monto' => 100000, 'pago_fecha' => '2026-03-01',
        ]);
        $this->assertSame(100000, $r['suma']);
        $this->assertSame('Pagado', $r['estado']);
        $this->assertSame('2026-03-01', $r['maxFecha']);
    }

    // ─── finance_retencion_honorarios ────────────────────────────
    public function testRetencionHonorarios2026(): void
    {
        $r = finance_retencion_honorarios(1000000, 15.25);
        $this->assertSame(1000000, $r['bruto']);
        $this->assertSame(152500,  $r['retencion']);
        $this->assertSame(847500,  $r['liquido']); // bruto − retención
    }
}
