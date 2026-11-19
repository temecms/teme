import { Link } from '@inertiajs/react';
import { PluginMark } from '@/components/ui/plugin-mark';
import type { JobRow } from '@/types/jobs';

/**
 * A job's name on Tools › Jobs, with the plugin mark when the job belongs
 * to a plugin (I-3445), and the line saying what it worked on, linked when
 * that has a page.
 */
export function JobLabel({
    job,
}: {
    job: Pick<JobRow, 'label' | 'plugin'> & {
        context?: { label: string; href: string | null } | null;
    };
}) {
    const context = job.context ?? null;

    return (
        <div className="grid justify-items-start gap-1">
            <span className="font-semibold">{job.label}</span>
            {job.plugin !== null && <PluginMark name={job.plugin} />}
            {context !== null &&
                (context.href !== null ? (
                    <Link
                        href={context.href}
                        className="text-small text-ink-3 hover:text-ink underline underline-offset-2"
                    >
                        {context.label}
                    </Link>
                ) : (
                    <span className="text-small text-ink-3">
                        {context.label}
                    </span>
                ))}
        </div>
    );
}
