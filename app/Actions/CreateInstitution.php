<?php

namespace App\Actions;

use App\Models\Institution;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateInstitution
{
    /**
     * Create an institution with its HMAC secret and, optionally, its first
     * finance staff user. A random secret is generated unless one is given.
     *
     * @return array{institution: Institution, secret: string}
     *
     * @throws InvalidArgumentException when the code is malformed or already taken
     */
    public function handle(string $code, string $name, ?string $staffEmail = null, ?string $secret = null): array
    {
        if (preg_match(Institution::CODE_PATTERN, $code) !== 1) {
            throw new InvalidArgumentException("The institution code [{$code}] must be 3 to 32 uppercase letters, digits, or hyphens.");
        }

        $secret ??= bin2hex(random_bytes(32));

        try {
            $institution = DB::transaction(function () use ($code, $name, $staffEmail, $secret): Institution {
                $institution = Institution::create([
                    'code' => $code,
                    'name' => $name,
                    'hmac_secret' => $secret,
                ]);

                if ($staffEmail !== null) {
                    $institution->users()->create([
                        'name' => "{$name} finance staff",
                        'email' => $staffEmail,
                        'password' => Str::password(32),
                    ]);
                }

                return $institution;
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException("The institution code [{$code}] or the staff email is already taken.");
        }

        return ['institution' => $institution, 'secret' => $secret];
    }
}
