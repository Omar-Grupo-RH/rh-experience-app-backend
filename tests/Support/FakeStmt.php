<?php

declare(strict_types=1);

namespace Tests\Support;

final class FakeStmt
{
    public function __construct(private array $rows)
    {
    }

    public function bind_param(string $types, mixed ...$params): bool
    {
        return true;
    }

    public function execute(): bool
    {
        return true;
    }

    public function get_result(): FakeResult
    {
        return new FakeResult($this->rows);
    }
}
