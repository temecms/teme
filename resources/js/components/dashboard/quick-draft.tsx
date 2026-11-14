import { Form } from '@inertiajs/react';
import QuickDraftController from '@/actions/App/Http/Admin/Dashboard/QuickDraftController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';

/**
 * Quick draft (I-240, from WordPress): a title and a few lines, saved as a
 * draft without opening the editor, for catching an idea. The new draft
 * appears at the top of Your drafts.
 */
export function QuickDraft() {
    return (
        <Panel
            aria-labelledby="quick-draft"
            className="border-t first:border-t"
        >
            <PanelHeader>
                <h2 id="quick-draft">Quick draft</h2>
            </PanelHeader>
            <PanelBody>
                <Form
                    {...QuickDraftController.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="grid gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor="quick-draft-title">Title</Label>
                                <Input
                                    id="quick-draft-title"
                                    name="title"
                                    required
                                    maxLength={200}
                                />
                                <InputError message={errors.title} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="quick-draft-body">
                                    What's it about?
                                </Label>
                                <textarea
                                    id="quick-draft-body"
                                    name="body"
                                    rows={4}
                                    maxLength={5000}
                                    className="border-edge bg-page focus-visible:ring-ring rounded-md border px-3 py-2 text-[13px] outline-none focus-visible:ring-2"
                                />
                                <InputError message={errors.body} />
                            </div>
                            <Button
                                disabled={processing}
                                className="justify-self-start"
                            >
                                Save draft
                            </Button>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}
