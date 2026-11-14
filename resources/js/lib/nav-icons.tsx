import {
    Archive,
    Brush,
    File,
    FileText,
    Files,
    Folder,
    House,
    Image,
    Import,
    Layers,
    LayoutGrid,
    LayoutTemplate,
    Link,
    ListChecks,
    ListTree,
    MessageSquare,
    Plug,
    Search,
    Settings,
    Shapes,
    Sparkles,
    Tag,
    Tags,
    Users,
    Wrench,
} from 'lucide-react';
import type { LucideProps } from 'lucide-react';
import { lazy, Suspense } from 'react';
import type { ComponentType } from 'react';

// An unknown name draws a circle, and any Lucide name its icon (I-3301), the
// whole set loading as one file the first time a name is not below (I-3311).
const LazyDynamicNavIcon = lazy(() => import('./dynamic-icon'));

const icons: Record<string, ComponentType<LucideProps>> = {
    archive: Archive,
    brush: Brush,
    file: File,
    'file-text': FileText,
    files: Files,
    folder: Folder,
    home: House,
    image: Image,
    import: Import,
    layers: Layers,
    'layout-grid': LayoutGrid,
    'layout-template': LayoutTemplate,
    link: Link,
    'list-checks': ListChecks,
    'list-tree': ListTree,
    'message-square': MessageSquare,
    plug: Plug,
    search: Search,
    settings: Settings,
    shapes: Shapes,
    sparkles: Sparkles,
    tag: Tag,
    tags: Tags,
    users: Users,
    wrench: Wrench,
};

const dynamic = new Map<string, ComponentType<LucideProps>>();

/** The icon a menu entry, content type or taxonomy names. */
export function navIcon(name: string): ComponentType<LucideProps> {
    // Own entries only: a name such as "constructor" is on every object.
    if (Object.hasOwn(icons, name)) {
        return icons[name];
    }

    const loaded = dynamic.get(name);

    if (loaded !== undefined) {
        return loaded;
    }

    function Icon(props: LucideProps) {
        return (
            <Suspense
                fallback={<span aria-hidden className={props.className} />}
            >
                <LazyDynamicNavIcon name={name} {...props} />
            </Suspense>
        );
    }

    dynamic.set(name, Icon);

    return Icon;
}
