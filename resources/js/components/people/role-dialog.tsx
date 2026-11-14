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
import { SelectField } from '@/components/ui/select-field';
import { __ } from '@/lib/i18n';
import type { RoleOption, UserRow } from '@/types';
import { PersonHead } from './person-cell';

/**
 * Changing a person's role, or the role an invitation gives. A lapsed
 * password is asked for inside the dialog, so the role chosen stays.
 */
export function RoleDialog({
    open,
    person,
    roles,
    onClose,
}: {
    open: boolean;
    person: UserRow | undefined;
    roles: RoleOption[];
    onClose: () => void;
}) {
    const form = useRef<FormComponentRef | null>(null);
    const [asking, setAsking] = useState(false);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setAsking(false);
                    onClose();
                }
            }}
        >
            <DialogContent size="sm" data-test="role-dialog">
                {person && (
                    <>
                        <DialogHeader>
                            <DialogTitle>
                                {__('Change the role of :name', {
                                    name: person.name,
                                })}
                            </DialogTitle>
                            <DialogDescription className="sr-only">
                                {person.email}
                            </DialogDescription>
                        </DialogHeader>
                        <PersonHead user={person} />
                        <Form
                            key={person.id}
                            {...UserController.updateRole.form({
                                user: person.id,
                            })}
                            ref={form}
                            headers={{ 'X-Teme-Ask-In-Place': '1' }}
                            onHttpException={(response) => {
                                // A lapsed password (bootstrap/app.php): ask here, so nothing chosen is lost.
                                if (response.status === 423) {
                                    setAsking(true);

                                    return false;
                                }
                            }}
                            onSuccess={() => {
                                setAsking(false);
                                onClose();
                            }}
                            className="grid gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <Field
                                        htmlFor="change-role"
                                        label={__('Role')}
                                        help={
                                            <span id="change-role-hint">
                                                {person.invitedAt === null
                                                    ? __(
                                                          'They’re signed out everywhere, so their next sign-in uses the new role.',
                                                      )
                                                    : __(
                                                          'They’ll have this role once they accept the invitation.',
                                                      )}
                                            </span>
                                        }
                                        error={errors.role ?? errors.user}
                                    >
                                        <SelectField
                                            id="change-role"
                                            name="role"
                                            defaultValue={
                                                person.role ?? undefined
                                            }
                                            options={roles}
                                        />
                                    </Field>
                                    <p className="text-caption text-ink-3">
                                        {__(
                                            'Changing a role may ask for your password first.',
                                        )}
                                    </p>
                                    <FreshPasswordInline
                                        open={asking}
                                        onOpenChange={setAsking}
                                        title={__('Confirm your password')}
                                        text={__(
                                            'Type your password to carry on. What you typed stays here.',
                                        )}
                                        onConfirmed={() =>
                                            form.current?.submit()
                                        }
                                    />
                                    {!asking && (
                                        <DialogFooter>
                                            <DialogClose asChild>
                                                <Button
                                                    type="button"
                                                    variant="neutral"
                                                >
                                                    {__('Cancel')}
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                type="submit"
                                                loading={processing}
                                            >
                                                {__('Change role')}
                                            </Button>
                                        </DialogFooter>
                                    )}
                                </>
                            )}
                        </Form>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
