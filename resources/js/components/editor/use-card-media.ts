import { useHttp } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import CardMediaController from '@/actions/App/Http/Admin/Contents/CardMediaController';
import type { CardMedia } from '@/types';

/**
 * The media the article shows, as the server's renderer sees it: its cards'
 * and its featured image. What someone chooses is added from
 * content.card-media, which a contributor may read.
 */
export function useCardMedia(initial: Record<string, CardMedia>): {
    media: Record<string, CardMedia>;
    loading: string[];
    addMedia: (ids: string[]) => Promise<void>;
} {
    const { submit } = useHttp();
    // An empty map arrives from PHP as a list; spreading makes it an object.
    const [media, setMedia] = useState<Record<string, CardMedia>>(() => ({
        ...initial,
    }));

    // What is on its way, so a view can tell loading from gone.
    const [loading, setLoading] = useState<string[]>([]);

    const addMedia = useCallback(
        async (ids: string[]) => {
            setLoading((current) => [...current, ...ids]);

            try {
                const found = (await submit(
                    CardMediaController({ query: { ids } }),
                )) as Record<string, CardMedia>;

                setMedia((current) => ({ ...current, ...found }));
            } finally {
                setLoading((current) =>
                    current.filter((id) => !ids.includes(id)),
                );
            }
        },
        [submit],
    );

    return { media, loading, addMedia };
}
