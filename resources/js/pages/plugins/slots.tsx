import { Head, router, setLayoutProps } from '@inertiajs/react';
import PluginSlotsController from '@/actions/App/Http/Admin/PluginSlotsController';
import { ListPage } from '@/components/patterns/list-page';
import { Button } from '@/components/ui/button';
import { Panel } from '@/components/ui/panel';
import { SelectField } from '@/components/ui/select-field';
import { useSectionCrumb } from '@/lib/crumbs';
import { __ } from '@/lib/i18n';
import {
    index as pluginsIndex,
    slots as providerChoices,
} from '@/routes/plugins';

type SlotProvider = { name: string; label: string };

type Slot = {
    id: string;
    label: string;
    chosen: string | null;
    notice: string | null;
    providers: SlotProvider[];
};

/**
 * The provider choices: one row per slot, with the plugin that fills it. A
 * slot with no enabled plugin says so, and core's default stands.
 */
export default function PluginSlots({ slots }: { slots: Slot[] }) {
    const siteCrumb = useSectionCrumb('site');
    const title = __('Provider choices');

    setLayoutProps({
        breadcrumbs: [
            siteCrumb,
            { title: __('Plugins'), href: pluginsIndex.url() },
            { title, href: providerChoices.url() },
        ],
    });

    return (
        <>
            <Head title={title} />

            <ListPage
                title={title}
                description={__(
                    'Where a service needs exactly one provider, choose the plugin that fills it. With one enabled plugin, that plugin is used by itself. Without a choice, Teme’s own provider stays in place.',
                )}
            >
                <Panel className="divide-line divide-y overflow-hidden">
                    {slots.map((slot) => (
                        <div
                            key={slot.id}
                            className="flex items-center gap-4 px-4 py-3"
                            data-test={`slot-${slot.id}`}
                        >
                            <div className="min-w-0 flex-1">
                                <p className="font-medium">{slot.label}</p>
                                {slot.notice && (
                                    <p className="text-small text-ink-2">
                                        {slot.notice}
                                    </p>
                                )}
                                {slot.providers.length === 0 && (
                                    <p className="text-small text-ink-3">
                                        {__(
                                            'No enabled plugin fills this yet.',
                                        )}
                                    </p>
                                )}
                            </div>
                            {slot.id === 'search' && slot.chosen && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            PluginSlotsController.reindex.url(),
                                        )
                                    }
                                >
                                    {__('Send every item again')}
                                </Button>
                            )}
                            <SelectField
                                aria-label={slot.label}
                                emptyLabel={__('Teme’s own')}
                                value={slot.chosen ?? ''}
                                options={slot.providers.map((provider) => ({
                                    value: provider.name,
                                    label: provider.label,
                                }))}
                                onValueChange={(value) =>
                                    router.post(
                                        PluginSlotsController.choose.url({
                                            slot: slot.id,
                                        }),
                                        { plugin: value },
                                    )
                                }
                            />
                        </div>
                    ))}
                </Panel>
            </ListPage>
        </>
    );
}
