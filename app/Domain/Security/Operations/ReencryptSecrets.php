<?php

declare(strict_types=1);

namespace App\Domain\Security\Operations;

use App\Domain\Security\EncryptedColumn;
use App\Domain\Security\EncryptedForm;
use App\Domain\Security\EncryptedValues;
use App\Domain\Security\Operations\Input\ReencryptSecretsInput;
use App\Domain\Security\ReencryptedColumn;
use App\Domain\Security\ReencryptedSecrets;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;
use Teme\Contracts\Operations\OperationInput;

/**
 * Writes every value Teme keeps encrypted under an old application key
 * again under this server's (I-359), the bytes inside copied as they
 * are, so each keeps its own form. A value already under this server's key
 * is left alone, and one no key opens is reported by its row, never erased.
 * The operator only; one transaction, so a failure changes nothing.
 */
final class ReencryptSecrets extends BaseOperation
{
    public function __construct(
        private readonly EncryptedValues $registry,
        private readonly SecurityLog $log,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var ReencryptSecretsInput $input */
        // Someone with the server's shell already holds APP_KEY
        // (docs/architecture/operations.md, The operator); nobody else may.
        if ($input->actorId() === null && Operator::isPresent()) {
            return;
        }

        throw new AuthorizationException('Only the operator re-encrypts secrets.');
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ReencryptSecretsInput $input */
        Validator::make(['chunk' => $input->chunk], ['chunk' => ['required', 'integer', 'min:1', 'max:10000']])->validate();

        $problem = $input->oldKeys->problem((string) config('app.cipher'));

        if ($problem !== null) {
            throw ValidationException::withMessages(['old_key' => $problem]);
        }
    }

    protected function perform(OperationInput $input): ReencryptedSecrets
    {
        /** @var ReencryptSecretsInput $input */
        $server = app(Encrypter::class);
        $cipher = (string) config('app.cipher');

        // APP_KEY alone: a value under one of APP_PREVIOUS_KEYS still moves.
        $current = new Encrypter($server->getKey(), $cipher);
        $old = $input->oldKeys->encrypter($cipher, $server->getKey(), $server->getPreviousKeys());

        $columns = [];

        foreach ($this->registry->all() as $column) {
            $columns[] = $this->column($column, $current, $old, $input->chunk);
        }

        $result = new ReencryptedSecrets($columns);

        $this->log->record(SecurityEventType::SecretsReencrypted, context: [
            'rewritten' => $result->rewritten(),
            'current' => $result->current(),
            'unopened' => $result->unopenedCount(),
        ]);

        return $result;
    }

    private function column(EncryptedColumn $column, #[SensitiveParameter] Encrypter $current, #[SensitiveParameter] ?Encrypter $old, int $chunk): ReencryptedColumn
    {
        $rewritten = 0;
        $alreadyCurrent = 0;
        $unopened = [];
        $after = null;

        do {
            // A page at a time in key order: memory holds one page, and a row
            // written here is never read again by a later one.
            $rows = DB::table($column->table)
                ->select([$column->key, $column->column])
                ->whereNotNull($column->column)
                ->when($after !== null, fn (Builder $query): Builder => $query->where($column->key, '>', $after))
                ->orderBy($column->key)
                ->limit($chunk)
                ->get();

            foreach ($rows as $row) {
                $id = $row->{$column->key};
                $stored = $row->{$column->column};
                $after = $id;

                if (! is_string($stored) || $stored === '') {
                    continue;
                }

                // Compare and set, up to three times: a value someone wrote
                // meanwhile is read again and judged afresh, never assumed.
                for ($attempt = 1; ; $attempt++) {
                    $again = $this->rewrap($column, $stored, $current, $old);

                    if ($again === null) {
                        $unopened[] = (string) $id;

                        break;
                    }

                    if ($again === $stored) {
                        $alreadyCurrent++;

                        break;
                    }

                    $written = DB::table($column->table)
                        ->where($column->key, $id)
                        ->where($column->column, $stored)
                        ->update([$column->column => $again]);

                    if ($written === 1) {
                        $rewritten++;

                        break;
                    }

                    $stored = DB::table($column->table)->where($column->key, $id)->value($column->column);

                    // Emptied meanwhile: nothing is left to carry.
                    if (! is_string($stored) || $stored === '') {
                        break;
                    }

                    // Still changing under the run: left as it is, and named.
                    if ($attempt === 3) {
                        $unopened[] = (string) $id;

                        break;
                    }
                }
            }
        } while ($rows->count() === $chunk);

        return new ReencryptedColumn($column, $rewritten, $alreadyCurrent, $unopened);
    }

    /**
     * The value under this server's key: the same string when it already
     * is, a new one when an old key opens it to bytes in the column's form,
     * null when no key does or the bytes aren't in that form. The bytes are
     * copied, never unserialized here (rule 4).
     */
    private function rewrap(EncryptedColumn $column, #[SensitiveParameter] string $stored, #[SensitiveParameter] Encrypter $current, #[SensitiveParameter] ?Encrypter $old): ?string
    {
        try {
            $current->decrypt($stored, false);

            return $stored;
        } catch (DecryptException) {
            // Not under this server's key; an old one may open it.
        }

        if ($old === null) {
            return null;
        }

        try {
            $inside = (string) $old->decrypt($stored, false);
        } catch (DecryptException) {
            return null;
        }

        // Whoever held the old key could have planted any bytes: only a
        // value in the column's own form is signed under the new key, since
        // Fortify unserializes its two with no allow-list.
        if (! $this->inForm($column->form, $inside)) {
            return null;
        }

        $again = $current->encrypt($inside, false);

        // Proved before it is written: the new value opens with this
        // server's key alone, to the same bytes.
        if (! hash_equals($inside, (string) $current->decrypt($again, false))) {
            throw new LogicException('A re-encrypted value didn’t open to what it held.');
        }

        return $again;
    }

    /**
     * Whether opened bytes are what the column's writer writes: a PHP
     * serialized string and nothing else (never an object), JSON, or any
     * string.
     */
    private function inForm(EncryptedForm $form, #[SensitiveParameter] string $inside): bool
    {
        return match ($form) {
            EncryptedForm::Serialized => preg_match('/\As:(\d+):"(.*)";\z/s', $inside, $match) === 1 && strlen($match[2]) === (int) $match[1],
            EncryptedForm::Json => json_validate($inside),
            EncryptedForm::String => true,
        };
    }
}
