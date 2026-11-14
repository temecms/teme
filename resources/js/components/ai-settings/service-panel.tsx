import { KeyRound } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import CheckAiKeyController from '@/actions/App/Http/Admin/Settings/CheckAiKeyController';
import { post } from '@/components/editor/editor-http';
import { SettingsField } from '@/components/fields/settings-field';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CardMedia, SecretStatus } from '@/types';
import type { AiServiceBlock } from '@/types/ai-settings';

/** What the key waits for: nothing, a new key typed in, or removal on save. */
type KeyMode = 'idle' | 'replacing' | 'removing';

const CHECK_FAILED = 'Check key couldn’t run just now. Try again in a minute.';

type Props = {
    service: AiServiceBlock;
    values: Record<string, unknown>;
    secrets: Record<string, SecretStatus>;
    media: Record<string, CardMedia | null>;
    errors: Record<string, string>;
};

/**
 * One AI service on Settings › AI (I-1308): where its text goes, its key's
 * state and never its key, the key's controls, Check key, and the model an
 * administrator adds. A new key is a password field inside the page's form,
 * sent only with Save; removal is a hidden field, until Keep key.
 */
export function ServicePanel({
    service,
    values,
    secrets,
    media,
    errors,
}: Props) {
    const { key, label, keyField, state } = service;
    const [mode, setMode] = useState<KeyMode>('idle');
    const [checking, setChecking] = useState(false);
    const [check, setCheck] = useState('');
    const field = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (mode === 'replacing') {
            field.current?.focus();
        }
    }, [mode]);

    const runCheck = async () => {
        setChecking(true);
        setCheck('');

        try {
            const answer = await post(
                CheckAiKeyController.url({ provider: key }),
            );
            const sentence = answer.body.sentence;

            setCheck(
                answer.status < 400 && typeof sentence === 'string'
                    ? sentence
                    : CHECK_FAILED,
            );
        } catch {
            setCheck(CHECK_FAILED);
        } finally {
            setChecking(false);
        }
    };

    const named = service.fields.some(
        (item) =>
            item.key.endsWith('_other_model') &&
            typeof values[item.key] === 'string' &&
            values[item.key] !== '',
    );

    return (
        <section
            aria-labelledby={`ai-service-${key}-heading`}
            data-test={`ai-service-${key}`}
            className="border-line grid gap-3 border-t pt-4"
        >
            <div className="grid gap-1">
                <h3
                    id={`ai-service-${key}-heading`}
                    className="text-body font-medium"
                >
                    {label}
                </h3>
                <p className="text-small text-ink-2">
                    Text you ask AI about is sent to {label} when a task uses
                    one of its models.
                </p>
            </div>

            <div
                role="group"
                aria-labelledby={`ai-key-label-${key}`}
                className="grid gap-2"
            >
                <div className="flex flex-wrap items-center gap-2">
                    <span
                        id={`ai-key-label-${key}`}
                        className="text-small font-medium"
                    >
                        API key
                    </span>
                    <Badge variant="outline" data-test={`ai-key-state-${key}`}>
                        <KeyRound aria-hidden="true" />
                        {service.stateLabel}
                    </Badge>
                    {mode === 'idle' && state === 'not-set' && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            aria-label={`Set key for ${label}`}
                            data-test={`ai-key-set-${key}`}
                            onClick={() => setMode('replacing')}
                        >
                            Set key
                        </Button>
                    )}
                    {mode === 'idle' && state === 'set' && (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                aria-label={`Replace key for ${label}`}
                                data-test={`ai-key-replace-${key}`}
                                onClick={() => setMode('replacing')}
                            >
                                Replace key
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                aria-label={`Remove key for ${label}`}
                                data-test={`ai-key-remove-${key}`}
                                onClick={() => setMode('removing')}
                            >
                                Remove key
                            </Button>
                        </>
                    )}
                    {mode === 'idle' && state !== 'not-set' && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            aria-label={
                                checking
                                    ? `Checking key for ${label}`
                                    : `Check key for ${label}`
                            }
                            data-test={`ai-key-check-${key}`}
                            disabled={checking}
                            onClick={() => void runCheck()}
                        >
                            {checking ? 'Checking…' : 'Check key'}
                        </Button>
                    )}
                </div>

                {state === 'set-by-server' && (
                    <p className="text-small">
                        Your host set this key on the server, so it can’t be
                        changed or removed here.
                    </p>
                )}

                {mode === 'replacing' && (
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="grid min-w-64 flex-1 gap-1">
                            <Label htmlFor={`ai-key-${key}`}>
                                New {label} API key
                            </Label>
                            <Input
                                ref={field}
                                id={`ai-key-${key}`}
                                type="password"
                                name={`secrets[${keyField}]`}
                                autoComplete="off"
                                spellCheck={false}
                                className="font-mono"
                                aria-describedby={`ai-key-hint-${key} ai-key-error-${key}`}
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label={`Cancel new ${label} key`}
                            data-test={`ai-key-cancel-${key}`}
                            onClick={() => setMode('idle')}
                        >
                            Cancel
                        </Button>
                    </div>
                )}

                {mode === 'removing' && (
                    <div className="flex flex-wrap items-center gap-2">
                        <input
                            type="hidden"
                            name="clear_secrets[]"
                            value={keyField}
                        />
                        <p className="text-small">
                            The key will be removed when you save. {label}’s
                            models stop working until it has a key again.
                        </p>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label={`Keep key for ${label}`}
                            data-test={`ai-key-keep-${key}`}
                            onClick={() => setMode('idle')}
                        >
                            Keep key
                        </Button>
                    </div>
                )}

                <p id={`ai-key-hint-${key}`} className="text-small text-ink-2">
                    Teme keeps keys encrypted and never shows them again, here
                    or anywhere else.
                </p>
                <InputError
                    id={`ai-key-error-${key}`}
                    message={errors[`secrets.${keyField}`]}
                />
                <p
                    role="status"
                    aria-live="polite"
                    data-test={`ai-key-check-result-${key}`}
                    className="text-small"
                >
                    {check}
                </p>
            </div>

            <details
                open={named}
                data-test={`ai-other-model-${key}`}
                className="grid gap-3"
            >
                <summary className="text-small cursor-pointer py-1 font-medium">
                    Another {label} model
                </summary>
                <div className="mt-3 grid gap-4">
                    {service.fields.map((item) => (
                        <SettingsField
                            key={item.key}
                            field={item}
                            value={values[item.key]}
                            secret={secrets[item.key]}
                            media={media[item.key] ?? null}
                            error={errors[`values.${item.key}`]}
                        />
                    ))}
                </div>
            </details>
        </section>
    );
}
