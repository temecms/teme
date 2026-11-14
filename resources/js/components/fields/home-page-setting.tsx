import { Form } from '@inertiajs/react';
import {
    SettingsPanel,
    SettingsPanelBody,
    SettingsPanelFooter,
} from './settings-panel';
import { Lock } from 'lucide-react';
import { useState } from 'react';
import HomePageController from '@/actions/App/Http/Admin/Settings/HomePageController';
import { useLinkTargets } from '@/components/editor/use-link-targets';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HomePageSection } from '@/types';
import { shownTitle } from '@/lib/titles';

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
                <p className="text-[13px]">
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
                <ul className="border-line rounded border">
                    {found.slice(0, 8).map((target) => (
                        <li key={target.id}>
                            <button
                                type="button"
                                data-test="page-choice"
                                className="hover:bg-surface-2 w-full px-3 py-1.5 text-left text-[13px]"
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
 * addresses group's settings, saved by its own Operation, so a change moves
 * addresses in the background; read-only for someone who may not change
 * the addresses. Each control carries its setting's field-{key} id (I-1443).
 */
export function HomePageSetting({ home }: { home: HomePageSection }) {
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

    return (
        <div data-test="home-page">
            <SettingsPanel
                as="section"
                bare
                title="Home page"
                description="What visitors see at the site’s own address."
            >
                <Form
                    {...HomePageController.form()}
                    options={{ preserveScroll: true }}
                    className="grid"
                >
                    {({ processing, errors }) => {
                        const clashes = Object.entries(errors)
                            .filter(([key]) => key.startsWith('addresses'))
                            .map(([, message]) => message);

                        return (
                            <>
                                <SettingsPanelBody className="gap-4">
                                    <fieldset
                                        className="grid gap-2"
                                        disabled={locked}
                                    >
                                        <legend className="mb-2 text-sm font-medium">
                                            Home page shows
                                        </legend>
                                        <input
                                            type="hidden"
                                            name="front_shows"
                                            value={frontShows}
                                        />
                                        <label className="flex items-center gap-2 text-[13px]">
                                            <input
                                                type="radio"
                                                id="field-front_shows"
                                                name="front"
                                                checked={
                                                    frontShows ===
                                                    home.frontModes.latest
                                                }
                                                onChange={() =>
                                                    setFrontShows(
                                                        home.frontModes.latest,
                                                    )
                                                }
                                            />
                                            Your latest {home.latestWords}
                                        </label>
                                        <label className="flex items-center gap-2 text-[13px]">
                                            <input
                                                type="radio"
                                                id="front-shows-page"
                                                name="front"
                                                checked={
                                                    frontShows ===
                                                    home.frontModes.page
                                                }
                                                onChange={() =>
                                                    setFrontShows(
                                                        home.frontModes.page,
                                                    )
                                                }
                                            />
                                            A page
                                        </label>
                                        <InputError
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
                                        <ul className="text-danger-ink list-disc pl-5 text-[13px]">
                                            {clashes.map((clash) => (
                                                <li key={clash}>{clash}</li>
                                            ))}
                                        </ul>
                                    )}
                                </SettingsPanelBody>

                                <SettingsPanelFooter>
                                    {locked ? (
                                        <p className="text-ink-2 flex items-center gap-2 text-sm">
                                            <Lock
                                                aria-hidden="true"
                                                className="size-4"
                                            />
                                            Only someone who may change the
                                            site’s addresses can change the home
                                            page.
                                        </p>
                                    ) : (
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            data-test="home-page-save"
                                        >
                                            Save home page
                                        </Button>
                                    )}
                                </SettingsPanelFooter>
                            </>
                        );
                    }}
                </Form>
            </SettingsPanel>
        </div>
    );
}
