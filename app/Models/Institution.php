<?php

namespace App\Models;

use Database\Factories\InstitutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['code', 'name', 'hmac_secret'])]
#[Hidden(['hmac_secret'])]
class Institution extends Model
{
    /** @use HasFactory<InstitutionFactory> */
    use HasFactory, HasUuids;

    /**
     * Uppercase letters, digits, and hyphens, 3 to 32 characters.
     */
    public const string CODE_PATTERN = '/^[A-Z0-9-]{3,32}$/';

    /**
     * A 32-byte HMAC secret written as 64 hexadecimal characters.
     */
    public const string SECRET_PATTERN = '/^[0-9a-f]{64}$/i';

    /**
     * The code identifies the institution on every webhook, so it never changes.
     */
    protected static function booted(): void
    {
        static::updating(function (Institution $institution): void {
            if ($institution->isDirty('code')) {
                throw new LogicException('An institution code cannot be changed.');
            }
        });
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hmac_secret' => 'encrypted',
        ];
    }
}
