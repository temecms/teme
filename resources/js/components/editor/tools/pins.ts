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

/** The tools already warned of, so each warning is given once. */
const warned = new Set<string>();

/**
 * The tools the editor can open: the available ones, without any that opens
 * as a workspace, which is refused with a warning until the workspace frame
 * is built.
 */
export function offeredTools<
    Tool extends { id: string; size: string; available: boolean },
>(tools: Tool[]): Tool[] {
    return tools.filter((tool) => {
        if (tool.size === 'workspace') {
            if (!warned.has(tool.id)) {
                warned.add(tool.id);
                console.warn(
                    `The editor tool "${tool.id}" opens as a workspace, which the editor cannot show yet, so it is left out.`,
                );
            }

            return false;
        }

        return tool.available;
    });
}
