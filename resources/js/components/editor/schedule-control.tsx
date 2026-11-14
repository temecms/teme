import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { addDays, nowIn, weekday, zonedToIso } from '@/lib/zoned-time';

/** The field's own sentence. */
export const SCHEDULE_WORDS = {
    past: 'Choose a time in the future.',
};

/** Where the field opens: tomorrow at 09:00 on the site's clock. */
export function defaultAt(siteTimezone: string): string {
    return `${addDays(nowIn(siteTimezone).date, 1)}T09:00`;
}

/**
 * A date-and-time value on the site's clock ("2026-10-15T09:00") as ISO
 * 8601 with that zone's offset at that moment, or null when it is empty or
 * not in the future: nothing is ever scheduled in the past.
 */
export function futureIso(at: string, siteTimezone: string): string | null {
    const [date, time] = at.split('T');

    if (!date || !time || time.length < 5) {
        return null;
    }

    const iso = zonedToIso(date, time.slice(0, 5), siteTimezone);

    return new Date(iso).getTime() > Date.now() ? iso : null;
}

/**
 * When to publish (I-1316): three quick choices and one date-and-time field
 * on the site's clock, with the zone named beneath, never an unlabelled
 * time, because "18:00" in the wrong zone is how an embargo gets broken.
 * The label is its row's; `error` is said under it.
 */
export function ScheduleControl({
    id,
    siteTimezone,
    value,
    onChange,
    error,
}: {
    id: string;
    siteTimezone: string;
    /** The time chosen, as a date-and-time value on the site's clock. */
    value: string;
    onChange: (value: string) => void;
    error: string | null;
}) {
    const today = nowIn(siteTimezone);
    // This evening, or tomorrow's when it is already past six.
    const evening = today.time < '18:00' ? today.date : addDays(today.date, 1);
    const monday = addDays(today.date, (8 - weekday(today.date)) % 7 || 7);
    const presets = [
        { label: 'This evening', at: `${evening}T18:00` },
        { label: 'Tomorrow morning', at: `${addDays(today.date, 1)}T09:00` },
        { label: 'Next Monday', at: `${monday}T09:00` },
    ];

    // The zone's own name for itself, "West Africa Standard Time", beside
    // its identifier, so nobody has to know what Africa/Lagos means.
    const zoneName =
        new Intl.DateTimeFormat([], {
            timeZone: siteTimezone,
            timeZoneName: 'long',
        })
            .formatToParts(new Date())
            .find((part) => part.type === 'timeZoneName')?.value ??
        siteTimezone;

    return (
        <div className="grid gap-2">
            <div
                role="group"
                aria-label="Quick choices"
                className="flex flex-wrap gap-1.5"
            >
                {presets.map((preset) => (
                    <Button
                        key={preset.label}
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => onChange(preset.at)}
                    >
                        {preset.label}
                    </Button>
                ))}
            </div>
            <Input
                id={id}
                type="datetime-local"
                value={value}
                min={`${today.date}T00:00`}
                aria-invalid={error !== null || undefined}
                aria-describedby={
                    error !== null ? `${id}-zone ${id}-error` : `${id}-zone`
                }
                onChange={(event) => onChange(event.target.value)}
            />
            <p id={`${id}-zone`} className="text-muted-foreground text-xs">
                Times are in the site’s zone: {zoneName} ({siteTimezone}).
            </p>
            {error !== null && (
                <p
                    id={`${id}-error`}
                    role="alert"
                    className="text-destructive text-xs"
                >
                    {error}
                </p>
            )}
        </div>
    );
}
