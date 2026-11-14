import { Form } from '@inertiajs/react';
import type { FormComponentRef } from '@inertiajs/core';
import { useRef, useState } from 'react';
import UserController from '@/actions/App/Http/Admin/Users/UserController';
import { FreshPasswordInline } from '@/components/patterns/fresh-password';
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
import { SelectField } from '@/components/ui/select-field';
import { __, __n } from '@/lib/i18n';
import type { RoleOption } from '@/types';

/**
 * Inviting someone from the header: their name, address and role. A lapsed
 * password is asked for inside the dialog, so nothing typed is lost.
 */
export function InviteDialog({
    open,
    onOpenChange,
    roles,
    days,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    roles: RoleOption[];
    days: number;
}) {
    const form = useRef<FormComponentRef | null>(null);
    const [asking, setAsking] = useState(false);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setAsking(false);
                }

                onOpenChange(next);
            }}
        >
            <DialogContent size="sm" data-test="invite-dialog">
                <DialogHeader>
                    <DialogTitle>{__('Invite someone')}</DialogTitle>
                    <DialogDescription>
                        {__n(
                            'They get an email with a link that works for :count day, where they choose their own password. You can send it again or revoke it under Invited.|They get an email with a link that works for :count days, where they choose their own password. You can send it again or revoke it under Invited.',
                            days,
                        )}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...UserController.store.form()}
                    ref={form}
                    headers={{ 'X-Teme-Ask-In-Place': '1' }}
                    onHttpException={(response) => {
                        // A lapsed password (bootstrap/app.php): ask here, so nothing typed is lost.
                        if (response.status === 423) {
                            setAsking(true);

                            return false;
                        }
                    }}
                    resetOnSuccess
                    onSuccess={() => onOpenChange(false)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field
                                htmlFor="invite-name"
                                label={__('Name')}
                                error={errors.name}
                            >
                                <Input
                                    id="invite-name"
                                    name="name"
                                    required
                                    autoComplete="off"
                                />
                            </Field>
                            <Field
                                htmlFor="invite-email"
                                label={__('Email address')}
                                error={errors.email}
                            >
                                <Input
                                    id="invite-email"
                                    name="email"
                                    type="email"
                                    required
                                    autoComplete="off"
                                />
                            </Field>
                            <Field
                                htmlFor="invite-role"
                                label={__('Role')}
                                error={errors.role}
                            >
                                {/* The least access unless chosen otherwise; the roles are listed most powerful first, as everywhere. */}
                                <SelectField
                                    id="invite-role"
                                    name="role"
                                    defaultValue={
                                        roles[roles.length - 1]?.value
                                    }
                                    options={roles}
                                />
                            </Field>
                            <p className="text-caption text-ink-3">
                                {__('Sending may ask for your password first.')}
                            </p>
                            <FreshPasswordInline
                                open={asking}
                                onOpenChange={setAsking}
                                title={__('Confirm your password')}
                                text={__(
                                    'Type your password to carry on. What you typed stays here.',
                                )}
                                onConfirmed={() => form.current?.submit()}
                            />
                            {!asking && (
                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button type="button" variant="neutral">
                                            {__('Cancel')}
                                        </Button>
                                    </DialogClose>
                                    <Button type="submit" loading={processing}>
                                        {__('Send invitation')}
                                    </Button>
                                </DialogFooter>
                            )}
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
