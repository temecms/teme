import { router } from '@inertiajs/react';
import type { FlashData, Method, RequestPayload } from '@inertiajs/core';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { FreshPasswordDialog } from '@/components/patterns/fresh-password';
import { __ } from '@/lib/i18n';

type Ask = {
    url: string;
    method?: Method;
    data?: RequestPayload;
    onFlash?: (flash: FlashData) => void;
    onDone?: () => void;
};

/**
 * Sends one change to the installer's routes, asking for the password in place
 * when the confirmation window has lapsed (bootstrap/app.php answers 423), then
 * sending it again, so nothing typed in a sheet is lost.
 */
export function useAsk(): {
    ask: (request: Ask) => void;
    busy: boolean;
    password: ReactNode;
} {
    const [busy, setBusy] = useState(false);
    const [asking, setAsking] = useState(false);
    const waiting = useRef<Ask | null>(null);

    const ask = (request: Ask) => {
        setBusy(true);
        router.visit(request.url, {
            method: request.method ?? 'post',
            data: request.data ?? {},
            preserveScroll: true,
            preserveState: true,
            headers: { 'X-Teme-Ask-In-Place': '1' },
            onHttpException: (response) => {
                if (response.status === 423) {
                    waiting.current = request;
                    setAsking(true);

                    return false;
                }
            },
            onFlash: (flash) => request.onFlash?.(flash),
            onSuccess: () => request.onDone?.(),
            onFinish: () => setBusy(false),
        });
    };

    return {
        ask,
        busy,
        password: (
            <FreshPasswordDialog
                open={asking}
                onOpenChange={(open) => {
                    setAsking(open);

                    if (!open) {
                        setBusy(false);
                    }
                }}
                title={__('Confirm your password')}
                text={__(
                    'Installing, updating and removing plugins and themes asks for your password.',
                )}
                onConfirmed={() => {
                    const again = waiting.current;
                    waiting.current = null;

                    if (again !== null) {
                        ask(again);
                    }
                }}
            />
        ),
    };
}
