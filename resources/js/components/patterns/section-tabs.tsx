import { useEffect, useState } from 'react';
import type { MouseEvent } from 'react';
import { ScrollArea } from '@/components/ui/scroll-area';

/** One section of a long form: the id of its wrapper and its tab's words. */
export type FormSection = { id: string; label: string };

/** InPageNav's item, the section in view marked instead of the page. */
const ITEM =
    'text-body text-ink-2 hover:bg-surface-3 hover:text-ink aria-[current=location]:bg-surface-3 aria-[current=location]:text-ink focus-visible:outline-ring inline-flex h-(--ctl-h) shrink-0 items-center rounded-md px-3 font-medium whitespace-nowrap focus-visible:outline-2 focus-visible:-outline-offset-2';

/**
 * A long form's sections as a row of tabs (docs/architecture/design-system.md,
 * section 4.27's look): each a link to its section of the same form, the one
 * in view marked. Nothing is visited and the address does not change, so the
 * form keeps what was typed and the leave guard is never asked.
 */
export function SectionTabs({
    label,
    sections,
}: {
    label: string;
    sections: FormSection[];
}) {
    const [current, setCurrent] = useState(sections[0]?.id ?? '');
    const ids = sections.map((section) => section.id).join(' ');

    useEffect(() => {
        // The section nearest the top of the view, once its top has passed the upper third.
        const observer = new IntersectionObserver(
            (entries) => {
                const shown = entries
                    .filter((entry) => entry.isIntersecting)
                    .sort(
                        (a, b) =>
                            a.boundingClientRect.top - b.boundingClientRect.top,
                    )[0];

                if (shown !== undefined) {
                    setCurrent(shown.target.id);
                }
            },
            { rootMargin: '0px 0px -66% 0px' },
        );

        for (const id of ids.split(' ')) {
            const element = document.getElementById(id);

            if (element !== null) {
                observer.observe(element);
            }
        }

        return () => observer.disconnect();
    }, [ids]);

    const go = (event: MouseEvent<HTMLAnchorElement>, id: string) => {
        const target = document.getElementById(id);

        if (target === null) {
            return;
        }

        event.preventDefault();
        target.scrollIntoView({
            block: 'start',
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                .matches
                ? 'auto'
                : 'smooth',
        });
        target.focus({ preventScroll: true });
        setCurrent(id);
    };

    return (
        <nav
            aria-label={label}
            data-slot="section-tabs"
            className="bg-page sticky top-0 z-(--z-sticky) mb-4 py-2 max-sm:static"
        >
            <ScrollArea axis="x">
                <ul className="flex flex-wrap gap-1">
                    {sections.map((section) => (
                        <li key={section.id} className="flex">
                            <a
                                href={`#${section.id}`}
                                aria-current={
                                    current === section.id
                                        ? 'location'
                                        : undefined
                                }
                                className={ITEM}
                                onClick={(event) => go(event, section.id)}
                            >
                                {section.label}
                            </a>
                        </li>
                    ))}
                </ul>
            </ScrollArea>
        </nav>
    );
}
