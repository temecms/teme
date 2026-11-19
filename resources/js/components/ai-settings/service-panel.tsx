import { ChevronDown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import CheckAiKeyController from '@/actions/App/Http/Admin/Settings/CheckAiKeyController';
import { post } from '@/components/editor/editor-http';
import { SettingsField } from '@/components/fields/settings-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusPill } from '@/components/ui/status-pill';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { __ } from '@/lib/i18n';
import type { CardMedia, SecretStatus } from '@/types';
import type { AiKeyState, AiServiceBlock } from '@/types/ai-settings';

/** What the key waits for: nothing, a new key typed in, or removal on save. */
type KeyMode = 'idle' | 'replacing' | 'removing';

type Props = {
    service: AiServiceBlock;
    values: Record<string, unknown>;
    secrets: Record<string, SecretStatus>;
    media: Record<string, CardMedia | null>;
    errors: Record<string, string>;
};

/** A key's state as a pill: saved here, set by the server, or not set. */
function KeyStatePill({
    state,
    serviceKey,
}: {
    state: AiKeyState;
    serviceKey: string;
}) {
    const tone =
        state === 'set' ? 'ok' : state === 'set-by-server' ? 'info' : 'warn';
    const words =
        state === 'set'
            ? __('Saved')
            : state === 'set-by-server'
              ? __('Set by the server')
              : __('Not set');

    return (
        <StatusPill tone={tone} data-test={`ai-key-state-${serviceKey}`}>
            {words}
        </StatusPill>
    );
}

/**
 * One AI service's panel on Settings › AI (I-1308): its key's row (the
 * state and never the key, Check key, and Add or Replace key), and the
 * model an administrator adds. A new key is a password field inside the
 * page's form, sent only with Save; removal is a hidden field, until Keep key.
 */
function ServicePanel({ service, values, secrets, media, errors }: Props) {
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
        const failed = __(
            'Check key couldn’t run just now. Try again in a minute.',
        );

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
                    : failed,
            );
        } catch {
            setCheck(failed);
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
        <div className="grid gap-4" data-test={`ai-service-${key}`}>
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
                        {__(':service key', { service: label })}
                    </span>
                    <KeyStatePill state={state} serviceKey={key} />
                    <span className="grow" />
                    {mode === 'idle' && state !== 'not-set' && (
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            aria-label={
                                checking
                                    ? __('Checking key for :service', {
                                          service: label,
                                      })
                                    : __('Check key for :service', {
                                          service: label,
                                      })
                            }
                            data-test={`ai-key-check-${key}`}
                            disabled={checking}
                            onClick={() => void runCheck()}
                        >
                            {checking ? __('Checking…') : __('Check key')}
                        </Button>
                    )}
                    {mode === 'idle' && state === 'not-set' && (
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            aria-label={__('Add key for :service', {
                                service: label,
                            })}
                            data-test={`ai-key-set-${key}`}
                            onClick={() => setMode('replacing')}
                        >
                            {__('Add key')}
                        </Button>
                    )}
                    {mode === 'idle' && state === 'set' && (
                        <>
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                aria-label={__('Replace key for :service', {
                                    service: label,
                                })}
                                data-test={`ai-key-replace-${key}`}
                                onClick={() => setMode('replacing')}
                            >
                                {__('Replace key')}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                aria-label={__('Remove key for :service', {
                                    service: label,
                                })}
                                data-test={`ai-key-remove-${key}`}
                                onClick={() => setMode('removing')}
                            >
                                {__('Remove key')}
                            </Button>
                        </>
                    )}
                </div>

                {mode === 'replacing' && (
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="grid min-w-64 flex-1 gap-1">
                            <Label htmlFor={`ai-key-${key}`}>
                                {__('New :service key', { service: label })}
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
                            aria-label={__('Cancel new :service key', {
                                service: label,
                            })}
                            data-test={`ai-key-cancel-${key}`}
                            onClick={() => setMode('idle')}
                        >
                            {__('Cancel')}
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
                            {__(
                                'The key will be removed when you save. :service’s models stop working until it has a key again.',
                                { service: label },
                            )}
                        </p>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label={__('Keep key for :service', {
                                service: label,
                            })}
                            data-test={`ai-key-keep-${key}`}
                            onClick={() => setMode('idle')}
                        >
                            {__('Keep key')}
                        </Button>
                    </div>
                )}

                <p
                    id={`ai-key-hint-${key}`}
                    className="text-caption text-ink-3"
                >
                    {__(
                        'Text you ask AI about is sent to :service. The key is kept encrypted and never shown again.',
                        { service: label },
                    )}
                </p>
                {state === 'set-by-server' && (
                    <p className="text-caption text-ink-3">
                        {__(
                            'Locked by the server’s configuration: the key is supplied as :variable, so it can’t be changed here.',
                            { variable: service.variable },
                        )}
                    </p>
                )}
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

            <Collapsible
                defaultOpen={named}
                data-test={`ai-other-model-${key}`}
                className="border-line rounded-md border"
            >
                <CollapsibleTrigger className="text-small group focus-visible:outline-ring flex w-full cursor-pointer items-center gap-2 rounded-md px-3 py-2 text-start font-medium outline-none focus-visible:outline-2 focus-visible:outline-offset-2">
                    <ChevronDown
                        aria-hidden="true"
                        className="size-4 shrink-0 transition-transform group-data-[state=open]:rotate-180"
                    />
                    {__('A model Teme’s list doesn’t have yet')}
                </CollapsibleTrigger>
                {/* Kept mounted, so what is typed in a closed section still goes with Save. */}
                <CollapsibleContent forceMount>
                    <div className="border-line grid gap-4 border-t p-3 in-data-[state=closed]:hidden">
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
                </CollapsibleContent>
            </Collapsible>
        </div>
    );
}

/**
 * The AI services as tabs, one key row at a time (I-1308). Every panel stays
 * mounted, so a key typed under one tab is still sent when another is open.
 */
export function ServiceTabs({
    services,
    values,
    secrets,
    media,
    errors,
}: {
    services: AiServiceBlock[];
    values: Record<string, unknown>;
    secrets: Record<string, SecretStatus>;
    media: Record<string, CardMedia | null>;
    errors: Record<string, string>;
}) {
    const first = services[0]?.key ?? '';

    return (
        <Tabs defaultValue={first} data-test="ai-services">
            <TabsList aria-label={__('AI services')} className="flex-wrap">
                {services.map((service) => (
                    <TabsTrigger key={service.key} value={service.key}>
                        {service.label}
                    </TabsTrigger>
                ))}
            </TabsList>
            {services.map((service) => (
                <TabsContent
                    key={service.key}
                    value={service.key}
                    forceMount
                    className="data-[state=inactive]:hidden"
                >
                    <ServicePanel
                        service={service}
                        values={values}
                        secrets={secrets}
                        media={media}
                        errors={errors}
                    />
                </TabsContent>
            ))}
        </Tabs>
    );
}
