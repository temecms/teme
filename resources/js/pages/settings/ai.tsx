import { Head, setLayoutProps } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import AiSettingsController from '@/actions/App/Http/Admin/Settings/AiSettingsController';
import { MonthFigures } from '@/components/ai-settings/month-figures';
import { ServiceTabs } from '@/components/ai-settings/service-panel';
import { TaskRow } from '@/components/ai-settings/task-row';
import { SettingsField } from '@/components/fields/settings-field';
import { SettingsForm } from '@/components/fields/settings-form';
import { SettingsPanel } from '@/components/fields/settings-panel';
import InputError from '@/components/input-error';
import { SettingsPage } from '@/components/patterns/settings-page';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Notice } from '@/components/ui/notice';
import { formatCost } from '@/lib/ai-cost';
import { __, __n } from '@/lib/i18n';
import { index as settingsIndex } from '@/routes/settings';
import type { FieldDefinition, SecretStatus } from '@/types';
import type {
    AiPluginRow,
    AiServiceBlock,
    AiSettingsPage,
} from '@/types/ai-settings';

/**
 * One plugin on Settings › AI: its switch ("X may use AI"), this month's
 * spend against its budget, this hour's requests against its limit, and
 * Details, which holds its budget and limit fields and its own task models.
 * The details stay mounted when closed, so every field still goes with Save.
 */
function PluginRow({
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
    const find = (key: string) => fields.find((field) => field.key === key);
    const on = find(plugin.onField);
    const details = [
        {
            field: find(plugin.budgetField),
            words: {
                label: __('Monthly budget, in US dollars'),
                description: __(
                    'When this month’s cost reaches it, the plugin can’t ask until the month ends. 0 means only the site’s budget applies.',
                ),
                unit: __('US dollars'),
            },
        },
        {
            field: find(plugin.hourlyField),
            words: {
                label: __('Requests an hour'),
                description: __(
                    'How many times the plugin may ask in an hour, for everyone it works for.',
                ),
                unit: __('requests'),
            },
        },
    ];
    const month = plugin.month;
    const spend =
        plugin.budgetMicrounits === null
            ? __(':cost this month, within the site’s budget', {
                  cost: formatCost(month.costMicrounits),
              })
            : __(':cost of :budget this month', {
                  cost: formatCost(month.costMicrounits),
                  budget: formatCost(plugin.budgetMicrounits),
              });

    return (
        <Collapsible
            data-test={`ai-plugin-${plugin.code}`}
            className="border-line grid gap-3 border-t pt-4 first:border-t-0 first:pt-0"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                {on !== undefined && (
                    <SettingsField
                        field={{
                            ...on,
                            label: __(':plugin may use AI', {
                                plugin: plugin.label,
                            }),
                            description: __(
                                'Its requests are recorded under its name in Tools › AI activity, and they count toward the site’s budget.',
                            ),
                        }}
                        value={values[on.key]}
                        secret={secrets[on.key]}
                        media={null}
                        error={errors[`values.${on.key}`]}
                    />
                )}
                <CollapsibleTrigger
                    className="text-small text-accent-ink group focus-visible:outline-ring flex cursor-pointer items-center gap-1 rounded-md px-2 py-1 font-medium outline-none hover:underline focus-visible:outline-2 focus-visible:outline-offset-2"
                    data-test={`ai-plugin-details-${plugin.code}`}
                >
                    {__('Details')}
                    <ChevronDown
                        aria-hidden="true"
                        className="size-4 transition-transform group-data-[state=open]:rotate-180"
                    />
                </CollapsibleTrigger>
            </div>
            <dl
                data-test={`ai-plugin-month-${plugin.code}`}
                className="text-small text-ink-2 flex flex-wrap gap-x-6 gap-y-1"
            >
                <div>
                    <dt className="sr-only">{__('This month')}</dt>
                    <dd>
                        {spend}
                        {', '}
                        {__n(':count request|:count requests', month.requests, {
                            count: month.requests.toLocaleString(),
                        })}
                    </dd>
                </div>
                <div>
                    <dt className="sr-only">{__('This hour')}</dt>
                    <dd>
                        {__(':count of :limit requests this hour', {
                            count: plugin.requestsThisHour.toLocaleString(),
                            limit: plugin.hourly.toLocaleString(),
                        })}
                    </dd>
                </div>
            </dl>
            <CollapsibleContent forceMount>
                <div className="grid gap-4 in-data-[state=closed]:hidden">
                    {details.map(({ field, words }) =>
                        field === undefined ? null : (
                            <SettingsField
                                key={field.key}
                                field={{ ...field, ...words }}
                                value={values[field.key]}
                                secret={secrets[field.key]}
                                media={null}
                                error={errors[`values.${field.key}`]}
                            />
                        ),
                    )}
                    {plugin.tasks.length > 0 && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            {plugin.tasks.map((task) => (
                                <TaskRow
                                    key={task.key}
                                    task={{
                                        ...task,
                                        key: `${plugin.code}-${task.key}`,
                                    }}
                                    note={[
                                        task.note,
                                        __('Sends :what.', {
                                            what: task.sendsWords,
                                        }),
                                    ]
                                        .filter((line) => line !== null)
                                        .join(' ')}
                                    values={values}
                                    errors={errors}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

/**
 * Settings › AI (I-1308): the services as tabs with a key row each, a model
 * and fallback for each task, the plugins that use AI, the limits with this
 * month's figures, and the one save bar, asking for the password in place.
 * While the server has AI switched off, a notice and nothing else.
 */
export default function AiSettings(props: AiSettingsPage) {
    setLayoutProps({
        breadcrumbs: [
            { title: __('Settings'), href: settingsIndex() },
            { title: __('AI'), href: AiSettingsController.show.url() },
        ],
    });

    return (
        <>
            <Head title={__('AI settings')} />

            <SettingsPage
                title={__('AI')}
                description={props.enabled ? props.description : undefined}
            >
                {!props.enabled ? (
                    <Notice tone="warn" data-test="ai-off">
                        <p>
                            <strong className="font-medium">
                                {__('AI is switched off on this server.')}
                            </strong>{' '}
                            {__(
                                'Nobody is offered AI and nothing is sent to an AI service. These settings can’t be changed until your host switches it on again; they’re kept as they were.',
                            )}
                        </p>
                    </Notice>
                ) : (
                    <AiForm {...props} />
                )}
            </SettingsPage>
        </>
    );
}

/** The form of Settings › AI, while AI is on. */
function AiForm(props: Extract<AiSettingsPage, { enabled: true }>) {
    const services = props.sections.filter(
        (section): section is AiServiceBlock => section.kind === 'service',
    );
    const firstService = props.sections.findIndex(
        (section) => section.kind === 'service',
    );

    return (
        <SettingsForm
            action={AiSettingsController.update.form()}
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
                                    {__('No AI task is on yet.')}
                                </strong>{' '}
                                {__(
                                    'Set a key for one of the services below and save, then choose a model for each task under Models.',
                                )}
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
                                    title={__('AI services')}
                                    description={__(
                                        'Each service needs a key from your account with that company. A task’s text goes only to the service whose model the task uses.',
                                    )}
                                >
                                    <ServiceTabs
                                        services={services}
                                        values={props.values}
                                        secrets={props.secrets}
                                        media={props.media}
                                        errors={errors}
                                    />
                                </SettingsPanel>
                            );
                        }

                        if (section.kind === 'models') {
                            return (
                                <SettingsPanel
                                    key={`models-${section.key}`}
                                    as="section"
                                    title={section.label}
                                    description={__(
                                        'Choose the model each task uses. A task with no model is off, and nobody sees it. The fallback is asked once when the first model can’t be reached or is busy.',
                                    )}
                                >
                                    {section.tasks.length === 0 ? (
                                        <p className="text-small text-ink-2">
                                            {__('No AI task is installed yet.')}
                                        </p>
                                    ) : (
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            {section.tasks.map((task) => (
                                                <TaskRow
                                                    key={task.key}
                                                    task={task}
                                                    values={props.values}
                                                    errors={errors}
                                                />
                                            ))}
                                        </div>
                                    )}
                                </SettingsPanel>
                            );
                        }

                        if (section.kind === 'plugins') {
                            return (
                                <SettingsPanel
                                    key={`plugins-${section.key}`}
                                    as="section"
                                    title={section.label}
                                >
                                    {section.plugins.map((plugin) => (
                                        <PluginRow
                                            key={`plugin-${plugin.code}`}
                                            plugin={plugin}
                                            fields={section.fields}
                                            values={props.values}
                                            secrets={props.secrets}
                                            errors={errors}
                                        />
                                    ))}
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
                                        ? __(
                                              'How often one person may ask, and how much the site may spend in a month.',
                                          )
                                        : undefined
                                }
                            >
                                {section.fields.map((field) => (
                                    <SettingsField
                                        key={field.key}
                                        field={field}
                                        value={props.values[field.key]}
                                        secret={props.secrets[field.key]}
                                        media={props.media[field.key] ?? null}
                                        error={
                                            errors[`values.${field.key}`] ??
                                            errors[`secrets.${field.key}`]
                                        }
                                    />
                                ))}
                                {section.kind === 'limits' && (
                                    <MonthFigures
                                        month={props.month}
                                        mostUsedTask={props.mostUsedTask}
                                    />
                                )}
                            </SettingsPanel>
                        );
                    })}
                </>
            )}
        </SettingsForm>
    );
}
