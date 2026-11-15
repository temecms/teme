import { Form } from '@inertiajs/react';
import { useState } from 'react';
import ImportSourceController from '@/actions/App/Http/Admin/Tools/Import/ImportSourceController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldInline } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { __, __n } from '@/lib/i18n';

/** A group of choices: its legend, a line on what it is for, and its error. */
const LEGEND = 'text-body text-ink font-semibold';
const GROUP = 'grid gap-4';

/**
 * Other old addresses of this site: listed hosts, suggestions from the
 * companion’s analysis as ticks with link counts, and a field to add one
 * by hand. Save asks for the person’s password.
 */
export function OtherHostsForm({
    siteId,
    listed,
    suggested,
    remembered = null,
}: {
    siteId: string;
    listed: string[];
    suggested: { host: string; links: number }[];
    remembered?: string[] | null;
}) {
    const suggestedHosts = suggested.map((row) => row.host);
    const [ticked, setTicked] = useState<string[]>(() =>
        (remembered ?? []).filter(
            (host) =>
                !listed.includes(host) && suggestedHosts.includes(host),
        ),
    );
    const [manual, setManual] = useState(
        () =>
            (remembered ?? []).find(
                (host) =>
                    !listed.includes(host) &&
                    !suggestedHosts.includes(host),
            ) ?? '',
    );

    const hosts = [
        ...listed,
        ...ticked.filter((host) => !listed.includes(host)),
        ...(manual.trim() !== '' ? [manual.trim()] : []),
    ];

    return (
        <Panel
            aria-labelledby="import-other-hosts"
            data-test="import-other-hosts"
        >
            <PanelHeader>
                <h2 id="import-other-hosts" className="text-lead font-semibold">
                    {__('Other addresses of this site')}
                </h2>
            </PanelHeader>
            <PanelBody>
                <Form
                    {...ImportSourceController.hosts.form(siteId)}
                    className="grid max-w-(--form-max) gap-6"
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <>
                            {hosts.map((host) => (
                                <input
                                    key={host}
                                    type="hidden"
                                    name="hosts[]"
                                    value={host}
                                />
                            ))}

                            <div className="grid gap-3">
                                <p className="text-body text-ink-2">
                                    {__(
                                        'Links and images on these hosts convert like ones on the home address. Tick a suggestion, or type another host the site once used.',
                                    )}
                                </p>

                                {listed.length > 0 && (
                                    <ul
                                        className="text-body grid gap-1"
                                        data-test="import-other-hosts-listed"
                                    >
                                        {listed.map((host) => (
                                            <li key={host}>{host}</li>
                                        ))}
                                    </ul>
                                )}
                            </div>

                            {suggested.length > 0 && (
                                <fieldset
                                    className={GROUP}
                                    aria-describedby="import-other-hosts-error"
                                >
                                    <legend className={LEGEND}>
                                        {__('Suggested from this site’s links')}
                                    </legend>
                                    {suggested.map((row, index) => (
                                        <FieldInline
                                            key={row.host}
                                            htmlFor={`import-other-host-${index}`}
                                            label={__(':host (:links)', {
                                                host: row.host,
                                                links: __n(
                                                    ':count link|:count links',
                                                    row.links,
                                                    {
                                                        count: row.links.toLocaleString(),
                                                    },
                                                ),
                                            })}
                                        >
                                            <Checkbox
                                                id={`import-other-host-${index}`}
                                                data-test={`import-other-host-${row.host}`}
                                                checked={
                                                    listed.includes(row.host) ||
                                                    ticked.includes(row.host)
                                                }
                                                disabled={listed.includes(
                                                    row.host,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    setTicked((current) =>
                                                        checked === true
                                                            ? [
                                                                  ...current,
                                                                  row.host,
                                                              ]
                                                            : current.filter(
                                                                  (host) =>
                                                                      host !==
                                                                      row.host,
                                                              ),
                                                    )
                                                }
                                            />
                                        </FieldInline>
                                    ))}
                                </fieldset>
                            )}

                            <Field
                                htmlFor="import-other-host-manual"
                                label={__('Add a host')}
                                error={errors.hosts}
                            >
                                <Input
                                    id="import-other-host-manual"
                                    data-test="import-other-host-manual"
                                    value={manual}
                                    onChange={(event) =>
                                        setManual(event.target.value)
                                    }
                                    placeholder={__('stage.example.com')}
                                    autoComplete="off"
                                />
                            </Field>

                            <InputError
                                id="import-other-hosts-error"
                                message={errors.hosts}
                            />

                            <div className="grid justify-items-start gap-2">
                                <Button
                                    type="submit"
                                    data-test="import-other-hosts-save"
                                    disabled={processing}
                                >
                                    {__('Save')}
                                </Button>
                                <p className="text-small text-ink-2">
                                    {__('Teme asks for your password first.')}
                                </p>
                            </div>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}
