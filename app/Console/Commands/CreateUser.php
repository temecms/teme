<?php

/**
 * `teme:user:create`: invites a person — the first administrator on a new site
 * included — and prints their set-password link. It acts as the operator, so
 * it works before any administrator exists and when email does not.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Users\InvitationUrl;
use App\Domain\Users\Operations\Input\InviteUserInput;
use App\Domain\Users\Operations\InviteUser;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\text;

final class CreateUser extends Command
{
    /** @var string */
    protected $signature = 'teme:user:create
        {--name= : Their name}
        {--email= : Their email address}
        {--role=administrator : administrator, editor, author or contributor}
        {--send : Also email the link}';

    /** @var string */
    protected $description = 'Invite a person — the first administrator included — and print their set-password link';

    public function handle(InviteUser $inviteUser): int
    {
        // The operator: docs/architecture/operations.md.
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $name = (string) ($this->option('name') ?? ($this->input->isInteractive() ? text('Name', required: true) : ''));
        $email = (string) ($this->option('email') ?? ($this->input->isInteractive() ? text('Email address', required: true) : ''));
        $role = (string) $this->option('role');

        try {
            $result = $inviteUser->execute(new InviteUserInput($name, $email, $role, actorId: null, deliver: (bool) $this->option('send')));
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info("Invited {$email} as {$role}.");
        $this->line('Their set-password link — it works once and expires in 7 days:');
        $this->line(InvitationUrl::for($result['token'], strtolower(trim($email))));

        return self::SUCCESS;
    }
}
