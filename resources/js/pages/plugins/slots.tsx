import { Head, router, usePage } from '@inertiajs/react';
import PluginSlotsController from '@/actions/App/Http/Admin/PluginSlotsController';
import { ListPage } from '@/components/patterns/list-page';
import { Panel } from '@/components/ui/panel';
import { SelectField } from '@/components/ui/select-field';
import { useSectionCrumb } from '@/lib/crumbs';
import { __ } from '@/lib/i18n';

type SlotProvider = { name: string; label: string };

type Slot = {
    id: string;
    label: string;
    chosen: string | null;
    providers: SlotProvider[];
};

/**
 * The provider choices: one row per slot, with the plugin that fills it. A
 * slot with no enabled plugin says so, and core's default stands.
 */
export default function PluginSlots({ slots }: { slots: Slot[] }) {
    useSectionCrumb('site');
    const { flash } = usePage<{ flash?: { status?: string } }>().props;

    return (
        <>
            <Head title={__('Provider choices')} />

            <ListPage
                title={__('Provider choices')}
                description={__(
                    'Where a service needs exactly one provider, choose the plugin that fills it. With one enabled plugin, that plugin is used by itself. Without a choice, Teme’s own provider stays in place.',
                )}
            >
                {flash?.status && (
                    <p role="status" className="text-small text-ink-2 mb-3">
                        {flash.status}
                    </p>
                )}

                <Panel className="divide-line divide-y overflow-hidden">
                    {slots.map((slot) => (
                        <div
                            key={slot.id}
                            className="flex items-center gap-4 px-4 py-3"
                            data-test={`slot-${slot.id}`}
                        >
                            <div className="min-w-0 flex-1">
                                <p className="font-medium">{slot.label}</p>
                                {slot.providers.length === 0 && (
                                    <p className="text-small text-ink-3">
                                        {__(
                                            'No enabled plugin fills this yet.',
                                        )}
                                    </p>
                                )}
                            </div>
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
