import { Head, setLayoutProps } from '@inertiajs/react';
import type { CSSProperties } from 'react';
import AiSettingsController from '@/actions/App/Http/Admin/Settings/AiSettingsController';
import { MonthFigures } from '@/components/ai-settings/month-figures';
import { ServicePanel } from '@/components/ai-settings/service-panel';
import { TaskRow } from '@/components/ai-settings/task-row';
import { SettingsField } from '@/components/fields/settings-field';
import { SettingsForm } from '@/components/fields/settings-form';
import { SettingsPanel } from '@/components/fields/settings-panel';
import InputError from '@/components/input-error';
import { SettingsPage } from '@/components/patterns/settings-page';
import { Notice } from '@/components/ui/notice';
import { formatCost } from '@/lib/ai-cost';
import { __, __n } from '@/lib/i18n';
import type { FieldDefinition, SecretStatus } from '@/types';
import type { AiPluginRow, AiSettingsPage } from '@/types/ai-settings';

/** The month's figures in two columns from the small breakpoint up. */
const FACTS = { '--facts-cols': 'max-content 1fr' } as CSSProperties;

/**
 * One plugin's AI on Settings › AI: its name once, in the panel's header;
 * its switch, budget and hourly limit; this month's figures; and its tasks'
 * model and fallback rows, each saying what it sends.
 */
function PluginPanel({
    plugin,
    fields,
    values,
    secrets,
    errors,
}: {
    plugin: AiPluginRow;
    fields: FieldDefinition[];
    values: Record<string, unknown>;
    secrets: Record<string, SecretStatus>;
    errors: Record<string, string>;
}) {
    // The section's own words, without the plugin's name, which the header already says.
    const words: Record<string, { label: string; description: string }> = {
        [plugin.onField]: {
            label: __('AI on'),
            description: __(
                'Lets the plugin ask the site’s AI connection for its tasks. Switched off, it can’t ask; its settings are kept.',
            ),
        },
        [plugin.budgetField]: {
            label: __('Monthly budget, in US dollars'),
            description: __(
                'When this month’s cost reaches it, the plugin can’t ask until the month ends. 0 means only the site’s budget applies.',
            ),
        },
        [plugin.hourlyField]: {
            label: __('Requests an hour'),
            description: __(
                'How many times the plugin may ask in an hour, for everyone it works for.',
            ),
        },
    };
    const controls = [plugin.onField, plugin.budgetField, plugin.hourlyField]
        .map((key) => fields.find((field) => field.key === key))
        .filter((field): field is FieldDefinition => field !== undefined);
    const month = plugin.month;

    return (
        <SettingsPanel
            as="section"
            title={plugin.label}
            description={__(
                'Its requests are recorded under its name in Tools › AI activity, and they count toward the site’s budget.',
            )}
        >
            <div data-test={`ai-plugin-${plugin.code}`} className="grid gap-5">
                <dl
                    data-test={`ai-plugin-month-${plugin.code}`}
                    className="text-small grid gap-x-6 gap-y-2 sm:grid-cols-(--facts-cols)"
                    style={FACTS}
                >
                    <dt className="text-ink-2">{__('This month')}</dt>
                    <dd>
                        {__n(
                            ':cost, :count request|:cost, :count requests',
                            month.requests,
                            {
                                cost: formatCost(month.costMicrounits),
                                count: month.requests.toLocaleString(),
                            },
                        )}
                    </dd>
                    <dt className="text-ink-2">{__('Budget')}</dt>
                    <dd>
                        {plugin.budgetMicrounits === null
                            ? __('The site’s budget only')
                            : __(':budget a month', {
                                  budget: formatCost(plugin.budgetMicrounits),
                              })}
                    </dd>
                </dl>

                {controls.map((field) => (
                    <SettingsField
                        key={field.key}
                        field={{ ...field, ...words[field.key] }}
                        value={values[field.key]}
                        secret={secrets[field.key]}
                        media={null}
                        error={errors[`values.${field.key}`]}
                    />
                ))}

                {plugin.tasks.map((task) => (
                    <TaskRow
                        key={task.key}
                        task={{
                            ...task,
                            key: `${plugin.code}-${task.key}`,
                            note: [
                                task.note,
                                __('Sends :what.', { what: task.sendsWords }),
                            ]
                                .filter((line) => line !== null)
                                .join(' '),
                        }}
                        values={values}
                        errors={errors}
                    />
                ))}
            </div>
        </SettingsPanel>
    );
}

/**
 * Settings › AI (I-1308): each AI service with its key, a model and fallback
 * for each task, the limits with this month's spend, and the one save bar,
 * asking for the password in place. While the
 * server has AI switched off, a notice and nothing else.
 */
export default function AiSettings(props: AiSettingsPage) {
    const firstService = props.enabled
        ? props.sections.findIndex((section) => section.kind === 'service')
        : -1;

    setLayoutProps({
        breadcrumbs: [
            { title: __('AI'), href: AiSettingsController.show.url() },
        ],
    });

    return (
        <>
            <Head title="AI settings" />

            <SettingsPage
                title="AI"
                description={props.enabled ? props.description : undefined}
            >
                {!props.enabled ? (
                    <Notice tone="warn" data-test="ai-off">
                        <p>
                            <strong className="font-medium">
                                AI is switched off on this server.
                            </strong>{' '}
                            Nobody is offered AI and nothing is sent to an AI
                            service. These settings can’t be changed until your
                            host switches it on again; they’re kept as they
                            were.
                        </p>
                    </Notice>
                ) : (
                    <SettingsForm
                        action={AiSettingsController.update.form()}
                        saveLabel={__('Save AI settings')}
                        saveTestId="ai-settings-save"
                        what={__(':page settings', { page: __('AI') })}
                        askInPlace
                        options={{ preserveState: 'errors' }}
                    >
                        {({ errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="expected_version"
                                    value={props.version}
                                />
                                <InputError
                                    id="expected-version-error"
                                    message={errors.expected_version}
                                />

                                {!props.anyTaskOn && (
                                    <Notice tone="info" data-test="ai-none-on">
                                        <p>
                                            <strong className="font-medium">
                                                No AI task is on yet.
                                            </strong>{' '}
                                            Set a key for one of the services
                                            below and save, then choose a model
                                            for each task under Models.
                                        </p>
                                    </Notice>
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
                                                    <p className="text-small text-ink-2">
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

                                    if (section.kind === 'plugins') {
                                        return section.plugins.map((plugin) => (
                                            <PluginPanel
                                                key={`plugin-${plugin.code}`}
                                                plugin={plugin}
                                                fields={section.fields}
                                                values={props.values}
                                                secrets={props.secrets}
                                                errors={errors}
                                            />
                                        ));
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
                            </>
                        )}
                    </SettingsForm>
                )}
            </SettingsPage>
        </>
    );
}
