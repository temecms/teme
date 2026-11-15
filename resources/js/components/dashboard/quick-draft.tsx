import { Form } from '@inertiajs/react';
import QuickDraftController from '@/actions/App/Http/Admin/Dashboard/QuickDraftController';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';

/**
 * Quick draft (I-240): a title and a few lines, saved as a draft without
 * opening the editor. HomePanel wraps it in the panel frame.
 */
export function QuickDraft() {
    return (
        <Form
            {...QuickDraftController.form()}
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="grid gap-3"
        >
            {({ processing, errors }) => (
                <>
                    <Field
                        htmlFor="quick-draft-title"
                        label={__('Title')}
                        error={errors.title}
                        required
                    >
                        <Input
                            id="quick-draft-title"
                            name="title"
                            required
                            maxLength={200}
                        />
                    </Field>
                    <Field
                        htmlFor="quick-draft-body"
                        label={__("What's it about?")}
                        error={errors.body}
                    >
                        <Textarea
                            id="quick-draft-body"
                            name="body"
                            rows={4}
                            maxLength={5000}
                        />
                    </Field>
                    <Button
                        disabled={processing}
                        className="justify-self-start"
                    >
                        {__('Save draft')}
                    </Button>
                </>
            )}
        </Form>
    );
}
