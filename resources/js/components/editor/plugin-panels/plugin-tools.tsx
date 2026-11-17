import { Plug } from 'lucide-react';
import type { EditorTool } from '@/components/editor/tools/editor-tools';
import type { EditorPluginPanel } from '@/types';
import type { PanelApply } from './panel-suggestions';
import { PANEL_ICONS, PluginPanel } from './plugin-panel';

/**
 * The plugins' panels as tools in the editor's bar: each owned by its
 * plugin, so Tools lists it under the plugins and its pane carries the
 * plugin's mark, and each pinnable.
 */
export function pluginTools(
    panels: EditorPluginPanel[],
    contentId: string,
    apply: PanelApply,
): EditorTool[] {
    return panels.map((panel) => ({
        id: panel.tool,
        owner: panel.plugin,
        label: panel.label,
        icon: PANEL_ICONS[panel.icon] ?? Plug,
        size: 'pane',
        available: true,
        render: () => (
            <PluginPanel panel={panel} contentId={contentId} apply={apply} />
        ),
    }));
}
