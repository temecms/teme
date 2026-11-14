/** The font a desktop search result is drawn in: an outside estimate (I-882). */
const FONT = 'Arial, Helvetica, sans-serif';

let context: CanvasRenderingContext2D | null = null;

/** How wide a line of text is in a search result, in whole pixels, at this size. */
export function textWidth(text: string, size: number): number {
    context ??= document.createElement('canvas').getContext('2d');

    if (context === null) {
        return 0;
    }

    context.font = `${size}px ${FONT}`;

    return Math.round(context.measureText(text).width);
}

/** Text cut to a width as a search result cuts it, ending in "…". */
export function fitWidth(text: string, size: number, max: number): string {
    if (textWidth(text, size) <= max) {
        return text;
    }

    let cut = text;

    while (cut.length > 1 && textWidth(`${cut}…`, size) > max) {
        cut = cut.slice(0, -1);
    }

    return `${cut.trimEnd()}…`;
}
