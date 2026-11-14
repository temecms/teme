import { Form, Head } from '@inertiajs/react';
import RecoveryKeyController from '@/actions/App/Http/Admin/Tools/Backups/RecoveryKeyController';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/list/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/tools';
import { index as backups } from '@/routes/tools/backups';
import { create } from '@/routes/tools/backups/keys';

/** Adding another recovery key (I-2402): its name; the key itself comes next, shown once. */
export default function NewKey() {
    return (
        <>
            <Head title="Add a recovery key" />

            <div className="max-w-3xl space-y-6 p-6">
                <PageHeader
                    title="Add a recovery key"
                    description="Another key that opens every place, for someone else to keep: a partner, a colleague, a sealed envelope."
                />

                <Form
                    {...RecoveryKeyController.store.form()}
                    className="max-w-[640px] space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="new-key-label">
                                    Name the key
                                </Label>
                                <Input
                                    id="new-key-label"
                                    name="label"
                                    autoComplete="off"
                                    aria-describedby="new-key-label-hint new-key-label-error"
                                />
                                <p
                                    id="new-key-label-hint"
                                    className="text-muted-foreground text-xs"
                                >
                                    Name it after who keeps it, such as Sam’s
                                    key.
                                </p>
                                <InputError
                                    id="new-key-label-error"
                                    message={errors.label}
                                />
                            </div>

                            <InputError
                                id="new-key-place-error"
                                message={errors.place}
                            />

                            <Button
                                type="submit"
                                disabled={processing}
                                aria-describedby="new-key-place-error"
                                data-test="new-key-submit"
                            >
                                {processing
                                    ? 'Making the key…'
                                    : 'Make the key'}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

NewKey.layout = {
    breadcrumbs: [
        { title: 'Tools', href: index() },
        { title: 'Backups', href: backups() },
        { title: 'Add a recovery key', href: create() },
    ],
};
