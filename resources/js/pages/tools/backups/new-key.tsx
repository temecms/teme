import { Form, Head, setLayoutProps } from '@inertiajs/react';
import RecoveryKeyController from '@/actions/App/Http/Admin/Tools/Backups/RecoveryKeyController';
import InputError from '@/components/input-error';
import { FormPage, FormPanel } from '@/components/patterns/form-page';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { __ } from '@/lib/i18n';
import { index } from '@/routes/tools';
import { index as backups } from '@/routes/tools/backups';
import { create } from '@/routes/tools/backups/keys';

/** Adding another recovery key (I-2402): its name; the key itself comes next, shown once. */
export default function NewKey() {
    setLayoutProps({
        breadcrumbs: [
            { title: __('Tools'), href: index() },
            { title: __('Backups'), href: backups() },
            { title: __('Add a recovery key'), href: create() },
        ],
    });

    return (
        <>
            <Head title={__('Add a recovery key')} />

            <FormPage
                title={__('Add a recovery key')}
                description={__(
                    'Another key that opens every place, for someone else to keep: a partner, a colleague, a sealed envelope.',
                )}
            >
                <Form {...RecoveryKeyController.store.form()}>
                    {({ processing, errors }) => (
                        <FormPanel>
                            <Field
                                htmlFor="new-key-label"
                                label={__('Name the key')}
                                help={__(
                                    'Name it after who keeps it, such as Sam’s key.',
                                )}
                                error={errors.label}
                            >
                                <Input
                                    id="new-key-label"
                                    name="label"
                                    autoComplete="off"
                                />
                            </Field>

                            <div className="grid justify-items-start gap-2">
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
                                        ? __('Making the key…')
                                        : __('Make the key')}
                                </Button>
                            </div>
                        </FormPanel>
                    )}
                </Form>
            </FormPage>
        </>
    );
}
