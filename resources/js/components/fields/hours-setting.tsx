import { Plus } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { FieldDefinition } from '@/types';

const DAYS = [
    ['monday', 'Monday'],
    ['tuesday', 'Tuesday'],
    ['wednesday', 'Wednesday'],
    ['thursday', 'Thursday'],
    ['friday', 'Friday'],
    ['saturday', 'Saturday'],
    ['sunday', 'Sunday'],
] as const;

type Day = (typeof DAYS)[number][0];

type Opening = { opens: string; closes: string };

type DayHours = { closed: boolean; hours: Opening[] };

type Props = {
    field: FieldDefinition;
    name: string;
    value: unknown;
    disabled?: boolean;
};

const GRID =
    'grid grid-cols-2 items-end gap-x-3 gap-y-2 sm:grid-cols-[7rem_8rem_8rem_auto_1fr]';

function textOf(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

/** The stored week as each day's state; anything else is an empty week. */
function weekOf(value: unknown): Record<Day, DayHours> {
    const week = Object.fromEntries(
        DAYS.map(([day]) => [
            day,
            { closed: false, hours: [{ opens: '', closes: '' }] },
        ]),
    ) as Record<Day, DayHours>;

    if (!Array.isArray(value)) {
        return week;
    }

    for (const entry of value) {
        const { day, hours, closed } = (entry ?? {}) as {
            day?: unknown;
            hours?: unknown;
            closed?: unknown;
        };
        const known = DAYS.find(([key]) => key === day);

        if (!known) {
            continue;
        }

        const openings = Array.isArray(hours)
            ? hours.slice(0, 2).map((opening) => {
                  const { opens, closes } = (opening ?? {}) as Partial<
                      Record<keyof Opening, unknown>
                  >;

                  return { opens: textOf(opens), closes: textOf(closes) };
              })
            : [];

        week[known[0]] = {
            closed: closed === true,
            hours: openings.length > 0 ? openings : [{ opens: '', closes: '' }],
        };
    }

    return week;
}

/**
 * An hours setting's control (I-877): a week, a row a day, each closed or
 * with one or two openings. A day's first opening is always drawn, so the
 * field sends every day and an emptied week saves as empty.
 */
export function HoursSetting({ field, name, value, disabled }: Props) {
    const id = `field-${field.key}`;
    const [week, setWeek] = useState(() => weekOf(value));
    const addButtons = useRef<Partial<Record<Day, HTMLButtonElement | null>>>(
        {},
    );
    const focusAgain = useRef<Day | null>(null);

    const update = (day: Day, change: (hours: DayHours) => DayHours) =>
        setWeek((current) => ({ ...current, [day]: change(current[day]) }));

    const setTime = (
        day: Day,
        index: number,
        part: keyof Opening,
        time: string,
    ) =>
        update(day, (hours) => ({
            ...hours,
            hours: hours.hours.map((opening, n) =>
                n === index ? { ...opening, [part]: time } : opening,
            ),
        }));

    return (
        <div
            id={id}
            role="group"
            aria-label={field.label}
            aria-describedby={`${id}-hint ${id}-error`}
            className="grid gap-3"
        >
            <div
                aria-hidden="true"
                className={`${GRID} text-ink-2 hidden text-xs sm:grid`}
            >
                <span>Day</span>
                <span>Opens</span>
                <span>Closes</span>
            </div>
            {DAYS.map(([day, label]) => {
                const hours = week[day];
                const timesOff = disabled || hours.closed;

                return (
                    <div
                        key={day}
                        role="group"
                        aria-label={label}
                        data-test="hours-day"
                        className="grid gap-2"
                    >
                        {hours.hours.map((opening, index) => (
                            <div key={index} className={GRID}>
                                {index === 0 ? (
                                    <span className="col-span-2 self-center text-sm font-medium sm:col-span-1">
                                        {label}
                                    </span>
                                ) : (
                                    <span
                                        aria-hidden="true"
                                        className="hidden sm:block"
                                    />
                                )}
                                {(['opens', 'closes'] as const).map((part) => (
                                    <div key={part} className="grid gap-1">
                                        <span
                                            aria-hidden="true"
                                            className="text-ink-2 text-xs sm:sr-only"
                                        >
                                            {part === 'opens'
                                                ? 'Opens'
                                                : 'Closes'}
                                        </span>
                                        <Input
                                            type="time"
                                            id={`${id}-${day}-${part}-${index}`}
                                            ref={(element) => {
                                                if (
                                                    element &&
                                                    part === 'opens' &&
                                                    index === 1 &&
                                                    focusAgain.current === day
                                                ) {
                                                    focusAgain.current = null;
                                                    element.focus();
                                                }
                                            }}
                                            name={`${name}[${day}][hours][${index}][${part}]`}
                                            aria-label={`${label} ${part}${index === 1 ? ' again' : ''}`}
                                            value={opening[part]}
                                            onChange={(event) =>
                                                setTime(
                                                    day,
                                                    index,
                                                    part,
                                                    event.target.value,
                                                )
                                            }
                                            disabled={timesOff}
                                        />
                                    </div>
                                ))}
                                {index === 0 ? (
                                    <>
                                        <div className="flex min-h-6 items-center gap-2 self-center">
                                            <Checkbox
                                                id={`${id}-${day}-closed`}
                                                name={`${name}[${day}][closed]`}
                                                value="1"
                                                checked={hours.closed}
                                                onCheckedChange={(checked) =>
                                                    update(day, (current) => ({
                                                        ...current,
                                                        closed:
                                                            checked === true,
                                                    }))
                                                }
                                                disabled={disabled}
                                            />
                                            <Label
                                                htmlFor={`${id}-${day}-closed`}
                                            >
                                                Closed
                                            </Label>
                                        </div>
                                        <div className="self-center">
                                            {hours.hours.length < 2 &&
                                                !hours.closed && (
                                                    <Button
                                                        ref={(element) => {
                                                            addButtons.current[
                                                                day
                                                            ] = element;
                                                        }}
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        data-test="hours-add"
                                                        aria-label={`${label}: add a second opening`}
                                                        disabled={disabled}
                                                        onClick={() => {
                                                            focusAgain.current =
                                                                day;
                                                            update(
                                                                day,
                                                                (current) => ({
                                                                    ...current,
                                                                    hours: [
                                                                        ...current.hours,
                                                                        {
                                                                            opens: '',
                                                                            closes: '',
                                                                        },
                                                                    ],
                                                                }),
                                                            );
                                                        }}
                                                    >
                                                        <Plus />
                                                        Add a second opening
                                                    </Button>
                                                )}
                                        </div>
                                    </>
                                ) : (
                                    <div className="self-center">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            data-test="hours-remove"
                                            disabled={timesOff}
                                            onClick={() => {
                                                update(day, (current) => ({
                                                    ...current,
                                                    hours: current.hours.slice(
                                                        0,
                                                        1,
                                                    ),
                                                }));
                                                requestAnimationFrame(() =>
                                                    addButtons.current[
                                                        day
                                                    ]?.focus(),
                                                );
                                            }}
                                        >
                                            Remove
                                            <span className="sr-only">
                                                {' '}
                                                {label}’s second opening
                                            </span>
                                        </Button>
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                );
            })}
        </div>
    );
}
