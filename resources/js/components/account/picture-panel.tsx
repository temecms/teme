import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import PictureController from '@/actions/App/Http/Admin/Account/PictureController';
import InputError from '@/components/input-error';
import { MediaModal } from '@/components/media/media-modal';
import { Button } from '@/components/ui/button';
import { PersonPicture } from '@/components/ui/person-picture';
import { __ } from '@/lib/i18n';

/**
 * Your picture, the first field of Profile's "Your details": an
 * image chosen from Media, or your initials. Choosing needs the Media
 * picker, which only those who may write open, and a confirmed email
 * address. It saves as it is chosen, apart from the form around it.
 */
export function PictureField() {
    const { auth, errors } = usePage().props;
    const [choosing, setChoosing] = useState(false);
    const [busy, setBusy] = useState(false);
    const mayChoose = auth.user.capabilities.includes('content.create');
    // The picture's routes need a verified address: until then both buttons wait, saying why.
    const verified = auth.user.emailVerified;
    const help = !verified
        ? __('Verify your email address to change your picture.')
        : mayChoose
          ? __(
                'Choose a picture from Media, or leave it empty so your initials show. The site’s default is chosen in Settings › People.',
            )
          : __(
                'Your role can’t choose from Media. Ask an administrator to add your picture.',
            );

    const settle = {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
    };

    return (
        <div data-slot="field" className="grid gap-1">
            <span
                id="picture-label"
                className="text-small text-ink-2 font-medium"
            >
                {__('Picture')}
            </span>
            <div
                role="group"
                aria-labelledby="picture-label"
                aria-describedby="picture-help"
                className="flex flex-wrap items-center gap-4"
            >
                <PersonPicture
                    name={auth.user.name}
                    src={auth.user.picture}
                    size={96}
                    nameBeside={false}
                />
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
            </div>
            <p id="picture-help" className="text-caption text-ink-3">
                {help}
            </p>
            <InputError role="alert" message={errors.media} />
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
        </div>
    );
}
