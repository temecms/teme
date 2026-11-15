import { useId } from 'react';
import type { KeyboardEvent, MouseEvent } from 'react';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Each arrow's step across and down, in percent. */
const STEPS: Record<string, [number, number]> = {
    ArrowLeft: [-1, 0],
    ArrowRight: [1, 0],
    ArrowUp: [0, -1],
    ArrowDown: [0, 1],
};

const within = (value: number) => Math.min(100, Math.max(0, value));

/** Where the thirds' lines fall, across and down. */
const THIRDS = ['33.333%', '66.667%'];

/**
 * The picture with its focal point (docs/architecture/design-system.md,
 * section 4.37, the marker and keys): click to place the point, or focus
 * the picture and move it with the arrow keys, 1% a step or 10% with
 * Shift. It saves when focus leaves the picture.
 */
export function FocalPoint({
    src,
    name,
    value,
    disabled = false,
    onChange,
    onLeave,
}: {
    src: string;
    /** The file's name, for the picture's label. */
    name: string;
    /** Across and down, in percent. */
    value: [number, number];
    disabled?: boolean;
    onChange: (value: [number, number]) => void;
    /** Focus left the picture: whatever moved is saved. */
    onLeave: () => void;
}) {
    const hint = useId();
    const [across, down] = value;

    const place = (event: MouseEvent<HTMLDivElement>) => {
        if (disabled) {
            return;
        }

        const box = event.currentTarget.getBoundingClientRect();
        onChange([
            within(Math.round(((event.clientX - box.left) / box.width) * 100)),
            within(Math.round(((event.clientY - box.top) / box.height) * 100)),
        ]);
    };

    const move = (event: KeyboardEvent<HTMLDivElement>) => {
        const step = STEPS[event.key];

        if (disabled || step === undefined) {
            return;
        }

        event.preventDefault();
        const size = event.shiftKey ? 10 : 1;
        onChange([
            within(across + step[0] * size),
            within(down + step[1] * size),
        ]);
    };

    return (
        <div className="grid gap-2">
            <div
                role="application"
                tabIndex={disabled ? undefined : 0}
                aria-label={__(
                    'Focal point of :name. Click, or use the arrow keys, to move it.',
                    { name },
                )}
                aria-describedby={hint}
                aria-disabled={disabled || undefined}
                data-test="media-focal-point"
                className={cn(
                    'focus-visible:outline-ring relative rounded-md outline-none select-none focus-visible:outline-2 focus-visible:outline-offset-2',
                    !disabled && 'cursor-crosshair',
                )}
                onClick={place}
                onKeyDown={move}
                onBlur={onLeave}
            >
                <img
                    src={src}
                    alt=""
                    draggable={false}
                    className="outline-img-outline block w-full rounded-md outline-1 -outline-offset-1"
                />
                {THIRDS.map((at) => (
                    <span
                        key={`across-${at}`}
                        aria-hidden="true"
                        className="border-on-photo/35 absolute inset-y-0 border-s"
                        style={{ left: at }}
                    />
                ))}
                {THIRDS.map((at) => (
                    <span
                        key={`down-${at}`}
                        aria-hidden="true"
                        className="border-on-photo/35 absolute inset-x-0 border-t"
                        style={{ top: at }}
                    />
                ))}
                <span
                    aria-hidden="true"
                    className="border-on-photo outline-photo-scrim absolute flex size-9 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 outline-2"
                    style={{ left: `${across}%`, top: `${down}%` }}
                >
                    <span className="bg-on-photo size-1.5 rounded-full" />
                </span>
                <span className="sr-only" aria-live="polite">
                    {__(':across% across, :down% down', { across, down })}
                </span>
            </div>
            <p id={hint} className="text-ink-3 text-small">
                {__(
                    'Click to set the focal point, or focus the picture and use the arrow keys (Shift for bigger steps).',
                )}
            </p>
        </div>
    );
}
