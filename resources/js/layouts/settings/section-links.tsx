import { useEffect, useState } from 'react';
import type { RefObject } from 'react';
import { __ } from '@/lib/i18n';

type Section = { id: string; title: string };

/** The nearest ancestor that scrolls: the admin's view, which scrolls instead of the window. */
function scroller(element: HTMLElement): HTMLElement | Window {
    let parent = element.parentElement;

    while (parent !== null) {
        const { overflowY } = window.getComputedStyle(parent);

        if (overflowY === 'auto' || overflowY === 'scroll') {
            return parent;
        }

        parent = parent.parentElement;
    }

    return window;
}

/** The titled sections drawn in the page, those that show. */
function read(main: HTMLElement): Section[] {
    return [...main.querySelectorAll<HTMLElement>('section, fieldset')]
        .filter((section) => section.dataset.settingsSection !== undefined)
        .filter((section) => section.closest('[hidden]') === null)
        .map((section) => ({
            id: section.id,
            title: section.dataset.settingsSection ?? '',
        }))
        .filter((section) => section.id !== '' && section.title !== '');
}

/**
 * The current page's sections under its entry in the Settings frame's side
 * column (docs/architecture/design-system.md, 4.42): read from the titled
 * panels the page draws, so a page lists whatever sections it has and a
 * hidden one drops out. The section in view is marked as the page scrolls,
 * and a click jumps to it.
 */
export function SectionLinks({
    main,
}: {
    main: RefObject<HTMLElement | null>;
}) {
    const [sections, setSections] = useState<Section[]>([]);
    const [current, setCurrent] = useState('');

    useEffect(() => {
        const element = main.current;

        if (element === null) {
            return;
        }

        const refresh = () => {
            const next = read(element);

            setSections((before) =>
                JSON.stringify(before) === JSON.stringify(next) ? before : next,
            );
        };
        const observer = new MutationObserver(refresh);

        refresh();
        observer.observe(element, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ['hidden', 'data-settings-section', 'id'],
        });

        return () => observer.disconnect();
    }, [main]);

    useEffect(() => {
        const element = main.current;

        if (element === null || sections.length === 0) {
            return;
        }

        const box = scroller(element);
        const spy = () => {
            const top =
                box instanceof Window ? 0 : box.getBoundingClientRect().top;
            let here = sections[0].id;

            for (const section of sections) {
                const node = document.getElementById(section.id);

                if (
                    node !== null &&
                    node.getBoundingClientRect().top <= top + 96
                ) {
                    here = section.id;
                }
            }

            setCurrent(here);
        };

        spy();
        box.addEventListener('scroll', spy, { passive: true });

        return () => box.removeEventListener('scroll', spy);
    }, [main, sections]);

    if (sections.length === 0) {
        return null;
    }

    return (
        <div
            role="group"
            aria-label={__('On this page')}
            data-test="settings-sections"
            className="border-surface-4 ms-4 mt-1 mb-2 grid gap-0.5 border-s-2 ps-2"
        >
            {sections.map((section) => (
                <a
                    key={section.id}
                    href={`#${section.id}`}
                    aria-current={section.id === current ? 'true' : undefined}
                    onClick={(event) => {
                        event.preventDefault();
                        document
                            .getElementById(section.id)
                            ?.scrollIntoView({ block: 'start' });
                        setCurrent(section.id);
                    }}
                    className="text-small text-ink-2 hover:bg-surface-3 hover:text-ink aria-[current=true]:bg-surface-3 aria-[current=true]:text-ink focus-visible:outline-ring flex min-h-(--ctl-h-sm) items-center rounded-md px-3 focus-visible:outline-2 focus-visible:-outline-offset-2"
                >
                    {section.title}
                </a>
            ))}
        </div>
    );
}
