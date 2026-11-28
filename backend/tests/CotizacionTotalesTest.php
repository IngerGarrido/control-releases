<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/cotizacion_totales.php';
require_once __DIR__ . '/../finance.php';

final class CotizacionTotalesTest extends TestCase
{
    public function testNetoIvaTotal(): void
    {
        $t = cotizacionCalcular([['cantidad' => 1, 'precio_unitario' => 380000]], 0, true, 19);
        $this->assertSame(380000, $t['neto']);
        $this->assertSame(72200, $t['iva']);
        $this->assertSame(452200, $t['total']);
    }

    public function testDescuentoSeRedondeaUnaSolaVez(): void
    {
        // 3 ítems con decimales + 33,3333 %: neto + IVA deben cuadrar con el total.
        $items = [['cantidad' => 1.5, 'precio_unitario' => 33333], ['cantidad' => 2, 'precio_unitario' => 99999.5], ['cantidad' => 1, 'precio_unitario' => 1]];
        $t = cotizacionCalcular($items, 33.3333, true, 19);
        $this->assertSame($t['subtotal'] - $t['descuento'], $t['neto']);
        $this->assertSame($t['neto'] + $t['iva'], $t['total']);
        $this->assertSame((int)round($t['neto'] * 0.19), $t['iva']);
    }

    public function testSinIva(): void
    {
        $t = cotizacionCalcular([['cantidad' => 2, 'precio_unitario' => 50000]], 10, false, 19);
        $this->assertSame(90000, $t['neto']);
        $this->assertSame(0, $t['iva']);
        $this->assertSame(90000, $t['total']);
        $this->assertSame(0.0, $t['iva_pct']);
    }

    public function testDescuentoAcotado(): void
    {
        $this->assertSame(0, cotizacionCalcular([['cantidad' => 1, 'precio_unitario' => 1000]], 150, false, 19)['neto']);
        $this->assertSame(1000, cotizacionCalcular([['cantidad' => 1, 'precio_unitario' => 1000]], -5, false, 19)['neto']);
    }

    public function testCleanIntNoMultiplicaDecimales(): void
    {
        $this->assertSame(1201, finance_clean_int(1200.5));
        $this->assertSame(1201, finance_clean_int('1200.50'));
        $this->assertSame(1201, finance_clean_int('1200,5'));
        // Formato chileno de miles: sigue igual.
        $this->assertSame(95000, finance_clean_int('$95.000'));
        $this->assertSame(1200, finance_clean_int('1.200'));
        $this->assertSame(1500000, finance_clean_int('1.500.000'));
        $this->assertSame(95000, finance_clean_int(95000));
    }
}
