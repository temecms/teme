import { useState } from 'react';
import type { ReactNode } from 'react';
import ExtensionsController from '@/actions/App/Http/Admin/ExtensionsController';
import { AddSheet, flashed } from '@/components/extensions/add-sheet';
import type { Installed } from '@/components/extensions/add-sheet';
import { RequestSheet } from '@/components/extensions/request-sheet';
import { useAsk } from '@/components/extensions/use-ask';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { __ } from '@/lib/i18n';
import type { ExtensionKind, ExtensionsData } from '@/types';

/** One installed package a row asks to update or remove. */
export type Change = {
    action: 'update' | 'remove';
    kind: ExtensionKind;
    package: string;
    label: string;
    version: string;
};

/**
 * Update… and Remove…: what the request does, then the button that asks.
 * Update takes an optional version; Remove keeps the package's data.
 */
function ChangeDialog({
    change,
    onClose,
    onRequested,
}: {
    change: Change | null;
    onClose: () => void;
    onRequested: (id: string) => void;
}) {
    const { ask, busy, password } = useAsk();
    const [constraint, setConstraint] = useState('');

    const submit = (current: Change) =>
        ask({
            url: ExtensionsController.store.url(),
            data: {
                action: current.action,
                kind: current.kind,
                package: current.package,
                constraint:
                    current.action === 'update' && constraint !== ''
                        ? constraint
                        : null,
            },
            onFlash: (flash) => {
                const id = flashed(flash, 'extensionRequest');

                if (typeof id === 'string') {
                    setConstraint('');
                    onRequested(id);
                }
            },
        });

    return (
        <Dialog
            open={change !== null}
            onOpenChange={(next) => !next && onClose()}
        >
            {change !== null && (
                <DialogContent data-test="extension-change-dialog">
                    <DialogHeader>
                        <DialogTitle>
                            {change.action === 'update'
                                ? __('Update :name?', { name: change.label })
                                : __('Remove :name?', { name: change.label })}
                        </DialogTitle>
                        <DialogDescription>
                            {change.action === 'update'
                                ? __(
                                      'The installer fetches the newer version into a new copy and switches over only when every check passes. :version stays until then.',
                                      { version: change.version },
                                  )
                                : change.kind === 'plugin'
                                  ? __(
                                        'The installer takes its code out of the site. Its data stays until you remove it.',
                                    )
                                  : __(
                                        'The installer takes the theme out of the site. Its settings stay.',
                                    )}
                        </DialogDescription>
                    </DialogHeader>
                    {change.action === 'update' && (
                        <Field
                            htmlFor="extension-change-version"
                            label={__('Version')}
                            help={__(
                                'Leave it empty for the newest that fits what was asked for before.',
                            )}
                        >
                            <Input
                                id="extension-change-version"
                                value={constraint}
                                spellCheck={false}
                                autoComplete="off"
                                className="font-mono"
                                onChange={(event) =>
                                    setConstraint(event.target.value.trim())
                                }
                            />
                        </Field>
                    )}
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="neutral">
                                {__('Cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            variant={
                                change.action === 'remove'
                                    ? 'destructive'
                                    : 'primary'
                            }
                            loading={busy}
                            data-test="extension-change-confirm"
                            onClick={() => submit(change)}
                        >
                            {change.action === 'update'
                                ? __('Ask to update')
                                : __('Ask to remove')}
                        </Button>
                    </DialogFooter>
                    {password}
                </DialogContent>
            )}
        </Dialog>
    );
}

/**
 * Installing from the admin on one screen (I-211): the Add sheet, the
 * Update… and Remove… dialog, and the request sheet each of them opens once
 * the request is recorded. Null data, for someone who may not install,
 * draws nothing.
 */
export function useInstaller(
    data: ExtensionsData | null,
    kind: ExtensionKind,
    installed: Installed[],
): {
    openAdd: () => void;
    openChange: (change: Change) => void;
    openRequest: (id: string) => void;
    sheets: ReactNode;
} {
    const [adding, setAdding] = useState(false);
    const [change, setChange] = useState<Change | null>(null);
    const [requestId, setRequestId] = useState<string | null>(null);

    const requested = (id: string) => {
        setAdding(false);
        setChange(null);
        setRequestId(id);
    };

    return {
        openAdd: () => setAdding(true),
        openChange: setChange,
        openRequest: setRequestId,
        sheets:
            data === null ? null : (
                <>
                    <AddSheet
                        kind={kind}
                        open={adding}
                        data={data}
                        installed={installed}
                        onClose={() => setAdding(false)}
                        onRequested={requested}
                    />
                    <ChangeDialog
                        change={change}
                        onClose={() => setChange(null)}
                        onRequested={requested}
                    />
                    <RequestSheet
                        key={requestId ?? 'none'}
                        requestId={requestId}
                        initial={data.open}
                        installerPresent={data.installerPresent}
                        onClose={() => setRequestId(null)}
                    />
                </>
            ),
    };
}
