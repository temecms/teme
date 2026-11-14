<?php

declare(strict_types=1);

namespace App\Domain\Redirects;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The checks a redirect passes against every other before it is saved
 * (I-297, I-859): its source is no other's, and no reader would go round
 * in a loop or take two steps through it, counting redirects switched off
 * too, so switching one on never makes a chain. Since every saved redirect
 * passed them, no chain is ever longer than one step, and a loop is either
 * a redirect to itself or two pointing at each other. Placeholders are
 * checked with sample values, both ways between two patterns. Run inside
 * the lock every redirect change takes first.
 */
final readonly class RedirectRules
{
    /**
     * Takes the lock every redirect change holds until its transaction ends,
     * so two saves at once can't together make a chain.
     */
    public static function lock(): void
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext('teme.redirects'))");
    }

    /**
     * Refuses the redirect proposed, the one being edited aside, when its
     * source is taken or it would make a loop or a chain.
     *
     * @throws RedirectRefused
     */
    public function check(RedirectProposal $proposal, ?string $except): void
    {
        $source = $proposal->source;
        $target = $proposal->target;

        if ($this->others($except)->where('source', $source->path)->exists()) {
            throw new RedirectRefused('source', __('A redirect from :source already exists. Edit that one instead.', ['source' => $source->shown()]));
        }

        // Only a path on this site leads anywhere a redirect here answers.
        if ($target !== null && $target->kind === RedirectTarget::ADDRESS) {
            $lands = (string) $target->path;

            if ($source->matches(RedirectSource::sample($lands)) !== null) {
                throw new RedirectRefused('to', __('That sends readers from :source back to itself.', ['source' => $source->shown()]));
            }

            $next = $this->leadingOn($lands, $except);

            if ($next !== null) {
                throw $this->onward($next, $source, $target);
            }
        }

        $into = $this->leadingInto($source, $except);

        if ($into !== null) {
            throw new RedirectRefused('source', __(':other already sends readers to :to, so they’d take two steps. Change that redirect first, or choose another address.', ['other' => '/'.$into->source, 'to' => $into->target]));
        }
    }

    /**
     * Every redirect but the one being edited.
     */
    private function others(?string $except): Builder
    {
        return DB::table('redirects')->when($except !== null, fn (Builder $query): Builder => $query->where('id', '<>', $except));
    }

    /**
     * The redirect whose source a reader sent to this path, in the source's
     * form and perhaps with placeholders, would meet next, or null: an exact
     * one first, by index, or by PostgreSQL's regex match when the path has
     * placeholders; then the few patterns, oldest first.
     */
    private function leadingOn(string $lands, ?string $except): ?object
    {
        $concrete = ! str_contains($lands, '[');
        $exact = $this->others($except)->where('kind', 'exact');

        $found = $concrete
            ? $exact->where('source', $lands)->first()
            : $exact->whereRaw('source ~ ?', [RedirectSource::compile($lands)])->first();

        if ($found !== null) {
            return $found;
        }

        $sample = RedirectSource::sample($lands);
        $mine = $concrete ? null : RedirectSource::compile($lands);

        foreach ($this->others($except)->where('kind', 'pattern')->orderBy('created_at')->orderBy('id')->get() as $pattern) {
            if (RedirectSource::captures((string) $pattern->pattern, [], $sample) !== null
                || ($mine !== null && RedirectSource::captures($mine, [], RedirectSource::sample((string) $pattern->source)) !== null)) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * The redirect that already sends readers to this source, or null.
     */
    private function leadingInto(RedirectSource $source, ?string $except): ?object
    {
        $found = $source->isPattern
            ? $this->others($except)->where('kind', 'exact')->whereNotNull('target_path')->whereRaw('target_path ~ ?', [(string) $source->regex])->first()
            : $this->others($except)->where('target_path', $source->path)->first();

        if ($found !== null) {
            return $found;
        }

        $sample = RedirectSource::sample($source->path);

        foreach ($this->others($except)->where('kind', 'pattern')->whereNotNull('target_path')->orderBy('created_at')->orderBy('id')->get() as $pattern) {
            $leads = (string) $pattern->target_path;

            if ($source->matches(RedirectSource::sample($leads)) !== null
                || RedirectSource::captures(RedirectSource::compile($leads), [], $sample) !== null) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * Why a redirect to where another starts can't be saved: that one sends
     * readers nowhere, back here, or on to somewhere else.
     */
    private function onward(object $next, RedirectSource $source, RedirectTarget $target): RedirectRefused
    {
        $status = RedirectStatus::from((int) $next->status);
        $other = '/'.$next->source;

        if ($status->sendsNowhere()) {
            return new RedirectRefused('to', __(':other answers :status (:name), so readers would be sent to an address that isn’t there.', ['other' => $other, 'status' => $status->value, 'name' => mb_strtolower($status->label())]));
        }

        if ($next->target_kind === RedirectTarget::ADDRESS && $source->matches(RedirectSource::sample((string) $next->target_path)) !== null) {
            return new RedirectRefused('to', __('That makes a loop: :source goes to :to, which goes back to :source.', ['source' => $source->shown(), 'to' => $target->target]));
        }

        return new RedirectRefused('to', __(':other already redirects to :where. Send readers there directly, so they take one step, not two.', ['other' => $other, 'where' => self::where($next)]));
    }

    /**
     * Where a redirect sends readers, in words: an address, an item's title
     * in quotation marks, or a file by its name.
     */
    private static function where(object $redirect): string
    {
        return match ($redirect->target_kind) {
            RedirectTarget::CONTENT => '“'.(string) (DB::table('contents')->where('id', $redirect->target)->value('title') ?? '').'”',
            RedirectTarget::MEDIA => __('the file :name', ['name' => (string) (DB::table('media')->where('id', $redirect->target)->value('name') ?? '')]),
            default => (string) $redirect->target,
        };
    }
}
