<?php

declare(strict_types=1);

use App\Services\PublicAddressResolver;

it('refuses addresses that are not public', function (string $host) {
    expect((new PublicAddressResolver)->resolve($host))->toBeNull();
})->with([
    '127.0.0.1', '10.0.0.1', '172.16.5.4', '192.168.1.1', '169.254.169.254', '0.0.0.0',
    '100.64.0.1', '224.0.0.1', '[::1]', '::ffff:127.0.0.1', 'fd00::1', 'fe80::1', 'localhost',
]);

it('returns a public address unchanged', function (string $host, string $expected) {
    expect((new PublicAddressResolver)->resolve($host))->toBe($expected);
})->with([
    ['93.184.216.34', '93.184.216.34'],
    ['[2606:2800:220:1::1]', '2606:2800:220:1::1'],
]);
