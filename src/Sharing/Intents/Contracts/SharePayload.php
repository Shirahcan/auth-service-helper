<?php

namespace AuthService\Helper\Sharing\Intents\Contracts;

interface SharePayload
{
    public static function fromArray(array $raw): self;
    public function toArray(): array;
    public static function intentSlug(): string;
    public static function intentVersion(): string;
}
