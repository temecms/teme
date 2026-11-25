import { Form, Link } from '@inertiajs/react';
import ImportRunController from '@/actions/App/Http/Admin/Tools/Import/ImportRunController';
import { Button } from '@/components/ui/button';
import { StatusPill } from '@/components/ui/status-pill';
import { __, __n } from '@/lib/i18n';
import type { ImportRunView } from '@/types/import';

/**
 * The picture pass under an import that brought the articles first:
 * how it stands, the files it brought and the posts that still show
 * pictures from the old site, with Bring the pictures again while some do.
 */
export function PicturePass({
    run,
    pass,
}: {
    run: ImportRunView;
    pass: NonNullable<ImportRunView['picturePass']>;
}) {
    const tone =
        pass.state === 'running'
            ? 'info'
            : pass.state === 'finished' && pass.waiting === 0
              ? 'ok'
              : 'warn';

    return (
        <section
            aria-labelledby="import-pictures-heading"
            data-test="import-picture-pass"
            className="bg-surface-2 grid gap-2 rounded-lg p-3"
        >
            <div className="flex flex-wrap items-center gap-3">
                <h3
                    id="import-pictures-heading"
                    className="text-body font-semibold"
                >
                    {__('Pictures')}
                </h3>
                <StatusPill tone={tone}>
                    {pass.stateLabel ?? __('Not started')}
                </StatusPill>
                {pass.href !== null && (
                    <Link href={pass.href} className="text-small underline">
                        {__('Show the picture pass')}
                    </Link>
                )}
            </div>
            <p className="text-small text-ink-2">
                {__n(':count file brought.|:count files brought.', pass.files, {
                    count: pass.files.toLocaleString(),
                })}{' '}
                {pass.waiting === 0
                    ? __('Every post shows its pictures from Teme.')
                    : __n(
                          ':count post still shows pictures from the old site.|:count posts still show pictures from the old site.',
                          pass.waiting,
                          { count: pass.waiting.toLocaleString() },
                      )}
            </p>
            {pass.again && (
                <Form
                    {...ImportRunController.pictures.form({ run: run.id })}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            disabled={processing}
                        >
                            {__('Bring the pictures again')}
                        </Button>
                    )}
                </Form>
            )}
        </section>
    );
}
