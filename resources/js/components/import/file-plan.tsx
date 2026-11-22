import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import ImportRunController from '@/actions/App/Http/Admin/Tools/Import/ImportRunController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldInline } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { RadioGroup, RadioGroupChoice } from '@/components/ui/radio-group';
import { SelectField } from '@/components/ui/select-field';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import type { FileChoicesForm, ImportPlan, ImportSite } from '@/types/import';

const LEGEND = 'text-body text-ink font-semibold';
const GROUP = 'grid gap-4';

/** A panel of the file's plan, with its heading. */
function Section({
    id,
    title,
    action,
    children,
}: {
    id: string;
    title: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Panel aria-labelledby={id}>
            <PanelHeader>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 id={id} className="text-lead font-semibold">
                        {title}
                    </h2>
                    {action}
                </div>
            </PanelHeader>
            <PanelBody className="text-body justify-items-start">
                {children}
            </PanelBody>
        </Panel>
    );
}

/** One thing an export file can't hold, and what that means here. */
function Missing({ title, children }: { title: string; children: ReactNode }) {
    return (
        <li>
            <span className="font-medium">{title}</span>
            <span className="text-ink-2 block">{children}</span>
        </li>
    );
}

/**
 * The plan of a site read from an export file (option A of the approved
 * Import pages): what comes across, what an export file can't hold, the
 * choices, and Start.
 */
export function FilePlan({
    site,
    plan,
    onAnotherFile,
}: {
    site: ImportSite;
    plan: ImportPlan;
    onAnotherFile: () => void;
}) {
    const { analysis, problem } = plan;
    const form = plan.fileForm;

    if (problem !== null || analysis === null || form === null) {
        return (
            <Section id="import-comes" title={__('What comes across')}>
                <p>{problem ?? __('Upload the export file again.')}</p>
            </Section>
        );
    }

    const missingTypes = form.types.filter((type) => type.type === null);

    return (
        <div data-test="import-file-plan" className="grid gap-4">
            <Section
                id="import-comes"
                title={__('What comes across')}
                action={
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onAnotherFile}
                        data-test="import-another-file"
                    >
                        {__('Use another file')}
                    </Button>
                }
            >
                <dl className="grid w-full grid-cols-[minmax(0,1fr)_auto] gap-x-6 gap-y-1 sm:max-w-(--form-max)">
                    {analysis.comes.map((row) => (
                        <div key={row.key} className="contents">
                            <dt className="text-ink-2">{row.label}</dt>
                            <dd className="text-end tabular-nums">
                                {(row.count ?? 0).toLocaleString()}
                            </dd>
                        </div>
                    ))}
                </dl>
                <p>
                    {__(
                        'Every item keeps its address from the file: each one answers at the same address on Teme.',
                    )}
                    {analysis.seo !== null &&
                        ' ' +
                            __(
                                'Search titles and descriptions come across from :plugin’s fields in the file.',
                                { plugin: analysis.seo },
                            )}
                </p>
            </Section>

            <Section
                id="import-file-missing"
                title={__('Not in an export file')}
            >
                <ul className="grid gap-3">
                    <Missing title={__('Passwords and roles')}>
                        {__(
                            'An export file has neither. People you add get the role you choose below and set a password with “Forgotten your password?” the first time they sign in.',
                        )}
                    </Missing>
                    <Missing title={__('Where menus sit')}>
                        {__('The menus come across; you place them below.')}
                    </Missing>
                    <Missing title={__('Redirects made by plugins')}>
                        {__(
                            'Each item’s own address comes across. Redirects kept by a plugin such as Redirection don’t.',
                        )}
                    </Missing>
                    {missingTypes.length > 0 && (
                        <Missing title={__('Custom types Teme doesn’t have')}>
                            {missingTypes.map((type) => (
                                <span key={type.key} className="block">
                                    {__(
                                        ':type, :n items: this site has no :type type yet. Add one with a plugin or a types file, then import again.',
                                        {
                                            type: type.label,
                                            n: type.count.toLocaleString(),
                                        },
                                    )}
                                </span>
                            ))}
                        </Missing>
                    )}
                    <Missing title={__('Site settings and widgets')}>
                        {__(
                            'Title, front page, time zone and widgets are set in Teme’s Settings and theme.',
                        )}
                    </Missing>
                    <Missing title={__('Spam comments')}>
                        {__('WordPress leaves them out of the export.')}
                    </Missing>
                </ul>
                <p className="text-ink-2">
                    {__(
                        'To bring these too, connect the live site with Teme Companion instead.',
                    )}
                </p>
            </Section>

            <Section id="import-choices" title={__('Your choices')}>
                <p className="text-ink-2">
                    {__(
                        'Teme uses these for this import, and again if you import a newer file of the same site.',
                    )}
                </p>
                <FileChoices
                    site={site}
                    form={form}
                    answers={plan.oldSiteAnswers ?? false}
                />
            </Section>
        </div>
    );
}

/** The options of one author's select: the person matched, added in a role, or credited to someone here. */
function authorOptions(
    form: FileChoicesForm,
    author: FileChoicesForm['authors'][number],
): { value: string; label: string }[] {
    const matched =
        author.matched === null
            ? []
            : [
                  {
                      value: `match:${author.matched}`,
                      label: __(':person (this site)', {
                          person:
                              form.people.find(
                                  (person) => person.value === author.matched,
                              )?.label ?? author.name,
                      }),
                  },
              ];
    const added = form.fileRoles.map((role) => ({
        value: `new:${role}`,
        label:
            role === 'author'
                ? __('Add :name as an author', { name: author.name })
                : role === 'contributor'
                  ? __('… as a contributor')
                  : __('… as an editor'),
    }));
    const credited = form.people
        .filter((person) => person.value !== author.matched)
        .map((person) => ({
            value: `to:${person.value}`,
            label: __('Credit to :person', { person: person.label }),
        }));

    return [...matched, ...added, ...credited];
}

/** The file's choices, with their defaults, and Start. */
function FileChoices({
    site,
    form,
    answers,
}: {
    site: ImportSite;
    form: FileChoicesForm;
    answers: boolean;
}) {
    const [media, setMedia] = useState<string>(answers ? form.media : 'links');
    const [drafts, setDrafts] = useState(form.drafts);
    const [comments, setComments] = useState(form.comments);
    const [types, setTypes] = useState<string[]>(
        form.types
            .filter((type) => type.type !== null && type.value !== '')
            .map((type) => type.key),
    );
    const host = site.host;

    return (
        <Form
            {...ImportRunController.start.form({ source: site.id })}
            className="grid w-full max-w-(--form-max) gap-6"
            data-test="import-file-choices"
        >
            {({ processing, errors }) => (
                <>
                    {form.authors.length > 0 && (
                        <fieldset className={GROUP}>
                            <legend className={LEGEND}>{__('Authors')}</legend>
                            <p className="text-small text-ink-2">
                                {__(
                                    'Each author in the file, matched by email to a person on this site, or added. People added set their password with “Forgotten your password?” the first time they sign in; the import emails no one.',
                                )}
                            </p>
                            {form.authors.map((author, index) => (
                                <Field
                                    key={author.login}
                                    htmlFor={`import-author-${index}`}
                                    label={author.name}
                                    help={
                                        <>
                                            {__(':login · :email · :n items', {
                                                login: author.login,
                                                email: author.email,
                                                n: author.items.toLocaleString(),
                                            })}
                                            {author.matched !== null && (
                                                <span className="text-ok-ink block">
                                                    {__(
                                                        'Same email as a person on this site',
                                                    )}
                                                </span>
                                            )}
                                        </>
                                    }
                                    error={errors[`authors.${author.login}`]}
                                >
                                    <SelectField
                                        id={`import-author-${index}`}
                                        name={`authors[${author.login}]`}
                                        options={authorOptions(form, author)}
                                        defaultValue={author.value}
                                    />
                                </Field>
                            ))}
                        </fieldset>
                    )}

                    <fieldset className={GROUP}>
                        <legend className={LEGEND}>
                            {__('What to bring')}
                        </legend>
                        <FieldInline
                            htmlFor="import-bring-posts"
                            label={posts(form)}
                        >
                            <Checkbox
                                id="import-bring-posts"
                                checked
                                disabled
                            />
                        </FieldInline>
                        {form.types
                            .filter((type) => type.type !== null)
                            .map((type) => (
                                <FieldInline
                                    key={type.key}
                                    htmlFor={`import-type-${type.key}`}
                                    label={__(':type (:n)', {
                                        type: type.typeLabel ?? type.label,
                                        n: type.count.toLocaleString(),
                                    })}
                                >
                                    <Checkbox
                                        id={`import-type-${type.key}`}
                                        checked={types.includes(type.key)}
                                        onCheckedChange={(checked) =>
                                            setTypes((kept) =>
                                                checked === true
                                                    ? [...kept, type.key]
                                                    : kept.filter(
                                                          (key) =>
                                                              key !== type.key,
                                                      ),
                                            )
                                        }
                                    />
                                </FieldInline>
                            ))}
                        {form.types.map((type) => (
                            <input
                                key={type.key}
                                type="hidden"
                                name={`types[${type.key}]`}
                                value={
                                    type.type !== null &&
                                    types.includes(type.key)
                                        ? type.type
                                        : ''
                                }
                            />
                        ))}
                        <FieldInline
                            htmlFor="import-drafts"
                            label={__(
                                'Drafts, scheduled and private items too',
                            )}
                        >
                            <Checkbox
                                id="import-drafts"
                                checked={drafts}
                                onCheckedChange={(checked) =>
                                    setDrafts(checked === true)
                                }
                            />
                        </FieldInline>
                        <input
                            type="hidden"
                            name="drafts"
                            value={drafts ? '1' : '0'}
                        />
                        <FieldInline
                            htmlFor="import-comments"
                            label={__('Comments')}
                        >
                            <Checkbox
                                id="import-comments"
                                checked={comments}
                                onCheckedChange={(checked) =>
                                    setComments(checked === true)
                                }
                            />
                        </FieldInline>
                        <input
                            type="hidden"
                            name="comments"
                            value={comments ? '1' : '0'}
                        />
                    </fieldset>

                    <fieldset
                        className={GROUP}
                        aria-describedby="import-media-error"
                    >
                        <legend className={LEGEND}>{__('Media files')}</legend>
                        <p className="text-small text-ink-2">
                            {__(
                                'The file lists :n media files; the files themselves are on the old site.',
                                {
                                    n: (form.mediaCount ?? 0).toLocaleString(),
                                },
                            )}
                        </p>
                        {!answers && (
                            <Notice
                                tone="warn"
                                data-test="import-old-site-silent"
                            >
                                {__(
                                    ':host doesn’t answer. Teme can’t download the media files from it. Bring the site back for the import, or keep them as links and add them later.',
                                    { host },
                                )}
                            </Notice>
                        )}
                        <RadioGroup
                            name="media"
                            value={media}
                            onValueChange={setMedia}
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <RadioGroupChoice
                                    id="import-media-download"
                                    value="download"
                                    disabled={!answers}
                                    label={__('Download them from :host', {
                                        host,
                                    })}
                                />
                                {answers && (
                                    <StatusPill
                                        tone="ok"
                                        data-test="import-old-site-answers"
                                    >
                                        {__(':host answers', { host })}
                                    </StatusPill>
                                )}
                            </div>
                            <RadioGroupChoice
                                id="import-media-links"
                                value="links"
                                label={__('Keep them on :host as links', {
                                    host,
                                })}
                                description={__(
                                    'Pictures break when the old site closes',
                                )}
                            />
                        </RadioGroup>
                        <InputError
                            id="import-media-error"
                            message={errors.media}
                        />
                    </fieldset>

                    {form.menuLocations.length > 0 && (
                        <fieldset className={GROUP}>
                            <legend className={LEGEND}>
                                {__('Menu locations')}
                            </legend>
                            <p className="text-small text-ink-2">
                                {__(
                                    'Where each menu goes in this site’s theme. The file has the menus but not their places.',
                                )}
                            </p>
                            {form.menuLocations.map((row) => (
                                <Field
                                    key={row.key}
                                    htmlFor={`import-location-${row.key}`}
                                    label={row.label}
                                    error={errors[`menu_locations.${row.key}`]}
                                >
                                    <SelectField
                                        id={`import-location-${row.key}`}
                                        name={`menu_locations[${row.key}]`}
                                        options={form.slots}
                                        emptyLabel={__('Don’t place it')}
                                        defaultValue={row.value}
                                    />
                                </Field>
                            ))}
                        </fieldset>
                    )}

                    {form.templates.map((row) => (
                        <input
                            key={row.key}
                            type="hidden"
                            name={`templates[${row.key}]`}
                            value={row.value}
                        />
                    ))}
                    <input type="hidden" name="owner" value={form.owner} />
                    <input
                        type="hidden"
                        name="pause"
                        value={String(form.pause)}
                    />

                    <fieldset className={GROUP}>
                        <legend className={LEGEND}>
                            {__('Category and tag addresses')}
                        </legend>
                        <p className="text-small text-ink-2">
                            {__(
                                'The file has each post’s and page’s address, but not where the old site put its category and tag pages. These came from the file’s links; change them if the old site used others.',
                            )}
                        </p>
                        <Base
                            id="import-category-base"
                            name="category_base"
                            host={host}
                            value={form.categoryBase}
                            example="news"
                            error={errors.category_base}
                            label={__('Category addresses')}
                        />
                        <Base
                            id="import-tag-base"
                            name="tag_base"
                            host={host}
                            value={form.tagBase}
                            example="budget"
                            error={errors.tag_base}
                            label={__('Tag addresses')}
                        />
                    </fieldset>

                    <div className="grid justify-items-start gap-2">
                        <InputError
                            id="import-choices-error"
                            message={errors.choices ?? errors.file}
                        />
                        <Button
                            type="submit"
                            data-test="import-start"
                            aria-describedby="import-choices-error"
                            disabled={processing}
                        >
                            {__('Start the import')}
                        </Button>
                        <p className="text-small text-ink-2">
                            {__(
                                'Teme asks for your password first. The import runs in the background: you can leave this page, and the result reaches your inbox.',
                            )}
                        </p>
                    </div>
                </>
            )}
        </Form>
    );
}

/** The posts' and pages' line of What to bring, with their counts. */
function posts(form: FileChoicesForm): string {
    return __('Posts (:posts) and Pages (:pages)', {
        posts: form.postCount.toLocaleString(),
        pages: form.pageCount.toLocaleString(),
    });
}

/** One base of the old site's term addresses, shown inside its address: {host}/[category]/news/. */
function Base({
    id,
    name,
    host,
    value,
    example,
    error,
    label,
}: {
    id: string;
    name: string;
    host: string;
    value: string;
    example: string;
    error?: string;
    label: string;
}) {
    return (
        <Field htmlFor={id} label={label} error={error}>
            <div className="text-small flex flex-wrap items-center gap-1 font-mono">
                <span className="text-ink-2">{host}/</span>
                <Input
                    id={id}
                    name={name}
                    defaultValue={value}
                    className="w-40 font-mono"
                    autoComplete="off"
                    spellCheck={false}
                />
                <span className="text-ink-2">/{example}/</span>
            </div>
        </Field>
    );
}
