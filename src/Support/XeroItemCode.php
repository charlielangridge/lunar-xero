<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

class XeroItemCode
{
    public const MaxLength = 30;

    private const HashLength = 8;

    public static function explicit(string $code): string
    {
        $normalized = static::normalize($code);

        if ($normalized === '') {
            throw new InvalidArgumentException('A Xero item code cannot be empty.');
        }

        if (Str::length($normalized) > self::MaxLength) {
            throw new InvalidArgumentException(sprintf(
                'Xero item code [%s] is %d characters long; Xero item codes must be %d characters or fewer.',
                $normalized,
                Str::length($normalized),
                self::MaxLength,
            ));
        }

        return $normalized;
    }

    public static function fallbackForSku(string $sku): ?string
    {
        $normalized = static::normalize($sku);

        if ($normalized === '') {
            return null;
        }

        if (! static::shouldGenerateForSku($sku)) {
            return $normalized;
        }

        return static::generatedForSku($sku);
    }

    public static function generatedForSku(string $sku): string
    {
        $normalized = static::normalize($sku);

        if ($normalized === '') {
            throw new InvalidArgumentException('A Xero item code cannot be generated without a SKU.');
        }

        if (! static::shouldGenerateForSku($sku)) {
            return $normalized;
        }

        $suffix = Str::upper(mb_substr(sha1($normalized), 0, self::HashLength));
        $prefixLength = self::MaxLength - self::HashLength - 1;
        $prefix = Str::of($normalized)
            ->substr(0, $prefixLength)
            ->trim('-')
            ->value();

        if ($prefix === '') {
            $prefix = 'ITEM';
        }

        return Str::of($prefix)
            ->substr(0, $prefixLength)
            ->trim('-')
            ->append("-{$suffix}")
            ->value();
    }

    public static function shouldGenerateForSku(string $sku): bool
    {
        $normalized = static::normalize($sku);

        return $normalized !== '' && Str::length($normalized) > self::MaxLength;
    }

    public static function isGeneratedForSku(mixed $code, mixed $sku): bool
    {
        if (! filled($code) || ! filled($sku) || ! static::shouldGenerateForSku((string) $sku)) {
            return false;
        }

        return static::normalize((string) $code) === static::generatedForSku((string) $sku);
    }

    public static function normalize(string $value): string
    {
        return Str::of($value)
            ->trim()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '-')
            ->trim('-')
            ->value();
    }
}
