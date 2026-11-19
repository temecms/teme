import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import type { SecretStatus } from '@/types';

/**
 * A secret setting's control: its state as a pill (Saved, Not set, or Set by
 * the server) and a button that reveals the password field. The field is
 * only in the form once asked for, so a save without it keeps the secret.
 */
export function SecretControl({
    id,
    name,
    describedBy,
    secret,
    disabled,
}: {
    id: string;
    name: string;
    describedBy?: string;
    secret?: SecretStatus;
    disabled?: boolean;
}) {
    const [asking, setAsking] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const fromServer = secret?.source === 'environment';

    useEffect(() => {
        if (asking) {
            input.current?.focus();
        }
    }, [asking]);

    return (
        <div className="grid gap-2">
            <div className="flex flex-wrap items-center gap-2">
                {fromServer ? (
                    <StatusPill tone="info">
                        {__('Set by the server')}
                    </StatusPill>
                ) : secret?.isSet ? (
                    <StatusPill tone="ok">{__('Saved')}</StatusPill>
                ) : (
                    <StatusPill tone="warn">{__('Not set')}</StatusPill>
                )}
                {!asking && (
                    <Button
                        type="button"
                        id={id}
                        variant="secondary"
                        size="sm"
                        aria-describedby={describedBy}
                        disabled={disabled || fromServer}
                        onClick={() => setAsking(true)}
                    >
                        {secret?.isSet ? __('Replace key') : __('Set key')}
                    </Button>
                )}
            </div>
            {asking && (
                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        ref={input}
                        id={id}
                        aria-describedby={describedBy}
                        type="password"
                        name={name}
                        autoComplete="off"
                        spellCheck={false}
                        className="min-w-64 flex-1 font-mono"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setAsking(false)}
                    >
                        {__('Cancel')}
                    </Button>
                </div>
            )}
            {fromServer && (
                <p className="text-caption text-ink-3">
                    {__('Provided by the server’s environment.')}
                </p>
            )}
        </div>
    );
}
