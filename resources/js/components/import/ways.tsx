import { Button } from '@/components/ui/button';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type ImportWay = 'connect' | 'upload' | null;

/**
 * The two ways to add a site on Tools › Import: connect the live site, or
 * upload an export file. Side by side on wide screens; one column on a phone.
 */
export function ImportWays({
    open,
    onOpen,
}: {
    open: ImportWay;
    onOpen: (way: ImportWay) => void;
}) {
    return (
        <section aria-labelledby="import-add-site" className="grid gap-3">
            <h2 id="import-add-site" className="text-lead font-semibold">
                {__('Add a site')}
            </h2>
            <div className="grid gap-4 md:grid-cols-2">
                <WayCard
                    title={__('Connect the live site')}
                    open={open === 'connect'}
                    onOpen={() => onOpen(open === 'connect' ? null : 'connect')}
                    openLabel={__('Connect a site')}
                    note={__(
                        'Needs the Teme Companion plugin on the WordPress site.',
                    )}
                    testId="import-way-connect"
                    primary
                    items={[
                        __(
                            'Everything: posts, pages, media, people with their roles and passwords, comments, menus in their places',
                        ),
                        __(
                            'Redirects, search and social fields, site settings, languages',
                        ),
                        __('Catch up later with what changed'),
                    ]}
                />
                <WayCard
                    title={__('Upload an export file')}
                    open={open === 'upload'}
                    onOpen={() => onOpen(open === 'upload' ? null : 'upload')}
                    openLabel={__('Upload a file')}
                    note={__(
                        'Needs only the file from WordPress’s Tools › Export.',
                    )}
                    testId="import-way-upload"
                    items={[
                        __(
                            'Posts, pages and custom types, with their categories, tags and authors',
                        ),
                        __('Comments, menus, featured images'),
                        __(
                            'Media, downloaded from the old site while it answers',
                        ),
                    ]}
                />
            </div>
        </section>
    );
}

function WayCard({
    title,
    items,
    note,
    open,
    onOpen,
    openLabel,
    testId,
    primary = false,
}: {
    title: string;
    items: string[];
    note: string;
    open: boolean;
    onOpen: () => void;
    openLabel: string;
    testId: string;
    /** The preferred way in: Connect stays primary; Upload is secondary. */
    primary?: boolean;
}) {
    return (
        <div
            data-test={testId}
            className={cn(
                'border-edge bg-surface grid gap-4 rounded-md border p-5',
                open && 'border-accent',
            )}
        >
            <div className="grid gap-3">
                <h3 className="text-body font-semibold">{title}</h3>
                <ul className="text-body text-ink-2 grid list-disc gap-2 ps-5">
                    {items.map((item) => (
                        <li key={item}>{item}</li>
                    ))}
                </ul>
                <p className="text-small text-ink-3">{note}</p>
            </div>
            <div>
                <Button
                    type="button"
                    variant={primary ? 'default' : 'secondary'}
                    onClick={onOpen}
                    data-test={`${testId}-button`}
                >
                    {open ? __('Close') : openLabel}
                </Button>
            </div>
        </div>
    );
}
