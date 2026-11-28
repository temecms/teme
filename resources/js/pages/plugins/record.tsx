import type { RequestPayload } from '@inertiajs/core';
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import PluginAreaController from '@/actions/App/Http/Admin/PluginAreaController';
import { FormPanel } from '@/components/patterns/form-page';
import { PluginAreaPage } from '@/components/patterns/plugin-area-page';
import { SaveBar } from '@/components/patterns/save-bar';
import { SectionTabs } from '@/components/patterns/section-tabs';
import {
    AreaFieldInput,
    AreaScriptsProvider,
    useScriptApproval,
} from '@/components/plugins/area/area-form';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Notice } from '@/components/ui/notice';
import { __, __n } from '@/lib/i18n';
import { visibleKeys } from '@/lib/visible-when';
import type { PluginRecordPageProps } from '@/types/plugin-area';
import { RecordHostNotices } from '@/components/plugins/area/host-notices';

/**
 * A record's form in a plugin's area (docs/plans/plugin-sockets-2.md, A9):
 * the area header with the plugin's icon and mark, All … back to the list,
 * its sections as panels, a field shown only while its condition holds, and
 * the one save bar. Delete sits as danger-quiet at the foot of the last
 * panel (design-system §5 Form), confirmed. The top bar names the area and
 * the record (design system 4.31).
 */
export default function PluginRecord(props: PluginRecordPageProps) {
    const {
        plugin,
        back,
        area,
        record,
        id,
        title,
        sections,
        failed,
        deletable,
    } = props;
    const page = usePage();
    const errors = (page.props.errors ?? {}) as Record<string, string>;
    const [values, setValues] = useState<Record<string, unknown>>(props.values);
    const [processing, setProcessing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const dirty = useMemo(
        () => JSON.stringify(values) !== JSON.stringify(props.values),
        [values, props.values],
    );
    const fields = sections.flatMap((section) => section.fields);
    const shown = visibleKeys(fields, values);
    const heading =
        id === null
            ? __('New :item', { item: record.label.toLowerCase() })
            : title !== ''
              ? title
              : record.label;
    const visibleSections = sections.filter((section) =>
        section.fields.some((field) => shown.has(field.key)),
    );
    const lastSectionId = visibleSections.at(-1)?.id ?? null;

    const approval = useScriptApproval(plugin.code);
    const scripts = {
        waiting: props.scripts.waiting,
        approve: props.scripts.approver ? approval.approve : undefined,
        busy: approval.busy,
    };

    const waitingCount = Object.values(props.scripts.waiting).reduce(
        (sum, list) => sum + list.length,
        0,
    );

    setLayoutProps({
        breadcrumbs: [
            { title: area.label, href: back },
            { title: heading, href: page.url },
        ],
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        // A hidden field's value is never sent (A2): it keeps what is stored.
        const sent = Object.fromEntries(
            Object.entries(values).filter(([key]) => shown.has(key)),
        );

        router.post(
            PluginAreaController.save.url({
                code: plugin.code,
                record: record.id,
                id: id ?? undefined,
            }),
            { values: sent } as RequestPayload,
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const remove = () =>
        router.post(
            PluginAreaController.act.url({
                code: plugin.code,
                record: record.id,
            }),
            { action: 'delete', ids: [id], confirmed: true, leave: true },
            { onFinish: () => setDeleting(false) },
        );

    return (
        <PluginAreaPage
            plugin={{ name: plugin.name, icon: plugin.icon }}
            title={heading}
            showTitle
            actions={
                <Button asChild variant="neutral">
                    <Link href={back} data-test="record-all">
                        {__('All :plural', {
                            plural: record.plural.toLowerCase(),
                        })}
                    </Link>
                </Button>
            }
        >
            <Head title={`${heading} · ${plugin.name}`} />
            {sections.length > 3 && (
                <SectionTabs
                    label={__('Sections of this form')}
                    sections={sections.map((section) => ({
                        id: `record-section-${section.id}`,
                        label: section.label,
                    }))}
                />
            )}
            {approval.password}
            {waitingCount > 0 && (
                <Notice
                    tone="warn"
                    className="mb-4"
                    data-test="record-scripts-waiting"
                    title={__n(
                        ':count script waits for approval|:count scripts wait for approval',
                        waitingCount,
                    )}
                >
                    {props.scripts.approver
                        ? __(
                              'It stays off the site’s pages until someone approves it. Approve it under the code field that holds it.',
                          )
                        : __(
                              'It stays off the site’s pages until someone who can change the site’s code approves it.',
                          )}
                </Notice>
            )}
            {props.hosts.length > 0 && (
                <RecordHostNotices
                    code={plugin.code}
                    plugin={plugin.name}
                    hosts={props.hosts}
                />
            )}
            <AreaScriptsProvider value={scripts}>
                <form onSubmit={submit} noValidate data-test="record-form">
                    {(failed !== null || errors.record !== undefined) && (
                        <Notice
                            tone="danger"
                            className="mb-4"
                            data-test="record-refused"
                        >
                            {errors.record ?? failed}
                        </Notice>
                    )}
                    {visibleSections.map((section) => {
                        const visible = section.fields.filter((field) =>
                            shown.has(field.key),
                        );
                        const isLast = section.id === lastSectionId;

                        return (
                            <div
                                key={section.id}
                                id={`record-section-${section.id}`}
                                tabIndex={-1}
                                className="scroll-mt-16 [&+&]:mt-4"
                            >
                                <FormPanel
                                    title={
                                        sections.length > 1 ||
                                        section.label !== ''
                                            ? section.label
                                            : undefined
                                    }
                                    description={
                                        section.description !== ''
                                            ? section.description
                                            : undefined
                                    }
                                >
                                    {visible.map((field) => (
                                        <AreaFieldInput
                                            key={field.key}
                                            field={field}
                                            value={values[field.key]}
                                            onChange={(next) =>
                                                setValues((now) => ({
                                                    ...now,
                                                    [field.key]: next,
                                                }))
                                            }
                                            errors={errors}
                                            path={`values.${field.key}`}
                                        />
                                    ))}
                                    {isLast && id !== null && deletable && (
                                        <div className="pt-2">
                                            <Button
                                                type="button"
                                                variant="danger-quiet"
                                                size="sm"
                                                data-test="record-delete"
                                                onClick={() =>
                                                    setDeleting(true)
                                                }
                                            >
                                                {__('Delete :item', {
                                                    item: record.label.toLowerCase(),
                                                })}
                                            </Button>
                                        </div>
                                    )}
                                </FormPanel>
                            </div>
                        );
                    })}
                    {visibleSections.length === 0 &&
                        id !== null &&
                        deletable && (
                            <FormPanel>
                                <Button
                                    type="button"
                                    variant="danger-quiet"
                                    size="sm"
                                    data-test="record-delete"
                                    onClick={() => setDeleting(true)}
                                >
                                    {__('Delete :item', {
                                        item: record.label.toLowerCase(),
                                    })}
                                </Button>
                            </FormPanel>
                        )}
                    <SaveBar
                        dirty={dirty}
                        processing={processing}
                        onDiscard={() => setValues(props.values)}
                        what={heading}
                    />
                </form>
            </AreaScriptsProvider>
            <Dialog open={deleting} onOpenChange={setDeleting}>
                <DialogContent size="sm" data-test="record-delete-confirm">
                    <DialogHeader>
                        <DialogTitle>
                            {__('Delete “:title”?', { title: heading })}
                        </DialogTitle>
                        <DialogDescription>
                            {__(
                                ':plugin deletes it, and this can’t be undone.',
                                {
                                    plugin: plugin.name,
                                },
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="neutral"
                            onClick={() => setDeleting(false)}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button
                            type="button"
                            variant="danger"
                            data-test="record-delete-go"
                            onClick={remove}
                        >
                            {__('Delete')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </PluginAreaPage>
    );
}
