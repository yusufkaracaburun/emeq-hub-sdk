<?php

declare(strict_types=1);

use Emeq\HubSdk\Support\DutchVatNumber;

it('accepts a number under the eleven-test', function (): void {
    expect(DutchVatNumber::normalise('NL123456782B01'))->toBe('NL123456782B01');
});

it('accepts a number under the mod-97 scheme', function (): void {
    expect(DutchVatNumber::normalise('NL100000024B01'))->toBe('NL100000024B01');
});

it('adds the country code Dutch bookkeeping leaves off', function (): void {
    expect(DutchVatNumber::normalise('123456782B01'))->toBe('NL123456782B01');
});

it('strips the separators people type', function (): void {
    expect(DutchVatNumber::normalise('nl 1234.567.82 b01'))->toBe('NL123456782B01');
});

it('drops anything that fails both check digits, rather than sending it', function (string $raw): void {
    expect(DutchVatNumber::normalise($raw))->toBeNull();
})->with([
    'wrong check digit' => 'NL123456789B01',
    'not Dutch' => 'BE0123456789',
    'too short' => 'NL12345678B01',
    'no B block' => 'NL123456782X01',
    'prose' => 'onbekend',
    'empty' => '   ',
]);

it('treats a missing number as absent', function (): void {
    expect(DutchVatNumber::normalise(null))->toBeNull();
});
