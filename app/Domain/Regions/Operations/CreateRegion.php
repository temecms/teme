<?php

/**
 * Makes a theme's region's content (study B §3e): one row of the region
 * type per theme and region, slug "{theme}--{region}", titled by the
 * region's label, a draft with an empty document. It is the only way a
 * region's row is made; CreateDraft refuses the type.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Regions\Operations;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Regions\Operations\Input\CreateRegionInput;
use App\Domain\Regions\RegionDeclarations;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Themes\ThemeRegistry;
use App\Domain\Types\CoreTypes;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class CreateRegion extends BaseOperation
{
    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly RegionDeclarations $declarations,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var CreateRegionInput $input */
        $actor = User::query()->find($input->actorId);

        // The region type's create ability, regions.manage (CoreTypes).
        if ($actor === null || ! Gate::forUser($actor)->allows('create', [Content::class, CoreTypes::REGION])) {
            throw new AuthorizationException('Not permitted to manage regions.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var CreateRegionInput $input */
        $theme = $this->themes->find($input->themeCode);
        $declared = $theme !== null && $theme->usable() ? $this->declarations->for($theme) : [];

        if (! array_key_exists($input->region, $declared)) {
            throw ValidationException::withMessages(['region' => __('The active theme has no region called “:region”. Reload the page and try again.', ['region' => $input->region])]);
        }

        // No row to lock yet: if two creates race past this, the live-only
        // unique index on (type, slug) refuses the second.
        if (Content::query()->where('type', CoreTypes::REGION)->where('slug', RegionDeclarations::slug($input->themeCode, $input->region))->exists()) {
            throw ValidationException::withMessages(['region' => __('This region already has its content.')]);
        }
    }

    protected function perform(OperationInput $input): Content
    {
        /** @var CreateRegionInput $input */
        $theme = $this->themes->find($input->themeCode);
        $label = $theme === null ? $input->region : ($this->declarations->for($theme)[$input->region]['label'] ?? $input->region);

        return CreateDraft::start(
            CoreTypes::REGION,
            RegionDeclarations::slug($input->themeCode, $input->region),
            $label,
            ['type' => 'doc', 'content' => []],
            [],
            [],
            $input->actorId,
        );
    }
}
