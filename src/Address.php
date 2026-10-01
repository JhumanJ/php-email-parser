<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser;

final readonly class Address
{
    public function __construct(public string $address, public ?string $name = null)
    {
    }
}
