<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Model\Client;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testConstructorNormalizesValues(): void
    {
        $client = new Client([
            'full_name' => '  Juan Pérez ',
            'username' => ' jperez ',
            'password' => 'secreto',
            'admin' => '1',
        ]);

        $this->assertSame('Juan Pérez', $client->full_name);
        $this->assertSame('jperez', $client->username);
        $this->assertSame(1, $client->admin);
    }

    public function testAdminDefaultsToZero(): void
    {
        $client = new Client();

        $this->assertSame(0, $client->admin);
    }

    public function testHashearPasswordProducesVerifiableBcryptHash(): void
    {
        $client = new Client(['password' => 'secreto123']);
        $client->hashearPassword();

        $this->assertNotSame('secreto123', $client->password);
        $this->assertTrue(password_verify('secreto123', $client->password));
        $this->assertStringStartsWith('$2y$', $client->password);
    }

    public function testHashearPasswordDoesNothingWithEmptyPassword(): void
    {
        $client = new Client(['password' => '']);
        $client->hashearPassword();

        $this->assertSame('', $client->password);
    }
}
