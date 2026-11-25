import type { RequestPayload } from '@inertiajs/core';
import { Head, router, setLayoutProps, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import PluginAreaController from '@/actions/App/Http/Admin/PluginAreaController';
import { FormPage, FormPanel } from '@/components/patterns/form-page';
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
import { __ } from '@/lib/i18n';
import { visibleKeys } from '@/lib/visible-when';
import type { PluginRecordPageProps } from '@/types/plugin-area';

/**
 * A record's form in a plugin's area (docs/plans/plugin-sockets-2.md, A9):
 * its sections as panels, a field shown only while its condition holds,
 * and the one save bar. The top bar names the area and the record; the
 * record's title heads the page.
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

    const approval = useScriptApproval(plugin.code);
    const scripts = {
        waiting: props.scripts.waiting,
        approve: props.scripts.approver ? approval.approve : undefined,
        busy: approval.busy,
    };

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
        <FormPage title={heading} showTitle>
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
                    {sections.map((section) => {
                        const visible = section.fields.filter((field) =>
                            shown.has(field.key),
                        );

                        if (visible.length === 0) {
                            return null;
                        }

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
                                </FormPanel>
                            </div>
                        );
                    })}
                    {id !== null && deletable && (
                        <FormPanel
                            title={__('Delete this :item', {
                                item: record.label.toLowerCase(),
                            })}
                        >
                            <div>
                                <Button
                                    type="button"
                                    variant="danger"
                                    data-test="record-delete"
                                    onClick={() => setDeleting(true)}
                                >
                                    {__('Delete')}
                                </Button>
                            </div>
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
        </FormPage>
    );
}
