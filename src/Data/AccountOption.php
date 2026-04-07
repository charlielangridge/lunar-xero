<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Data;

final class AccountOption
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $type = null,
        public readonly ?string $class = null,
        public readonly bool $enablePaymentsToAccount = false,
    ) {}

    public function label(): string
    {
        return sprintf('%s - %s', $this->code, $this->name);
    }

    /**
     * @return array{code:string,name:string,type:?string,class:?string,enable_payments_to_account:bool}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'class' => $this->class,
            'enable_payments_to_account' => $this->enablePaymentsToAccount,
        ];
    }
}
