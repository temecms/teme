import { useState } from 'react';
import {
    textOf,
    useFieldControlContext,
} from '@/components/editor/fields/field-control';
import type { FieldControlProps } from '@/components/editor/fields/field-control';
import { Input } from '@/components/ui/input';
import { zonedToIso } from '@/lib/zoned-time';

/** A stored moment's date and time on a clock in `zone`, or empty parts for none. */
function partsOf(value: unknown, zone: string): { date: string; time: string } {
    if (typeof value !== 'string' || value === '') {
        return { date: '', time: '' };
    }

    const moment = new Date(value);

    if (Number.isNaN(moment.getTime())) {
        return { date: '', time: '' };
    }

    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-GB', {
            timeZone: zone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        })
            .formatToParts(moment)
            .map((part) => [part.type, part.value]),
    );

    return {
        date: `${parts.year}-${parts.month}-${parts.day}`,
        time: `${parts.hour}:${parts.minute}`,
    };
}

/**
 * A date alone, stored as written (I-3321); or a date and a time read and
 * written on the site's clock, with the zone named beneath, as the schedule
 * names it. Emptying either part empties the value.
 */
export function DatetimeControl({
    field,
    value,
    onChange,
    id,
    describedBy,
    invalid,
}: FieldControlProps) {
    const { siteTimezone } = useFieldControlContext();
    const [parts, setParts] = useState(() => partsOf(value, siteTimezone));

    if (field.settings.dateOnly === true) {
        return (
            <Input
                id={id}
                type="date"
                aria-describedby={describedBy}
                aria-invalid={invalid || undefined}
                value={textOf(value)}
                onChange={(event) =>
                    onChange(
                        event.target.value === '' ? null : event.target.value,
                    )
                }
                className="w-auto"
            />
        );
    }

    const change = (next: { date: string; time: string }) => {
        setParts(next);
        onChange(
            next.date !== '' && next.time !== ''
                ? zonedToIso(next.date, next.time, siteTimezone)
                : null,
        );
    };

    // The zone's own name for itself beside its identifier, as the schedule says it.
    const zoneName =
        new Intl.DateTimeFormat([], {
            timeZone: siteTimezone,
            timeZoneName: 'long',
        })
            .formatToParts(new Date())
            .find((part) => part.type === 'timeZoneName')?.value ??
        siteTimezone;
    const described = [describedBy, `${id}-zone`].filter(Boolean).join(' ');

    return (
        <div
            id={id}
            role="group"
            aria-labelledby={`${id}-label`}
            className="grid gap-1"
        >
            <div className="flex flex-wrap gap-2">
                <Input
                    id={`${id}-date`}
                    type="date"
                    aria-label="Date"
                    aria-describedby={described}
                    aria-invalid={invalid || undefined}
                    value={parts.date}
                    onChange={(event) =>
                        change({ ...parts, date: event.target.value })
                    }
                    className="w-auto"
                />
                <Input
                    id={`${id}-time`}
                    type="time"
                    aria-label="Time"
                    aria-describedby={described}
                    aria-invalid={invalid || undefined}
                    value={parts.time}
                    onChange={(event) =>
                        change({ ...parts, time: event.target.value })
                    }
                    className="w-auto"
                />
            </div>
            <p id={`${id}-zone`} className="text-ink-2 text-xs">
                Times are in the site’s zone: {zoneName} ({siteTimezone}).
            </p>
        </div>
    );
}
