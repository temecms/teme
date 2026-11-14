/** What a palette command is told when the palette opens and when it runs. */
export type PaletteContext = {
    /** The current route's name, such as content.index or content.edit. */
    route: string;
    /** The item's ID when the palette opened on an editor screen, otherwise null. */
    contentId: string | null;
    /** The AI tasks the open screen's `ai` prop offers this person; empty elsewhere (I-321). */
    aiTasks: string[];
    can: (ability: string) => boolean;
};

/** One command. The fields mirror 1.5c's RegionEntry, so phase 5 contributes both the same way. */
export type PaletteCommand = {
    id: string;
    owner: string;
    label: string;
    /** A line saying what it does. */
    description?: string;
    icon?: string;
    group: string;
    order: number;
    /** Words someone might type to find it; the palette is searched. */
    keywords?: string[];
    when?: (context: PaletteContext) => boolean;
    permission?: string;
    run: (context: PaletteContext) => void;
};
