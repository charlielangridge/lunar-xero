<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Data;

use Carbon\CarbonImmutable;

final class TenantData
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $tenantName,
        public readonly ?string $tenantType = null,
        public readonly ?CarbonImmutable $createdAt = null,
        public readonly ?CarbonImmutable $updatedAt = null,
    ) {}

    /**
     * @return array{tenant_id:string,tenant_name:string,tenant_type:?string,created_at:?string,updated_at:?string}
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'tenant_name' => $this->tenantName,
            'tenant_type' => $this->tenantType,
            'created_at' => $this->createdAt?->toIso8601String(),
            'updated_at' => $this->updatedAt?->toIso8601String(),
        ];
    }
}
