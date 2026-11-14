import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import PictureController from '@/actions/App/Http/Admin/Account/PictureController';
import InputError from '@/components/input-error';
import { MediaModal } from '@/components/media/media-modal';
import { FormPanel } from '@/components/patterns/form-page';
import { Button } from '@/components/ui/button';
import { PersonPicture } from '@/components/ui/person-picture';
import { __ } from '@/lib/i18n';

/**
 * Your picture on Profile (I-3410): an image chosen from Media, or your
 * initials. Choosing needs the Media picker, which only those who may
 * write open, and a confirmed email address.
 */
export function PicturePanel() {
    const { auth, errors } = usePage().props;
    const [choosing, setChoosing] = useState(false);
    const [busy, setBusy] = useState(false);
    const mayChoose = auth.user.capabilities.includes('content.create');
    // The picture's routes need a verified address: until then both buttons wait, saying why.
    const verified = auth.user.emailVerified;
    const help = !verified
        ? __('Verify your email address to change your picture.')
        : mayChoose
          ? __('With none, your initials show.')
          : __(
                'Your role can’t choose from Media. Ask an administrator to add your picture.',
            );

    const settle = {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
    };

    return (
        <FormPanel title={__('Picture')}>
            <div className="flex flex-wrap items-center gap-4">
                <PersonPicture
                    name={auth.user.name}
                    src={auth.user.picture}
                    size={96}
                    nameBeside={false}
                />
                <div className="grid gap-2">
                    <div className="flex flex-wrap gap-2">
                        {mayChoose && (
                            <Button
                                type="button"
                                variant="neutral"
                                size="sm"
                                data-test="picture-choose"
                                disabled={busy || !verified}
                                onClick={() => setChoosing(true)}
                            >
                                {__('Choose from Media')}
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            data-test="picture-remove"
                            disabled={
                                busy || !verified || auth.user.picture === null
                            }
                            onClick={() =>
                                router.delete(
                                    PictureController.destroy.url(),
                                    settle,
                                )
                            }
                        >
                            {__('Remove')}
                        </Button>
                    </div>
                    <p className="text-caption text-ink-3">{help}</p>
                    <InputError message={errors.media} />
                </div>
            </div>
            {mayChoose && verified && (
                <MediaModal
                    open={choosing}
                    onOpenChange={setChoosing}
                    kinds={['image']}
                    action={__('Use as picture')}
                    onChoose={([id]) => {
                        if (id === undefined) {
                            return;
                        }

                        router.put(
                            PictureController.update.url(),
                            { media: id },
                            settle,
                        );
                    }}
                />
            )}
        </FormPanel>
    );
}
