// WCAG 2.x contrast for the accent's live readout on Settings › Design: the
// same functions as app/Domain/Design/Contrast.php, with the same messages,
// word for word, and the same sums in the same order, so darkVariant() gives
// the same answer in both (I-457). The two text colours come from the server
// (Contrast::ON_COLORS), so no colour is written here. Imports nothing, so
// Node can run it as it is for ContrastTest.

const MINIMUM = 4.5;

/** The steps darkVariant() raises OKLab lightness by. */
const LIGHTNESS_STEP = 0.01;

/** The halvings that find the most chroma a lightness keeps inside sRGB. */
const CHROMA_SEARCHES = 20;

type Triple = [number, number, number];

/** A colour's six hex digits, lower case, or null when it isn't a colour. */
function digits(hex: string): string | null {
    const match = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(hex);

    if (match === null) {
        return null;
    }

    const found =
        match[1].length === 3
            ? match[1]
                  .split('')
                  .map((digit) => digit + digit)
                  .join('')
            : match[1];

    return found.toLowerCase();
}

/** A colour's three channels, linearised; NaN when it isn't a colour. */
function linear(hex: string): Triple {
    const found = digits(hex);

    if (found === null) {
        return [Number.NaN, Number.NaN, Number.NaN];
    }

    const [red, green, blue] = [0, 2, 4].map((start) => {
        const channel = parseInt(found.slice(start, start + 2), 16) / 255;

        return channel <= 0.03928
            ? channel / 12.92
            : ((channel + 0.055) / 1.055) ** 2.4;
    });

    return [red, green, blue];
}

function luminance(hex: string): number {
    const [red, green, blue] = linear(hex);

    return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
}

function exactRatio(hexA: string, hexB: string): number {
    const [a, b] = [luminance(hexA), luminance(hexB)];

    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

// A failing ratio as a message states it: cut to two places, never rounded
// up, so a failing ratio never reads as 4.5:1.
function shown(ratio: number): string {
    return `${Math.floor(ratio * 100) / 100}:1`;
}

function passes(
    hex: string,
    base: string,
    candidates: readonly [string, string],
): boolean {
    return (
        exactRatio(hex, base) >= MINIMUM &&
        exactRatio(hex, onColor(hex, candidates)) >= MINIMUM
    );
}

function toOklab(hex: string): Triple {
    const [red, green, blue] = linear(hex);
    const l =
        (0.4122214708 * red + 0.5363325363 * green + 0.0514459929 * blue) **
        (1 / 3);
    const m =
        (0.2119034982 * red + 0.6806995451 * green + 0.1073969566 * blue) **
        (1 / 3);
    const s =
        (0.0883024619 * red + 0.2817188376 * green + 0.6299787005 * blue) **
        (1 / 3);

    return [
        0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s,
        1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s,
        0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s,
    ];
}

/** Linear sRGB, which may fall outside 0 to 1. */
function fromOklab(lightness: number, a: number, b: number): Triple {
    let l = lightness + 0.3963377774 * a + 0.2158037573 * b;
    let m = lightness - 0.1055613458 * a - 0.0638541728 * b;
    let s = lightness - 0.0894841775 * a - 1.291485548 * b;
    [l, m, s] = [l * l * l, m * m * m, s * s * s];

    return [
        4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
        -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
        -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
    ];
}

function inGamut(channels: Triple): boolean {
    return Math.min(...channels) >= 0 && Math.max(...channels) <= 1;
}

/** The largest share of the chroma, 0 to 1, that this lightness keeps inside sRGB. */
function chromaKept(lightness: number, a: number, b: number): number {
    if (inGamut(fromOklab(lightness, a, b))) {
        return 1;
    }

    let [low, high] = [0, 1];

    for (let i = 0; i < CHROMA_SEARCHES; i++) {
        const middle = (low + high) / 2;

        if (inGamut(fromOklab(lightness, a * middle, b * middle))) {
            low = middle;
        } else {
            high = middle;
        }
    }

    return low;
}

function hexOf(channels: Triple): string {
    return (
        '#' +
        channels
            .map((value) => {
                const channel = Math.min(1, Math.max(0, value));
                const encoded =
                    channel <= 0.0031308
                        ? 12.92 * channel
                        : 1.055 * channel ** (1 / 2.4) - 0.055;

                return Math.floor(encoded * 255 + 0.5)
                    .toString(16)
                    .padStart(2, '0');
            })
            .join('')
    );
}

/** Whether a value is a colour this file can read: #rgb or #rrggbb. */
export function isHexColor(value: string): boolean {
    return digits(value) !== null;
}

/** The contrast ratio of two colours, rounded to two places; NaN when either isn't a colour. */
export function ratio(hexA: string, hexB: string): number {
    return Math.floor(exactRatio(hexA, hexB) * 100 + 0.5) / 100;
}

/** Whichever of the two candidates contrasts more with this colour, the first on a tie. */
export function onColor(
    hex: string,
    candidates: readonly [string, string],
): string {
    const [first, second] = candidates;

    return exactRatio(hex, second) > exactRatio(hex, first) ? second : first;
}

/**
 * Why this accent can't be used, one sentence per failure: against the light
 * background (I-457; the dark scheme takes darkVariant() of it), then for
 * the text drawn on it. Empty when it passes.
 */
export function accentProblems(
    accent: string,
    lightBase: string,
    candidates: readonly [string, string],
): string[] {
    const problems: string[] = [];
    const found = exactRatio(accent, lightBase);

    if (found < MINIMUM) {
        problems.push(
            `The brand colour reaches only ${shown(found)} against the light background; it needs 4.5:1.`,
        );
    }

    const onAccent = exactRatio(accent, onColor(accent, candidates));

    if (onAccent < MINIMUM) {
        problems.push(
            `Text on the brand colour reaches only ${shown(onAccent)}; it needs 4.5:1.`,
        );
    }

    return problems;
}

/**
 * The accent for the dark scheme (I-457): the same colour, lightened just
 * enough to reach 4.5:1 against the dark background, with the text it takes
 * reaching 4.5:1 on it, as #rrggbb; '' when it isn't a colour. The same sums
 * as Contrast::darkVariant(), in the same order.
 */
export function darkVariant(
    accent: string,
    darkBase: string,
    candidates: readonly [string, string],
): string {
    const found = digits(accent);

    if (found === null) {
        return '';
    }

    const written = '#' + found;

    if (passes(written, darkBase, candidates)) {
        return written;
    }

    const [lightness, a, b] = toOklab(written);
    let step = 0;

    while (true) {
        step++;
        const stepped = Math.min(1, lightness + step * LIGHTNESS_STEP);
        const kept = chromaKept(stepped, a, b);
        const candidate = hexOf(fromOklab(stepped, a * kept, b * kept));

        if (stepped >= 1 || passes(candidate, darkBase, candidates)) {
            return candidate;
        }
    }
}
