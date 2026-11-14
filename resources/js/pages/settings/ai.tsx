import { Form, Head } from '@inertiajs/react';
import { Info } from 'lucide-react';
import AiSettingsController from '@/actions/App/Http/Admin/Settings/AiSettingsController';
import { MonthFigures } from '@/components/ai-settings/month-figures';
import { ServicePanel } from '@/components/ai-settings/service-panel';
import { TaskRow } from '@/components/ai-settings/task-row';
import { SettingsField } from '@/components/fields/settings-field';
import { SettingsPanel } from '@/components/fields/settings-panel';
import { SettingsSaveBar } from '@/components/fields/settings-save-bar';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import type { AiSettingsPage } from '@/types/ai-settings';

/**
 * Settings › AI (I-1308): each AI service with its key, a model and fallback
 * for each task, the limits with this month's spend, and one Save. While the
 * server has AI switched off, a notice and nothing else.
 */
export default function AiSettings(props: AiSettingsPage) {
    const firstService = props.enabled
        ? props.sections.findIndex((section) => section.kind === 'service')
        : -1;

    return (
        <>
            <Head title="AI settings" />

            <div className="grid max-w-[960px] gap-6">
                <Heading
                    level={1}
                    title="AI"
                    description={props.enabled ? props.description : undefined}
                />

                {!props.enabled ? (
                    <div
                        role="note"
                        data-test="ai-off"
                        className="border-border flex gap-3 rounded-md border p-4 text-sm"
                    >
                        <Info
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0"
                        />
                        <p>
                            <strong className="font-medium">
                                AI is switched off on this server.
                            </strong>{' '}
                            Nobody is offered AI and nothing is sent to an AI
                            service. These settings can’t be changed until your
                            host switches it on again; they’re kept as they
                            were.
                        </p>
                    </div>
                ) : (
                    <Form
                        {...AiSettingsController.update.form()}
                        options={{
                            preserveScroll: true,
                            preserveState: 'errors',
                        }}
                        className="grid gap-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="expected_version"
                                    value={props.version}
                                />
                                <InputError message={errors.expected_version} />

                                {!props.anyTaskOn && (
                                    <div
                                        role="note"
                                        data-test="ai-none-on"
                                        className="border-border flex gap-3 rounded-md border p-4 text-sm"
                                    >
                                        <Info
                                            aria-hidden="true"
                                            className="mt-0.5 size-4 shrink-0"
                                        />
                                        <p>
                                            <strong className="font-medium">
                                                No AI task is on yet.
                                            </strong>{' '}
                                            Set a key for one of the services
                                            below and save, then choose a model
                                            for each task under Models.
                                        </p>
                                    </div>
                                )}

                                {props.sections.map((section, index) => {
                                    if (section.kind === 'service') {
                                        if (index !== firstService) {
                                            return null;
                                        }

                                        return (
                                            <SettingsPanel
                                                key="services"
                                                as="section"
                                                title="AI services"
                                                description="Each service needs a key from your account with that company. A task’s text goes only to the service whose model the task uses."
                                            >
                                                {props.sections
                                                    .filter(
                                                        (candidate) =>
                                                            candidate.kind ===
                                                            'service',
                                                    )
                                                    .map((service) => (
                                                        <ServicePanel
                                                            key={`service-${service.key}`}
                                                            service={service}
                                                            values={
                                                                props.values
                                                            }
                                                            secrets={
                                                                props.secrets
                                                            }
                                                            media={props.media}
                                                            errors={errors}
                                                        />
                                                    ))}
                                            </SettingsPanel>
                                        );
                                    }

                                    if (section.kind === 'models') {
                                        return (
                                            <SettingsPanel
                                                key={`models-${section.key}`}
                                                as="section"
                                                title={section.label}
                                                description="Choose the model each task uses. A task with no model is off, and nobody sees it. The fallback is asked once when the first model can’t be reached or is busy."
                                            >
                                                {section.tasks.length === 0 ? (
                                                    <p className="text-muted-foreground text-sm">
                                                        No AI task is installed
                                                        yet.
                                                    </p>
                                                ) : (
                                                    section.tasks.map(
                                                        (task) => (
                                                            <TaskRow
                                                                key={task.key}
                                                                task={task}
                                                                values={
                                                                    props.values
                                                                }
                                                                errors={errors}
                                                            />
                                                        ),
                                                    )
                                                )}
                                            </SettingsPanel>
                                        );
                                    }

                                    return (
                                        <SettingsPanel
                                            key={`${section.kind}-${section.key}`}
                                            as="section"
                                            title={section.label}
                                            description={
                                                section.kind === 'limits'
                                                    ? 'How often one person may ask, and how much the site may spend in a month.'
                                                    : undefined
                                            }
                                        >
                                            {section.fields.map((field) => (
                                                <SettingsField
                                                    key={field.key}
                                                    field={field}
                                                    value={
                                                        props.values[field.key]
                                                    }
                                                    secret={
                                                        props.secrets[field.key]
                                                    }
                                                    media={
                                                        props.media[
                                                            field.key
                                                        ] ?? null
                                                    }
                                                    error={
                                                        errors[
                                                            `values.${field.key}`
                                                        ] ??
                                                        errors[
                                                            `secrets.${field.key}`
                                                        ]
                                                    }
                                                />
                                            ))}
                                            {section.kind === 'limits' && (
                                                <MonthFigures
                                                    month={props.month}
                                                    activityUrl={
                                                        props.activityUrl
                                                    }
                                                />
                                            )}
                                        </SettingsPanel>
                                    );
                                })}

                                <SettingsSaveBar
                                    label="Save AI settings"
                                    processing={processing}
                                    testId="ai-settings-save"
                                />
                                <div className="space-y-2">
                                    <p className="text-muted-foreground text-sm">
                                        Saving may ask for your password. If it
                                        does, you’ll come back here to save
                                        again, and any key you typed must be
                                        typed again.
                                    </p>
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}
