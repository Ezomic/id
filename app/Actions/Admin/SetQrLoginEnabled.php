<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\AccessAudit;
use App\Models\Setting;
use App\Services\QrLoginPolicy;

class SetQrLoginEnabled
{
    public function handle(bool $enabled): void
    {
        Setting::putFlag(QrLoginPolicy::SETTING, $enabled);

        AccessAudit::log($enabled ? 'qr_login_enable' : 'qr_login_disable', []);
    }
}
