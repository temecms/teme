<?php

declare(strict_types=1);

namespace App\Http\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The enable request of the Plugins page (I-3353): the version, capabilities,
 * hosts and hooks the consent dialog showed, which EnablePlugin compares with
 * what the plugin asks for now. Who may enable is the Operation's to say.
 */
final class EnablePluginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'max:60'],
            'capabilities' => ['present', 'array'],
            'capabilities.*' => ['string', 'max:60'],
            'hosts' => ['present', 'array'],
            'hosts.*' => ['string', 'max:253'],
            'hooks' => ['present', 'array'],
            'hooks.*' => ['string', 'max:80'],
            'settingHosts' => ['sometimes', 'array'],
            'settingHosts.*' => ['string', 'max:253'],
        ];
    }
}
