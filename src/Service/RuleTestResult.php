<?php

declare(strict_types=1);

namespace Mpgw\Service;

/**
 * Resultat av att kora ett extraktionsmonster mot en text - anvands
 * bade for live-preview i UI:t och for faktisk faltextraktion.
 */
final class RuleTestResult
{
    private function __construct(
        public readonly bool $matched,
        public readonly ?string $value,
        public readonly ?int $offset,
        public readonly ?int $length,
        public readonly ?string $error,
        public readonly int $durationUs,
    ) {
    }

    public static function match(string $value, int $offset, int $length, int $durationUs): self
    {
        return new self(true, $value, $offset, $length, null, $durationUs);
    }

    public static function noMatch(int $durationUs): self
    {
        return new self(false, null, null, null, null, $durationUs);
    }

    public static function error(string $error, int $durationUs): self
    {
        return new self(false, null, null, null, $error, $durationUs);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'matched' => $this->matched,
            'value' => $this->value,
            'offset' => $this->offset,
            'length' => $this->length,
            'error' => $this->error,
            'duration_us' => $this->durationUs,
        ];
    }
}
