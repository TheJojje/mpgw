<?php

declare(strict_types=1);

namespace Mpgw\Service;

final class RuleEngineResult
{
    /**
     * @param array<int,array{field_name:string,rule_id:int,matched:bool,value:?string,offset:?int,length:?int,error:?string}> $fields
     * @param array<int,string> $missingRequiredFields
     */
    public function __construct(
        public readonly string $status,
        public readonly float $matchRate,
        public readonly array $fields,
        public readonly array $missingRequiredFields,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'match_rate' => $this->matchRate,
            'fields' => $this->fields,
            'missing_required_fields' => $this->missingRequiredFields,
        ];
    }
}
