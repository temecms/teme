import { useId, useState } from 'react';
import { post } from '@/components/editor/editor-http';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field } from '@/components/ui/field';
import { __ } from '@/lib/i18n';
import { store as confirmPassword } from '@/routes/password/confirm';

type FreshPasswordProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    text?: string;
    confirmLabel?: string;
    /** Runs once the password is confirmed, after the prompt closes. */
    onConfirmed: () => void;
};

/** The first message in a 422 answer under one key. */
function firstError(body: Record<string, unknown>, key: string): string {
    const errors = body.errors as Record<string, string[]> | undefined;

    return errors?.[key]?.[0] ?? (body.message as string | undefined) ?? '';
}

/**
 * The password field and its two buttons: Confirm posts to the site's own
 * password confirmation; a wrong password is said in the field and stays
 * typed.
 */
function FreshPasswordFields({
    onOpenChange,
    text,
    confirmLabel,
    onConfirmed,
    Footer,
    Text,
}: Omit<FreshPasswordProps, 'open' | 'title'> & {
    Footer: typeof DialogFooter;
    Text: typeof DialogDescription | 'p';
}) {
    const id = useId();
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    const confirm = async () => {
        setBusy(true);
        setError('');

        try {
            const answer = await post(confirmPassword.url(), { password });

            if (answer.status === 422) {
                setError(firstError(answer.body, 'password'));

                return;
            }

            if (answer.status >= 200 && answer.status < 300) {
                setPassword('');
                onOpenChange(false);
                onConfirmed();
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        // Not a form of its own: the inline prompt may sit inside another form.
        <div className="grid gap-4">
            <Text className="text-body text-ink-2">
                {text ?? __('Type your password to carry on.')}
            </Text>
            <Field
                htmlFor={`${id}-password`}
                label={__('Password')}
                error={error || undefined}
                errorTestId="fresh-password-error"
            >
                <PasswordInput
                    id={`${id}-password`}
                    data-test="fresh-password-input"
                    autoComplete="current-password"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            void confirm();
                        }
                    }}
                    autoFocus
                />
            </Field>
            <Footer>
                <Button
                    type="button"
                    variant="neutral"
                    onClick={() => onOpenChange(false)}
                >
                    {__('Cancel')}
                </Button>
                <Button
                    type="button"
                    loading={busy}
                    data-test="fresh-password-confirm"
                    onClick={() => void confirm()}
                >
                    {confirmLabel ?? __('Confirm')}
                </Button>
            </Footer>
        </div>
    );
}

/**
 * The one fresh-password prompt (docs/architecture/design-system.md,
 * section 4.41): a small dialog asking for the person's password before a
 * sensitive act.
 */
export function FreshPasswordDialog({
    open,
    onOpenChange,
    title,
    ...props
}: FreshPasswordProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent size="sm">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                </DialogHeader>
                <FreshPasswordFields
                    onOpenChange={onOpenChange}
                    Footer={DialogFooter}
                    Text={DialogDescription}
                    {...props}
                />
            </DialogContent>
        </Dialog>
    );
}

/**
 * The same prompt drawn inside a dialog that is already open, so nothing
 * typed there is lost while the password is asked for.
 */
export function FreshPasswordInline({
    open,
    onOpenChange,
    title,
    ...props
}: FreshPasswordProps) {
    const id = useId();

    if (!open) {
        return null;
    }

    return (
        <section
            aria-labelledby={`${id}-title`}
            data-slot="fresh-password"
            className="bg-surface-2 grid gap-3 rounded-lg p-4"
        >
            <h3 id={`${id}-title`} className="text-body font-semibold">
                {title}
            </h3>
            <FreshPasswordFields
                onOpenChange={onOpenChange}
                Footer={DialogFooter}
                Text="p"
                {...props}
            />
        </section>
    );
}
