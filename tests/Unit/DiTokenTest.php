<?php

use App\Services\Garmin\DiToken;

it('round-trips through toArray/fromArray', function () {
    $token = new DiToken('access-xyz', 'refresh-xyz', 'CLIENT_ID', 1893456000);

    expect(DiToken::fromArray($token->toArray()))->toEqual($token);
});

it('is not usable without both tokens', function () {
    expect((new DiToken('a', 'r', 'c'))->isUsable())->toBeTrue();
    expect((new DiToken('a', '', 'c'))->isUsable())->toBeFalse();
    expect((new DiToken('', 'r', 'c'))->isUsable())->toBeFalse();
});

it('flags tokens that expire within the refresh buffer', function () {
    expect((new DiToken('a', 'r', 'c', time() + 60))->isExpiringWithin())->toBeTrue();
    expect((new DiToken('a', 'r', 'c', time() + 3600))->isExpiringWithin())->toBeFalse();
    // Unknown expiry (0) is never treated as expiring — refresh happens via 401-retry.
    expect((new DiToken('a', 'r', 'c', 0))->isExpiringWithin())->toBeFalse();
});

it('decodes the exp and client_id claims of a Garmin JWT', function () {
    $payload = rtrim(strtr(base64_encode(json_encode([
        'exp' => 1893456000,
        'client_id' => 'GARMIN_CONNECT_MOBILE_ANDROID_DI_2025Q2',
    ])), '+/', '-_'), '=');
    $jwt = 'eyJhbGciOiJIUzI1NiJ9.'.$payload.'.signature';

    expect(DiToken::expiresAtFromJwt($jwt))->toBe(1893456000)
        ->and(DiToken::clientIdFromJwt($jwt))->toBe('GARMIN_CONNECT_MOBILE_ANDROID_DI_2025Q2');
});

it('returns zero expiry for malformed JWTs', function () {
    expect(DiToken::expiresAtFromJwt('not-a-jwt'))->toBe(0)
        ->and(DiToken::expiresAtFromJwt('a.'.base64_encode('{"nope":1}').'.c'))->toBe(0)
        ->and(DiToken::clientIdFromJwt('not-a-jwt'))->toBe('');
});
