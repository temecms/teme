import { Lock } from 'lucide-react';
import { useState } from 'react';
import { useLinkTargets } from '@/components/editor/use-link-targets';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupChoice } from '@/components/ui/radio-group';
import { __ } from '@/lib/i18n';
import { shownTitle } from '@/lib/titles';
import type { HomePageSection } from '@/types';
import { SettingsPanel } from './settings-panel';

/** A page picker: published items of a type with no archive. */
function PagePicker({
    id,
    label,
    name,
    value,
    title,
    pageTypes,
    disabled,
    onChange,
}: {
    id: string;
    label: string;
    name: string;
    value: string;
    title: string;
    pageTypes: string[];
    disabled: boolean;
    onChange: (id: string, title: string) => void;
}) {
    const [query, setQuery] = useState('');
    const found = useLinkTargets(query, query.trim() !== '').filter(
        (target) =>
            pageTypes.includes(target.type) && target.status === 'published',
    );

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <input type="hidden" name={name} value={value} />
            {value !== '' && (
                <p className="text-small">
                    Chosen: <span className="font-medium">{title}</span>
                </p>
            )}
            <Input
                id={id}
                value={query}
                disabled={disabled}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="Search published pages"
            />
            {found.length > 0 && (
                <ul className="border-line rounded-md border p-1">
                    {found.slice(0, 8).map((target) => (
                        <li key={target.id}>
                            <button
                                type="button"
                                data-test="page-choice"
                                className="text-small hover:bg-surface-2 focus-visible:outline-ring min-h-(--ctl-h-sm) w-full rounded-sm px-3 text-left focus-visible:outline-2"
                                onClick={() => {
                                    onChange(target.id, target.title);
                                    setQuery('');
                                }}
                            >
                                {shownTitle(target.title)}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * Reading's Home page: whether the site's own address shows the latest
 * posts or one page, and which page lists the posts then. They are the
 * addresses group's settings, a section of Reading's form saved with it, so
 * a change moves addresses in the background; read-only, and sent with
 * nothing, for someone who may not change the addresses. Each control
 * carries its setting's field-{key} id (I-1443).
 */
export function HomePageSetting({
    home,
    errors,
}: {
    home: HomePageSection;
    /** The page form's errors: the home page's are keyed as ChangeAddresses keys them. */
    errors: Record<string, string>;
}) {
    const [frontShows, setFrontShows] = useState(home.frontShows);
    const [front, setFront] = useState({
        id: home.frontPageId,
        title: home.chosenPages[home.frontPageId] ?? '',
    });
    const [posts, setPosts] = useState({
        id: home.postsPageId,
        title: home.chosenPages[home.postsPageId] ?? '',
    });
    const locked = !home.canChange;
    const listName =
        home.latestWords.charAt(0).toUpperCase() + home.latestWords.slice(1);

    const clashes = Object.entries(errors)
        .filter(([key]) => key.startsWith('addresses'))
        .map(([, message]) => message);

    return (
        <div data-test="home-page">
            <SettingsPanel
                as="section"
                title="Home page"
                description="What visitors see at the site’s own address."
            >
                <fieldset className="grid gap-2" disabled={locked}>
                    <legend className="text-small text-ink-2 mb-1 font-medium">
                        Home page shows
                    </legend>
                    <input
                        type="hidden"
                        name="front_shows"
                        value={frontShows}
                    />
                    <RadioGroup
                        value={frontShows}
                        onValueChange={setFrontShows}
                        disabled={locked}
                    >
                        <RadioGroupChoice
                            id="field-front_shows"
                            value={home.frontModes.latest}
                            label={__('Your latest :things', {
                                things: home.latestWords,
                            })}
                        />
                        <RadioGroupChoice
                            id="front-shows-page"
                            value={home.frontModes.page}
                            label="A page"
                        />
                    </RadioGroup>
                    <InputError
                        id="field-front_shows-error"
                        message={errors.frontShows}
                    />

                    {frontShows === home.frontModes.page ? (
                        <div className="grid gap-4 pl-6">
                            <PagePicker
                                id="field-front_page_id"
                                label="Home page"
                                name="front_page_id"
                                value={front.id}
                                title={front.title}
                                pageTypes={home.pageTypes}
                                disabled={locked}
                                onChange={(id, title) =>
                                    setFront({ id, title })
                                }
                            />
                            <InputError
                                id="field-front_page_id-error"
                                message={errors.frontPageId}
                            />
                            <PagePicker
                                id="field-posts_page_id"
                                label={`${listName} page`}
                                name="posts_page_id"
                                value={posts.id}
                                title={posts.title}
                                pageTypes={home.pageTypes}
                                disabled={locked}
                                onChange={(id, title) =>
                                    setPosts({ id, title })
                                }
                            />
                            <InputError
                                id="field-posts_page_id-error"
                                message={errors.postsPageId}
                            />
                        </div>
                    ) : (
                        <>
                            <input
                                type="hidden"
                                name="front_page_id"
                                value={front.id}
                            />
                            <input
                                type="hidden"
                                name="posts_page_id"
                                value={posts.id}
                            />
                        </>
                    )}
                </fieldset>

                {clashes.length > 0 && (
                    <ul className="text-small text-danger-ink list-disc pl-5">
                        {clashes.map((clash) => (
                            <li key={clash}>{clash}</li>
                        ))}
                    </ul>
                )}

                {locked && (
                    <p className="text-caption text-ink-3 flex items-center gap-2">
                        <Lock aria-hidden="true" className="size-4" />
                        Only someone who may change the site’s addresses can
                        change the home page.
                    </p>
                )}
            </SettingsPanel>
        </div>
    );
}
