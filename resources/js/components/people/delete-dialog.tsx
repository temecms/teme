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
import type { ReassignOption, UserRow } from '@/types';
import { PersonHead } from './person-cell';

/**
 * Deleting a person, after asking who receives what they wrote. A lapsed
 * password is asked for inside the dialog, so the choice stays.
 */
export function DeleteDialog({
    open,
    person,
    reassignOptions,
    onClose,
}: {
    open: boolean;
    person: UserRow | undefined;
    reassignOptions: ReassignOption[];
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
            <DialogContent size="sm" data-test="delete-dialog">
                {person && (
                    <>
                        <DialogHeader>
                            <DialogTitle>
                                {__('Delete :name', { name: person.name })}
                            </DialogTitle>
                            <DialogDescription className="sr-only">
                                {person.email}
                            </DialogDescription>
                        </DialogHeader>
                        <PersonHead user={person} />
                        <Form
                            key={person.id}
                            {...UserController.destroy.form({
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
                                        htmlFor={`reassign-${person.id}`}
                                        label={__('Give their content to')}
                                        help={
                                            <span
                                                id={`reassign-${person.id}-hint`}
                                            >
                                                {__(
                                                    'Their name stays on what they wrote.',
                                                )}
                                            </span>
                                        }
                                        error={
                                            errors.reassign_to ?? errors.user
                                        }
                                    >
                                        {/* Everyone with an account, from the server, whatever the view lists. */}
                                        <SelectField
                                            id={`reassign-${person.id}`}
                                            name="reassign_to"
                                            defaultValue=""
                                            emptyLabel={__(
                                                'No one — they wrote nothing',
                                            )}
                                            options={reassignOptions.filter(
                                                (option) =>
                                                    option.value !== person.id,
                                            )}
                                        />
                                    </Field>
                                    <p className="text-caption text-ink-3">
                                        {__(
                                            'Deleting may ask for your password first.',
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
                                                variant="danger"
                                                loading={processing}
                                            >
                                                {__('Delete :name', {
                                                    name: person.name,
                                                })}
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
