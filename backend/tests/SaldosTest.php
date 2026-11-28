<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Reglas de saldo de lib/saldos.php: la ÚNICA fuente de "por cobrar",
 * "por emitir", "vencida" y tramos que usan Panel, Pagos, Reportes, Clientes
 * y la ficha del cliente. Si estas reglas cambian, cambian todas las cifras.
 */
final class SaldosTest extends TestCase
{
    private const HOY = '2026-11-25';

    /** Cuota armada como la devuelve saldosCuotas() (sin BD). */
    private function cuota(array $c): array
    {
        $c += ['cuota_id' => 1, 'pago_id' => 1, 'proyecto_id' => 1, 'cliente_id' => 1,
               'tipo_pago' => 'factura', 'estado' => 'Pendiente', 'boleta_num' => '', 'boleta_fecha' => null,
               'boleta_monto' => 0, 'pago_monto' => 0];
        $c['monto']   = cuotaMonto($c);
        $c['cobrado'] = (int)$c['pago_monto'];
        $c['saldo']   = saldoCuota($c);
        $c['dias']    = $c['boleta_fecha'] ? (int)((strtotime(self::HOY) - strtotime($c['boleta_fecha'])) / 86400) : null;
        $c['emitida'] = cuotaEmitida($c, self::HOY);
        $c['vencida'] = cuotaVencida($c, self::HOY);
        $c['tramo']   = saldoTramo($c['dias']);
        return $c;
    }

    public function testSaldoDeCuotaParcialYPagada(): void
    {
        $this->assertSame(10000, saldoCuota(['estado' => 'Parcial', 'boleta_monto' => 60000, 'pago_monto' => 50000]));
        $this->assertSame(0, saldoCuota(['estado' => 'Pagado', 'boleta_monto' => 60000, 'pago_monto' => 60000]));
        $this->assertSame(60000, saldoCuota(['estado' => 'Pendiente', 'boleta_monto' => 60000, 'pago_monto' => 0]));
        // Pagado de más no da saldo negativo
        $this->assertSame(0, saldoCuota(['estado' => 'Parcial', 'boleta_monto' => 100, 'pago_monto' => 150]));
    }

    public function testMontoDeCuotaDirectaSinMontoAcordado(): void
    {
        // Pago directo registrado sin monto acordado: vale lo cobrado
        $this->assertSame(30000, cuotaMonto(['boleta_monto' => 0, 'pago_monto' => 30000]));
    }

    public function testEmitidaRequiereNumeroYFechaCumplida(): void
    {
        $this->assertTrue(cuotaEmitida(['tipo_pago' => 'factura', 'boleta_num' => '123', 'boleta_fecha' => '2026-11-01'], self::HOY));
        $this->assertTrue(cuotaEmitida(['tipo_pago' => 'factura', 'boleta_num' => '123', 'boleta_fecha' => self::HOY], self::HOY));
        // Solo número (etiqueta tipo «Fase 2»): todavía no se emite
        $this->assertFalse(cuotaEmitida(['tipo_pago' => 'factura', 'boleta_num' => 'Fase 2', 'boleta_fecha' => null], self::HOY));
        // Fecha futura: por emitir
        $this->assertFalse(cuotaEmitida(['tipo_pago' => 'boleta', 'boleta_num' => '9', 'boleta_fecha' => '2026-12-15'], self::HOY));
        // Sin número
        $this->assertFalse(cuotaEmitida(['tipo_pago' => 'factura', 'boleta_num' => '', 'boleta_fecha' => '2026-11-01'], self::HOY));
        // El pago directo no espera documento
        $this->assertTrue(cuotaEmitida(['tipo_pago' => 'directo'], self::HOY));
    }

    public function testVencidaTrasElPlazo(): void
    {
        $base = ['tipo_pago' => 'factura', 'boleta_num' => '1', 'estado' => 'Pendiente', 'boleta_monto' => 1000, 'pago_monto' => 0];
        $this->assertFalse(cuotaVencida($base + ['boleta_fecha' => '2026-11-01'], self::HOY)); // 24 días
        $this->assertTrue(cuotaVencida($base + ['boleta_fecha' => '2026-10-01'], self::HOY));  // 55 días
        // Plazo de pago configurable (Configuración → Finanzas): con 15 días, 24 ya es vencida;
        // con 60, 55 todavía no.
        $this->assertTrue(cuotaVencida($base + ['boleta_fecha' => '2026-11-01'], self::HOY, 15));
        $this->assertFalse(cuotaVencida($base + ['boleta_fecha' => '2026-10-01'], self::HOY, 60));
        // Pagada no vence aunque sea vieja
        $this->assertFalse(cuotaVencida(['estado' => 'Pagado', 'pago_monto' => 1000] + $base + ['boleta_fecha' => '2026-01-01'], self::HOY));
    }

    public function testTramos(): void
    {
        $this->assertSame('vigente', saldoTramo(null));
        $this->assertSame('vigente', saldoTramo(0));
        $this->assertSame('1-30', saldoTramo(30));
        $this->assertSame('31-60', saldoTramo(31));
        $this->assertSame('61-90', saldoTramo(90));
        $this->assertSame('+90', saldoTramo(91));
    }

    public function testResumenSeparaPorCobrarDePorEmitir(): void
    {
        $cuotas = [
            // Emitida hace 55 días, parcial: 10.000 por cobrar y vencida
            $this->cuota(['cuota_id' => 1, 'pago_id' => 1, 'boleta_num' => '55', 'boleta_fecha' => '2026-10-01',
                          'estado' => 'Parcial', 'boleta_monto' => 60000, 'pago_monto' => 50000]),
            // Acordada para una fase futura (sin número): por emitir
            $this->cuota(['cuota_id' => 2, 'pago_id' => 1, 'boleta_num' => '', 'boleta_monto' => 40000]),
            // Pagada: suma al total y al cobrado, no al saldo
            $this->cuota(['cuota_id' => 3, 'pago_id' => 2, 'boleta_num' => '56', 'boleta_fecha' => '2026-11-10',
                          'estado' => 'Pagado', 'boleta_monto' => 20000, 'pago_monto' => 20000]),
        ];
        $r = saldoResumen($cuotas);
        $this->assertSame(120000, $r['total']);
        $this->assertSame(70000, $r['cobrado']);
        $this->assertSame(10000, $r['por_cobrar']);
        $this->assertSame(40000, $r['por_emitir']);
        $this->assertSame(50000, $r['falta']);
        $this->assertSame(1, $r['vencidas']);
        $this->assertSame(10000, $r['vencido']);
        $this->assertSame(1, $r['pagos_pendientes']);
        $this->assertSame(10000, $r['tramos']['31-60']['total']);
    }

    public function testResumenPorCliente(): void
    {
        $cuotas = [
            $this->cuota(['cuota_id' => 1, 'cliente_id' => 7, 'tipo_pago' => 'directo', 'boleta_monto' => 5000]),
            $this->cuota(['cuota_id' => 2, 'cliente_id' => 8, 'tipo_pago' => 'directo', 'boleta_monto' => 3000]),
        ];
        $por = saldoResumenPor($cuotas, 'cliente_id');
        $this->assertSame(5000, $por[7]['por_cobrar']);
        $this->assertSame(3000, $por[8]['por_cobrar']);
    }
}
