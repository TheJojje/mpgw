<?php

declare(strict_types=1);

namespace Mpgw\Service;

final class RegexSuggestion
{
    public function __construct(
        public readonly string $label,
        public readonly string $pattern,
        public readonly string $description,
    ) {
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'pattern' => $this->pattern,
            'description' => $this->description,
        ];
    }
}
