import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import RecoveryKeyController from '@/actions/App/Http/Admin/Tools/Backups/RecoveryKeyController';
import { post } from '@/components/editor/editor-http';
import InputError from '@/components/input-error';
import { FreshPasswordDialog } from '@/components/patterns/fresh-password';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { __ } from '@/lib/i18n';

/** The first message in a 422 answer under one key. */
function firstError(body: Record<string, unknown>, key: string): string {
    const errors = body.errors as Record<string, string[]> | undefined;

    return errors?.[key]?.[0] ?? (body.message as string | undefined) ?? '';
}

/**
 * Two groups of a recovery key typed back to finish it (I-2401). It posts
 * without leaving the page: a wrong pair shows under the fields, and a lapsed
 * password is asked for in the shared prompt over the page, so the key shown
 * there is never lost.
 */
export function TypedBack({
    positions,
    challengeId,
    onBack,
    extra,
    focusFirst = false,
}: {
    positions: [number, number];
    /** The key the page showed, so groups typed from an older key are refused. */
    challengeId: string;
    /** Returns to the key without losing what was typed. */
    onBack?: () => void;
    /** More buttons beside Confirm, such as "Show a new key instead". */
    extra?: ReactNode;
    /** Takes focus when it appears, as on the key page; the screen's note doesn't. */
    focusFirst?: boolean;
}) {
    const [a, b] = positions;
    const [typed, setTyped] = useState<Record<number, string>>({
        [a]: '',
        [b]: '',
    });
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [askPassword, setAskPassword] = useState(false);
    const first = useRef<HTMLInputElement | null>(null);

    useEffect(() => {
        if (focusFirst) {
            first.current?.focus();
        }
    }, [focusFirst]);

    const send = async () => {
        setBusy(true);
        setError('');

        try {
            const answer = await post(RecoveryKeyController.confirm.url(), {
                groups: { [a]: typed[a], [b]: typed[b] },
                challenge: challengeId,
            });

            if (answer.status === 423) {
                setAskPassword(true);
            } else if (answer.status === 422) {
                setError(firstError(answer.body, 'groups'));
                first.current?.focus();
            } else if (answer.status >= 200 && answer.status < 300) {
                router.visit(String(answer.body.redirect));
            } else {
                setError(__('The groups could not be checked. Try again.'));
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <section aria-labelledby="typed-back-heading" className="grid gap-4">
            <h2 id="typed-back-heading" className="text-lead font-semibold">
                {__('Type groups :first and :second of your recovery key', {
                    first: a,
                    second: b,
                })}
            </h2>

            <div className="grid gap-1">
                <div className="flex flex-wrap gap-4">
                    {positions.map((position, index) => (
                        <div key={position} className="grid gap-1">
                            <Label htmlFor={`group-${position}`}>
                                {__('Group :number', { number: position })}
                            </Label>
                            <Input
                                id={`group-${position}`}
                                ref={index === 0 ? first : undefined}
                                value={typed[position]}
                                onChange={(event) =>
                                    setTyped({
                                        ...typed,
                                        [position]: event.target.value,
                                    })
                                }
                                maxLength={9}
                                autoComplete="off"
                                autoCapitalize="characters"
                                spellCheck={false}
                                aria-invalid={error !== ''}
                                aria-describedby="typed-back-hint typed-back-error"
                                className="w-32 font-mono uppercase"
                            />
                        </div>
                    ))}
                </div>
                <p id="typed-back-hint" className="text-caption text-ink-3">
                    {__(
                        'Four letters and digits, as printed. Upper or lower case.',
                    )}
                </p>
                <InputError id="typed-back-error" message={error} />
            </div>

            <FreshPasswordDialog
                open={askPassword}
                onOpenChange={setAskPassword}
                title={__('Confirm your password')}
                text={__(
                    'Type your password to carry on. The key stays on this page.',
                )}
                confirmLabel={__('Confirm')}
                onConfirmed={() => {
                    setAskPassword(false);
                    void send();
                }}
            />

            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    disabled={busy}
                    onClick={() => void send()}
                    data-test="key-confirm"
                >
                    {busy ? __('Confirming…') : __('Confirm the key')}
                </Button>
                {onBack && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onBack}
                        data-test="key-back"
                    >
                        {__('Back to the key')}
                    </Button>
                )}
                {extra}
            </div>
        </section>
    );
}
