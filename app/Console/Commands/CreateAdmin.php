<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Mints the first administrator, and promotes later ones.
 *
 * This is the only supported way an account becomes an administrator, in production as much as
 * locally: `role` is not mass-assignable, so no HTTP request can grant it, and there is no
 * registration form to grant it at sign-up.
 *
 * Two deliberate choices:
 *
 * - **The password is always prompted for.** There is no `--password` option, because an option
 *   would put the password in the shell history, in `ps` output, and in any shell transcript. It is
 *   read with `secret()` so it is not echoed either.
 * - **The command is idempotent on the account, not on the run.** Given an email that already
 *   belongs to an administrator it changes nothing and exits `0`, because the state the operator
 *   asked for already holds. Given an email that belongs to a member it asks before promoting, and
 *   a declined prompt exits non-zero: nothing went wrong, but the account is *not* an
 *   administrator afterwards, and a provisioning script reading the exit code has to be able to
 *   tell those apart.
 */
#[Signature('app:create-admin {email? : The email address of the administrator}')]
#[Description('Create an administrator account, or promote an existing account to administrator')]
class CreateAdmin extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->resolveEmailAddress();

        $existing = User::query()->where('email', $email)->first();

        if ($existing instanceof User) {
            return $this->promote($existing);
        }

        return $this->create($email);
    }

    /**
     * Get the email address to act on, from the argument or by asking for it.
     */
    private function resolveEmailAddress(): string
    {
        $email = $this->argument('email');

        if (is_string($email) && trim($email) !== '') {
            return trim($email);
        }

        return $this->askFor('Email address');
    }

    /**
     * Promote an account that already exists, after confirming with the operator.
     *
     * The password is deliberately neither asked for nor changed here: the account holder chose
     * it, and promoting them is not a reason to take it away. The verification state is left alone
     * for the same reason — an account that has not verified its email address still has to do so
     * before `verified` lets it near `/admin`.
     */
    private function promote(User $user): int
    {
        if ($user->isAdmin()) {
            $this->components->info("[{$user->email}] is already an administrator. Nothing to do.");

            return self::SUCCESS;
        }

        $confirmed = $this->confirm("[{$user->email}] already has an account. Promote it to administrator?");

        if (! $confirmed) {
            $this->components->warn('Aborted. No changes were made.');

            return self::FAILURE;
        }

        $user->role = UserRole::Admin;
        $user->save();

        $this->components->info("[{$user->email}] was promoted to administrator.");

        return self::SUCCESS;
    }

    /**
     * Create a brand new administrator account.
     */
    private function create(string $email): int
    {
        $name = $this->askFor('Name');
        $password = $this->askForSecret('Password');
        $passwordConfirmation = $this->askForSecret('Confirm password');

        /*
         * The email address is only validated here, not before the lookup above: `emailRules()`
         * requires the address to be unique, which is exactly what an account being promoted is
         * not.
         */
        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        /*
         * Every attribute is assigned explicitly rather than mass-assigned: `role` is not fillable
         * (that is the whole point of the privilege boundary), and spelling the rest out keeps this
         * honest about which columns a console command is allowed to write.
         *
         * The address is then marked verified outright, through the `MustVerifyEmail` API rather
         * than by writing the column. Verification exists to prove the person signing up controls
         * the mailbox; whoever ran this had a shell on the server, which is a strictly stronger
         * claim than clicking a mailed link, and there is no one to send the link to yet.
         */
        $user = new User;
        $user->name = $name;
        $user->email = $email;
        $user->password = $password;
        $user->role = UserRole::Admin;
        $user->save();

        $user->markEmailAsVerified();

        $this->components->info("[{$user->email}] was created as an administrator.");

        return self::SUCCESS;
    }

    /**
     * Ask for a value, normalising a missing answer to an empty string so validation reports it.
     */
    private function askFor(string $question): string
    {
        $answer = $this->ask($question);

        return is_string($answer) ? trim($answer) : '';
    }

    /**
     * Ask for a value without echoing it back. Never trimmed — the whitespace may be the password.
     */
    private function askForSecret(string $question): string
    {
        $answer = $this->secret($question);

        return is_string($answer) ? $answer : '';
    }
}
