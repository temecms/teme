import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { SelectField } from '@/components/ui/select-field';
import { __ } from '@/lib/i18n';
import { formatInZone } from '@/lib/zoned-time';
import type { EditorReview, EditorSchedule } from '@/types';
import { ScheduleControl } from '../schedule-control';

/** When the next publishing decision takes effect. */
export type WhenChoice = {
    /** At a set time, rather than immediately. */
    later: boolean;
    /** The time chosen, a date-and-time value on the site's clock: "2026-10-15T09:00". */
    at: string;
    /** Why that time can't be used, said under the field. */
    error: string | null;
};

/** Why a held schedule cannot run, in words. */
function heldWords(): Record<
    NonNullable<EditorSchedule['heldReason']>,
    string
> {
    return {
        scheduler_removed: __(
            'the account of the person who scheduled it was removed',
        ),
        scheduler_cannot_publish: __(
            'the person who scheduled it may no longer publish',
        ),
    };
}

/**
 * The Publish panel's Publish row (Update on a live item), and its Date and
 * time row while a time is being set (I-1316): Immediately or at a set
 * time; or what waits, with Reschedule and Cancel; or who decides. The
 * action bar's one button follows the choice made here.
 */
export function PublishWhen({
    live,
    canPublish,
    review,
    schedule,
    siteTimezone,
    when,
    onWhenChange,
    onCancelSchedule,
    busy,
}: {
    live: boolean;
    /** Whether this person may publish the item. */
    canPublish: boolean;
    review: EditorReview | null;
    schedule: EditorSchedule | null;
    siteTimezone: string;
    when: WhenChoice;
    onWhenChange: (next: WhenChoice) => void;
    onCancelSchedule: () => void;
    busy: boolean;
}) {
    const label = live ? __('Update') : __('Publish');
    const pending = review?.state === 'pending';
    const zoned = (iso: string) =>
        `${formatInZone(iso, siteTimezone)} (${siteTimezone})`;
    const choose = (next: Partial<WhenChoice>) =>
        onWhenChange({ ...when, error: null, ...next });
    // Only whoever may publish it, with no review waiting, moves a schedule.
    const decides = canPublish && !pending;
    let row: ReactNode;

    // A schedule is said first, to everyone: a contributor whose post an
    // editor scheduled reads when it publishes, not "After review".
    if (schedule !== null) {
        const failed = schedule.failedReason !== null;
        const held = schedule.heldReason !== null;

        row = (
            <div className="grid gap-2">
                <p
                    className={
                        failed
                            ? 'text-danger-ink'
                            : held
                              ? 'text-warn-ink'
                              : undefined
                    }
                >
                    {failed
                        ? __(
                              'The publication scheduled for :time failed: :reason',
                              {
                                  time: zoned(schedule.runAt),
                                  reason: schedule.failedReason ?? '',
                              },
                          )
                        : schedule.heldReason !== null
                          ? __(
                                'Held: :reason. It publishes nothing until someone who may publish reschedules it.',
                                { reason: heldWords()[schedule.heldReason] },
                            )
                          : __('Scheduled for :time', {
                                time: zoned(schedule.runAt),
                            })}
                </p>
                {decides && (
                    <div className="flex flex-wrap gap-2">
                        {when.later ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => choose({ later: false })}
                            >
                                {__('Not now')}
                            </Button>
                        ) : (
                            <>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={busy}
                                    onClick={() => choose({ later: true })}
                                >
                                    {__('Reschedule…')}
                                </Button>
                                {!failed && !held && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        disabled={busy}
                                        onClick={onCancelSchedule}
                                    >
                                        {__('Cancel')}
                                        <span className="sr-only">
                                            {' '}
                                            {live
                                                ? __('the scheduled update')
                                                : __(
                                                      'the scheduled publication',
                                                  )}
                                        </span>
                                    </Button>
                                )}
                            </>
                        )}
                    </div>
                )}
            </div>
        );
    } else if (!decides) {
        row = (
            <span className="text-ink-2">
                {pending && canPublish
                    ? __('When you approve it')
                    : __('After review')}
            </span>
        );
    } else {
        row = (
            <SelectField
                id="editor-when"
                aria-label={label}
                options={[
                    { value: 'now', label: 'Immediately' },
                    { value: 'later', label: 'At a set time…' },
                ]}
                value={when.later ? 'later' : 'now'}
                onValueChange={(next) => choose({ later: next === 'later' })}
                className="h-[34px] w-full"
            />
        );
    }

    return (
        <>
            <dt className="text-ink-2">{label}</dt>
            <dd className="min-w-0" data-test="publish-when">
                {row}
            </dd>
            {when.later && decides && (
                <>
                    <dt className="text-ink-2">
                        <label htmlFor="editor-when-at">
                            {__('Date and time')}
                        </label>
                    </dt>
                    <dd className="min-w-0">
                        <ScheduleControl
                            id="editor-when-at"
                            siteTimezone={siteTimezone}
                            value={when.at}
                            onChange={(at) => choose({ at })}
                            error={when.error}
                        />
                    </dd>
                </>
            )}
        </>
    );
}
