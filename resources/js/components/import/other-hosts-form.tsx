import { Form } from "@inertiajs/react";
import { useState } from "react";
import ImportSourceController from "@/actions/App/Http/Admin/Tools/Import/ImportSourceController";
import InputError from "@/components/input-error";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Field, FieldInline } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Panel, PanelBody, PanelHeader } from "@/components/ui/panel";
import { __, __n } from "@/lib/i18n";

/** A group of choices: its legend, a line on what it is for, and its error. */
const LEGEND = "text-body text-ink font-semibold";
const GROUP = "grid gap-4";

/**
 * Other old addresses of this site: listed hosts as ticks the person can
 * clear, suggestions from the companion’s analysis, and a field to add one
 * by hand. Save asks for the person’s password and sends only what is
 * still ticked plus a typed host.
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
    const [kept, setKept] = useState<string[]>(() =>
        remembered === null
            ? [...listed]
            : listed.filter((host) => remembered.includes(host)),
    );
    const [ticked, setTicked] = useState<string[]>(() =>
        (remembered ?? []).filter(
            (host) => !listed.includes(host) && suggestedHosts.includes(host),
        ),
    );
    const [manual, setManual] = useState(
        () =>
            (remembered ?? []).find(
                (host) =>
                    !listed.includes(host) && !suggestedHosts.includes(host),
            ) ?? "",
    );

    const hosts = [
        ...kept,
        ...ticked.filter((host) => !kept.includes(host)),
        ...(manual.trim() !== "" ? [manual.trim()] : []),
    ];

    return (
        <Panel
            aria-labelledby="import-other-hosts"
            data-test="import-other-hosts"
        >
            <PanelHeader>
                <h2 id="import-other-hosts" className="text-lead font-semibold">
                    {__("Other addresses of this site")}
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
                                        "Links and images on these hosts convert like ones on the home address. Untick a saved address to drop it, tick a suggestion, or type another host the site once used.",
                                    )}
                                </p>
                            </div>

                            {listed.length > 0 && (
                                <fieldset
                                    className={GROUP}
                                    aria-describedby="import-other-hosts-error"
                                    data-test="import-other-hosts-listed"
                                >
                                    <legend className={LEGEND}>
                                        {__("Saved addresses")}
                                    </legend>
                                    {listed.map((host, index) => (
                                        <FieldInline
                                            key={host}
                                            htmlFor={`import-other-host-listed-${index}`}
                                            label={host}
                                        >
                                            <Checkbox
                                                id={`import-other-host-listed-${index}`}
                                                data-test={`import-other-host-listed-${host}`}
                                                checked={kept.includes(host)}
                                                onCheckedChange={(checked) =>
                                                    setKept((current) =>
                                                        checked === true
                                                            ? [...current, host]
                                                            : current.filter(
                                                                  (row) =>
                                                                      row !==
                                                                      host,
                                                              ),
                                                    )
                                                }
                                            />
                                        </FieldInline>
                                    ))}
                                </fieldset>
                            )}

                            {suggested.filter(
                                (row) => !listed.includes(row.host),
                            ).length > 0 && (
                                <fieldset
                                    className={GROUP}
                                    aria-describedby="import-other-hosts-error"
                                >
                                    <legend className={LEGEND}>
                                        {__("Suggested from this site’s links")}
                                    </legend>
                                    {suggested
                                        .filter(
                                            (row) => !listed.includes(row.host),
                                        )
                                        .map((row, index) => (
                                            <FieldInline
                                                key={row.host}
                                                htmlFor={`import-other-host-${index}`}
                                                label={__(":host (:links)", {
                                                    host: row.host,
                                                    links: __n(
                                                        ":count link|:count links",
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
                                                    checked={ticked.includes(
                                                        row.host,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
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
                                label={__("Add a host")}
                                error={errors.hosts}
                            >
                                <Input
                                    id="import-other-host-manual"
                                    data-test="import-other-host-manual"
                                    value={manual}
                                    onChange={(event) =>
                                        setManual(event.target.value)
                                    }
                                    placeholder={__("stage.example.com")}
                                    autoComplete="off"
                                />
                            </Field>

                            <div className="grid justify-items-start gap-2">
                                <Button
                                    type="submit"
                                    data-test="import-other-hosts-save"
                                    disabled={processing}
                                >
                                    {__("Save")}
                                </Button>
                                <p className="text-small text-ink-2">
                                    {__("Teme asks for your password first.")}
                                </p>
                            </div>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}
