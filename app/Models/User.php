<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $pending_email
 * @property string|null $pending_email_token
 * @property Carbon|null $pending_email_expires_at
 * @property bool $is_admin
 * @property string|null $login_code_hash
 * @property Carbon|null $login_code_expires_at
 * @property int $login_code_attempts
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'is_admin'])]
#[Hidden(['login_code_hash', 'pending_email_token', 'remember_token'])]
class User extends Authenticatable implements OAuthenticatable, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable;

    /**
     * The applications this user is allowed to access.
     *
     * @return BelongsToMany<Application, $this>
     */
    public function applications(): BelongsToMany
    {
        return $this->belongsToMany(Application::class)
            ->withPivot(['pinned', 'position', 'last_launched_at'])
            ->withTimestamps();
    }

    /**
     * The links this user saved to read later.
     *
     * @return HasMany<Bookmark, $this>
     */
    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class);
    }

    /**
     * One-time codes for the case where neither the inbox nor a passkey is
     * reachable, which would otherwise be a permanent lockout.
     *
     * @return HasMany<RecoveryCode, $this>
     */
    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(RecoveryCode::class);
    }

    /**
     * Groups this user belongs to. Group grants are one source of app access.
     *
     * @return BelongsToMany<Group, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class);
    }

    /**
     * Effective app access: the union of direct grants (application_user) and
     * grants inherited from the user's groups (application_group).
     *
     * @return Collection<int, int>
     */
    public function accessibleApplicationIds(): Collection
    {
        $direct = $this->applications()->pluck('applications.id');

        $viaGroups = Application::query()
            ->whereHas('groups', fn ($query) => $query->whereIn('groups.id', $this->groups()->select('groups.id')))
            ->pluck('id');

        return $direct->merge($viaGroups)
            ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->unique()
            ->values();
    }

    public function canAccess(Application $application): bool
    {
        return $this->accessibleApplicationIds()->contains($application->id);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'pending_email_expires_at' => 'datetime',
            'login_code_expires_at' => 'datetime',
            'login_code_attempts' => 'integer',
            'is_admin' => 'boolean',
        ];
    }
}
