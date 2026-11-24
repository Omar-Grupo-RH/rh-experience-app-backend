<?php

declare(strict_types=1);

namespace Tests\Support;

final class FakeResult
{
    private int $index = 0;

    public function __construct(private array $rows)
    {
    }

    public function fetch_assoc(): ?array
    {
        return $this->rows[$this->index++] ?? null;
    }
}
