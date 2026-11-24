<?php

declare(strict_types=1);

namespace Tests\Unit\Includes;

use PHPUnit\Framework\TestCase;

final class FuncionesTest extends TestCase
{
    // ---------- s() ----------

    public function testSEscapesHtml(): void
    {
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            s('<script>alert(1)</script>')
        );
    }

    public function testSEscapesQuotes(): void
    {
        $this->assertSame('&quot;hola&quot;', s('"hola"'));
        $this->assertSame('&#039;hola&#039;', s("'hola'"));
    }

    public function testSKeepsPlainTextIntact(): void
    {
        $this->assertSame('Hola Mundo', s('Hola Mundo'));
    }

    // ---------- cleanString() ----------

    public function testCleanStringTrimsCollapsesAndLowercases(): void
    {
        $this->assertSame('hola mundo', cleanString('   Hola    Mundo   '));
    }

    public function testCleanStringHandlesNull(): void
    {
        $this->assertSame('', cleanString(null));
    }

    public function testCleanStringNormalizesTabsAndNewlines(): void
    {
        $this->assertSame('a b c', cleanString("A\t B\n\nC"));
    }

    // ---------- formatearFecha() ----------

    public function testFormatearFechaReturnsSpanishLongDate(): void
    {
        $this->assertSame('5 de Enero de 2026', formatearFecha('2026-01-05'));
        $this->assertSame('31 de Diciembre de 2025', formatearFecha('2025-12-31'));
        $this->assertSame('15 de Septiembre de 2026', formatearFecha('2026-09-15'));
    }

    // ---------- pagina_actual() ----------

    public function testPaginaActualMatchesPathInfo(): void
    {
        $_SERVER['PATH_INFO'] = '/admin/trips';

        $this->assertTrue(pagina_actual('/admin'));
        $this->assertTrue(pagina_actual('/trips'));
        $this->assertFalse(pagina_actual('/surveys'));

        unset($_SERVER['PATH_INFO']);
    }

    public function testPaginaActualWithoutPathInfoReturnsFalse(): void
    {
        unset($_SERVER['PATH_INFO']);

        $this->assertFalse(pagina_actual('/admin'));
    }
}
