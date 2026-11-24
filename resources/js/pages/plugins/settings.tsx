import { Head, setLayoutProps } from '@inertiajs/react';
import PluginAreaController from '@/actions/App/Http/Admin/PluginAreaController';
import { PluginAreaPage } from '@/components/patterns/plugin-area-page';
import { __ } from '@/lib/i18n';
import SettingsGroup from '@/pages/settings/group';
import type { SettingsGroupPage } from '@/types';
import type { PluginAreaPageProps } from '@/types/plugin-area';

type PluginHosts = {
    plugin: string;
    settings: string[];
    agreed: string[];
    declared: string[];
};

type PluginScripts = {
    code: string;
    waiting: Record<string, { id: string; script: string }[]>;
    approver: boolean;
};

/**
 * A plugin's settings as the last tab of its area: the same form Settings
 * drew for plugin.{code}, inside the area's page chrome.
 */
export default function PluginAreaSettings(
    props: PluginAreaPageProps &
        SettingsGroupPage & {
            pluginHosts: PluginHosts | null;
            pluginScripts?: PluginScripts | null;
        },
) {
    const {
        plugin,
        area,
        tabs,
        parent,
        blocks: _blocks,
        asks: _asks,
        current: _current,
        ...settings
    } = props;

    setLayoutProps({
        breadcrumbs: parent === null ? [] : [parent],
    });

    return (
        <>
            <Head title={`${__('Settings')} · ${area.label}`} />
            <PluginAreaPage
                plugin={{ name: plugin.name }}
                title={area.label}
                showTitle
                description={area.description || undefined}
                pages={tabs.map((one) => ({
                    href:
                        one.href ??
                        PluginAreaController.show.url({
                            code: plugin.code,
                            page: one.id,
                        }),
                    label: one.label,
                    current: one.current,
                }))}
            >
                <SettingsGroup
                    {...(settings as SettingsGroupPage & {
                        pluginHosts: PluginHosts | null;
                        pluginScripts?: PluginScripts | null;
                    })}
                    bare
                />
            </PluginAreaPage>
        </>
    );
}
