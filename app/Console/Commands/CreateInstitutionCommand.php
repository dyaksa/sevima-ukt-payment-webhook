<?php

namespace App\Console\Commands;

use App\Actions\CreateInstitution;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('institution:create
    {code : Uppercase code that identifies the institution on webhooks, e.g. UNIV-D}
    {name : Display name of the institution}
    {--staff-email= : Also create the first finance staff user with this email}')]
#[Description('Create an institution with a random HMAC secret that is shown only once')]
class CreateInstitutionCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CreateInstitution $createInstitution): int
    {
        try {
            ['institution' => $institution, 'secret' => $secret] = $createInstitution->handle(
                $this->argument('code'),
                $this->argument('name'),
                $this->option('staff-email'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Institution [{$institution->code}] created.");
        $this->components->warn('Store the HMAC secret now; it is not shown again.');
        $this->line($secret);

        return self::SUCCESS;
    }
}
