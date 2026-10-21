<?php

namespace App\EInvoicing\Enums;

use App\EInvoicing\Contracts\EInvoicingProvider;
use App\EInvoicing\Providers\B2BrouterProvider;
use App\EInvoicing\Providers\FakeProvider;

enum EInvoicingDriver: string
{
    case B2Brouter = 'b2brouter';
    case Fake = 'fake';

    /**
     * @return class-string<EInvoicingProvider>
     */
    public function providerClass(): string
    {
        return match ($this) {
            self::B2Brouter => B2BrouterProvider::class,
            self::Fake => FakeProvider::class,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::B2Brouter => 'B2Brouter',
            self::Fake => 'Fake (local)',
        };
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function credentialRules(): array
    {
        return match ($this) {
            self::B2Brouter => [
                'api_key' => ['required', 'string', 'max:255'],
                'account_id' => ['required', 'string', 'max:255'],
                'webhook_signing_secret' => ['nullable', 'string', 'max:255'],
            ],
            self::Fake => [],
        };
    }

    /**
     * @return list<array{name: string, type: 'text'|'password', required: bool}>
     */
    public function credentialFields(): array
    {
        return match ($this) {
            self::B2Brouter => [
                ['name' => 'api_key', 'type' => 'password', 'required' => true],
                ['name' => 'account_id', 'type' => 'text', 'required' => true],
                ['name' => 'webhook_signing_secret', 'type' => 'password', 'required' => false],
            ],
            self::Fake => [],
        };
    }

    /**
     * @return list<string>
     */
    public function supportedCountries(): array
    {
        return ['IT', 'ES'];
    }

    public function isAvailable(): bool
    {
        return $this !== self::Fake || app()->environment(['local', 'testing']);
    }

    /**
     * @return list<self>
     */
    public static function availableFor(?string $isoCode): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $driver): bool => $driver->isAvailable() && in_array($isoCode, $driver->supportedCountries(), true),
        ));
    }
}
