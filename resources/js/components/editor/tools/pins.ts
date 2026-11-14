/** The most tools a person may pin to the editor's bar (DS 4.39). */
export const MAX_PINS = 3;

/** The pins after pinning or unpinning one tool, and whether a pin was refused for being a fourth. */
export function togglePin(
    pins: string[],
    id: string,
    pinnable: string[],
): { pins: string[]; refused: boolean } {
    if (pins.includes(id)) {
        return { pins: pins.filter((pin) => pin !== id), refused: false };
    }

    if (!pinnable.includes(id)) {
        return { pins, refused: false };
    }

    if (pins.length >= MAX_PINS) {
        return { pins, refused: true };
    }

    return { pins: [...pins, id], refused: false };
}
