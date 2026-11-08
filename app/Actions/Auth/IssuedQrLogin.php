<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\QrLogin;

final readonly class IssuedQrLogin
{
    public function __construct(
        public QrLogin $qrLogin,
        public string $token,
        public string $shortCode,
    ) {}
}
