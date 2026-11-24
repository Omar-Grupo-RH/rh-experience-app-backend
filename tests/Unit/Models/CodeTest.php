<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Model\Code;
use PHPUnit\Framework\TestCase;

final class CodeTest extends TestCase
{
    public function testConstructorNormalizesValues(): void
    {
        $code = new Code([
            'type' => '  alliance ',
            'name' => '  Alianza Norte ',
            'code' => '  rh-2026_a ',
        ]);

        $this->assertSame('alliance', $code->type);
        $this->assertSame('Alianza Norte', $code->name);
        $this->assertSame('RH-2026_A', $code->code);
    }

    public function testValidCodePassesValidation(): void
    {
        $code = new Code([
            'type' => 'department',
            'name' => 'Recursos Humanos',
            'code' => 'RH-01',
        ]);

        $this->assertSame([], $code->validar());
    }

    public function testEveryAllowedTypeIsAccepted(): void
    {
        foreach (['department', 'alliance', 'discount', 'internal'] as $type) {
            $code = new Code(['type' => $type, 'name' => 'X', 'code' => 'X1']);
            $this->assertSame([], $code->validar(), "Tipo '$type' debería ser válido");
        }
    }

    public function testInvalidTypeIsRejected(): void
    {
        $code = new Code(['type' => 'promo', 'name' => 'X', 'code' => 'X1']);

        $this->assertContains('El tipo de código no es válido.', $code->validar());
    }

    public function testMissingNameAndCodeAreRejected(): void
    {
        $code = new Code(['type' => 'discount']);
        $errores = $code->validar();

        $this->assertContains('El nombre es obligatorio.', $errores);
        $this->assertContains('El código es obligatorio.', $errores);
    }

    public function testNameLongerThan120CharsIsRejected(): void
    {
        $code = new Code([
            'type' => 'discount',
            'name' => str_repeat('a', 121),
            'code' => 'OK-1',
        ]);

        $this->assertContains(
            'El nombre no puede tener más de 120 caracteres.',
            $code->validar()
        );
    }

    public function testCodeLongerThan80CharsIsRejected(): void
    {
        $code = new Code([
            'type' => 'discount',
            'name' => 'Ok',
            'code' => str_repeat('A', 81),
        ]);

        $this->assertContains(
            'El código no puede tener más de 80 caracteres.',
            $code->validar()
        );
    }

    public function testCodeWithInvalidCharactersIsRejected(): void
    {
        $code = new Code([
            'type' => 'discount',
            'name' => 'Ok',
            'code' => 'AB C$',
        ]);

        $this->assertContains(
            'El código solo puede contener letras, números, guiones y guion bajo.',
            $code->validar()
        );
    }
}
