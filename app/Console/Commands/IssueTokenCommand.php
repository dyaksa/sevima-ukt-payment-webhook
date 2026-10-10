<?php

namespace App\Console\Commands;

use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('token:issue
    {email : Email of an existing institution staff user}
    {--ability=* : Token ability (invoices:read, invoices:write); defaults to both}')]
#[Description('Issue an API token for an institution staff user, shown only once')]
class IssueTokenCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::firstWhere('email', $this->argument('email'));

        if ($user === null) {
            $this->components->error("No user has the email [{$this->argument('email')}].");

            return self::FAILURE;
        }

        $knownAbilities = array_column(TokenAbility::cases(), 'value');
        $abilities = $this->option('ability') ?: $knownAbilities;
        $unknownAbilities = array_diff($abilities, $knownAbilities);

        if ($unknownAbilities !== []) {
            $this->components->error('Unknown ability: '.implode(', ', $unknownAbilities).'.');

            return self::FAILURE;
        }

        $token = $user->createToken('cli', array_values($abilities));

        $this->components->info('Token issued with: '.implode(', ', $abilities).'.');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
