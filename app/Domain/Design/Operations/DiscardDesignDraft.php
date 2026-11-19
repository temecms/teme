<?php

/**
 * Drops every unpublished change on Settings › Design. The live values are
 * untouched, and a group's row that existed only for its draft goes.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Design\Operations;

use App\Domain\Design\Operations\Concerns\ManagesDesign;
use App\Domain\Design\Operations\Input\DiscardDesignDraftInput;
use App\Domain\Support\Operations\BaseOperation;
use Teme\Contracts\Operations\OperationInput;

final class DiscardDesignDraft extends BaseOperation
{
    use ManagesDesign;

    protected function authorize(OperationInput $input): void
    {
        /** @var DiscardDesignDraftInput $input */
        $this->authorizeDesign($input);
    }

    protected function validate(OperationInput $input): void
    {
        //
    }

    /**
     * @return int how many groups had a draft
     */
    protected function perform(OperationInput $input): int
    {
        /** @var DiscardDesignDraftInput $input */
        $records = $this->lockedDrafts();

        foreach ($records as $record) {
            $this->clearDraft($record);
        }

        return $records->count();
    }
}
