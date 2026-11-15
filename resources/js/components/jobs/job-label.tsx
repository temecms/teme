import { PluginMark } from '@/components/ui/plugin-mark';
import type { JobRow } from '@/types/jobs';

/**
 * A job's name on Tools › Jobs, with the plugin mark when the job belongs
 * to a plugin (I-3445).
 */
export function JobLabel({ job }: { job: Pick<JobRow, 'label' | 'plugin'> }) {
    return (
        <div className="grid gap-1">
            <span className="font-medium">{job.label}</span>
            {job.plugin !== null && <PluginMark name={job.plugin} />}
        </div>
    );
}
