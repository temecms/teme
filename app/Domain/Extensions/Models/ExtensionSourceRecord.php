<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Models;

use App\Domain\Extensions\Sources\ExtensionSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved place packages come from: a Git address or a vendor's Composer
 * source, with its key encrypted under the app key and never sent to the
 * browser. Written only by the extension Operations.
 *
 * @property string $id
 * @property string $kind
 * @property string $label
 * @property string|null $url
 * @property string|null $username
 * @property string|null $credential
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ExtensionSourceRecord extends Model
{
    use HasUlids;

    protected $table = 'extension_sources';

    protected $guarded = [];

    protected $hidden = ['credential'];

    /** The source as a source kind receives it, its key decrypted. */
    public function toSource(): ExtensionSource
    {
        return new ExtensionSource($this->id, $this->kind, $this->label, $this->url, $this->username, $this->credential);
    }

    protected function casts(): array
    {
        return [
            'credential' => 'encrypted',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
