<?php

declare(strict_types=1);

namespace App\Domain\SiteCode;

/**
 * Whether the page this request draws leaves Settings › Site code out. The
 * signed-in preview does, so no third-party script runs on the admin's
 * address (I-1324). Bound scoped: it holds one request's answer.
 */
final class SiteCodeWithheld
{
    private bool $withheld = false;

    public function withhold(): void
    {
        $this->withheld = true;
    }

    /**
     * Whether this request's page draws no site code.
     */
    public function isWithheld(): bool
    {
        return $this->withheld;
    }
}
