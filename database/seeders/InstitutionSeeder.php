<?php

namespace Database\Seeders;

use App\Actions\CreateInstitution;
use App\Models\Institution;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class InstitutionSeeder extends Seeder
{
    /**
     * Create the configured institutions, each with one finance staff user.
     *
     * The database is the only place secrets are read from at runtime. The
     * environment may supply a secret so it matches one delivered out of band;
     * when it is empty, a random secret is generated and printed once.
     * Institutions that already exist are left untouched.
     */
    public function run(CreateInstitution $createInstitution): void
    {
        foreach (config('institutions.seed') as $institution) {
            if (Institution::where('code', $institution['code'])->exists()) {
                continue;
            }

            $secretFromEnvironment = blank($institution['secret']) ? null : $institution['secret'];

            if ($secretFromEnvironment !== null && preg_match(Institution::SECRET_PATTERN, $secretFromEnvironment) !== 1) {
                throw new RuntimeException("The HMAC secret for [{$institution['code']}] in the environment must be 64 hexadecimal characters.");
            }

            ['secret' => $secret] = $createInstitution->handle(
                $institution['code'],
                $institution['name'],
                'finance@'.Str::lower($institution['code']).'.test',
                $secretFromEnvironment,
            );

            if ($secretFromEnvironment === null) {
                $this->command?->warn("Generated HMAC secret for [{$institution['code']}], shown once: {$secret}");
            } else {
                $this->command?->info("Created [{$institution['code']}] with the HMAC secret from the environment.");
            }
        }
    }
}
