import { Copy } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { __ } from '@/lib/i18n';
import type { ExtensionCommands } from '@/types';

/** One command as it is typed, with a button that copies it. */
function Command({ label, command }: { label: string; command: string }) {
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(command);
            toast.success(__('Copied the command.'));
        } catch {
            toast.error(
                __(
                    'The command could not be copied. Select it and copy it by hand.',
                ),
            );
        }
    };

    return (
        <li className="grid min-w-0 gap-1">
            <p className="text-small text-ink-2">{label}</p>
            <div className="flex min-w-0 items-start gap-2">
                <code className="bg-surface-2 text-small min-w-0 flex-1 rounded-sm px-2 py-2 font-mono break-all">
                    {command}
                </code>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    onClick={() => void copy()}
                    aria-label={__('Copy: :label', { label })}
                    data-test="extension-command-copy"
                >
                    <Copy aria-hidden="true" />
                </Button>
            </div>
        </li>
    );
}

/**
 * With no installer running: the exact commands that carry the request out on
 * the server, or add the package to the site's own build instead.
 */
export function CommandsBox({ commands }: { commands: ExtensionCommands }) {
    return (
        <section
            data-test="extension-commands"
            className="border-line grid gap-3 rounded-md border p-4"
            aria-labelledby="extension-commands-title"
        >
            <h3
                id="extension-commands-title"
                className="text-body font-semibold"
            >
                {__('No installer is running')}
            </h3>
            <p className="text-small text-ink-2">
                {__(
                    'The request is recorded and waits. Someone with access to the server carries it out with one of these:',
                )}
            </p>
            <ul className="grid gap-3">
                {commands.docker !== null && (
                    <Command
                        label={__('With Docker Compose')}
                        command={commands.docker}
                    />
                )}
                {commands.server !== null && (
                    <Command
                        label={__('On a server without Docker')}
                        command={commands.server}
                    />
                )}
                {commands.build !== null && (
                    <Command
                        label={__(
                            'Or add it to the site’s own build, deploy, then cancel this request',
                        )}
                        command={commands.build}
                    />
                )}
            </ul>
        </section>
    );
}
