<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string|null $value
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public static function flag(string $key, bool $default): bool
    {
        $value = static::query()->whereKey($key)->value('value');

        return is_string($value) ? $value === '1' : $default;
    }

    public static function putFlag(string $key, bool $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value ? '1' : '0']);
    }
}
