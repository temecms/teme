export type FeaturedImage = {
    id: string;
    title: string;
    thumbUrl: string | null;
    alt: string | null;
    decorative: boolean;
};

export type MediaKind = 'image' | 'video' | 'audio' | 'document';

/** The item open in the editor, which uploads made there record (I-553), and what to call it: "post", "page", "region". */
export type UploadTarget = { id: string; noun: string };

/** The item a file was uploaded for (I-553), as the drawer names it. */
export type UploadedFor = {
    id: string;
    title: string;
    status: string;
    trashed: boolean;
};

export type MediaRow = {
    id: string;
    kind: MediaKind;
    title: string;
    filename: string;
    extension: string;
    width: number | null;
    height: number | null;
    sizeBytes: number;
    thumbUrl: string | null;
    processing: 'pending' | 'ready' | 'failed';
    uses: number;
    hasAlt: boolean;
    alt: string | null;
    credit: string | null;
    licence: string | null;
    trashed: boolean;
    createdAt: string;
    uploaderName: string | null;
};

/** What Suggest alt text offers this viewer for this image (I-321): where it would be sent, and their hourly limit. */
export type AltTextOffer = { sentTo: string[]; hourlyLimit: number };

export type MediaDetailsData = MediaRow & {
    mimeType: string;
    caption: string | null;
    focalX: number;
    focalY: number;
    previewUrl: string | null;
    fileUrl: string | null;
    processingError: string | null;
    workingUses: number;
    publishedUses: number;
    updatedAt: string;
    trashedAt: string | null;
    uploadedFor: UploadedFor | null;
    can: { edit: boolean; delete: boolean };
    altText: AltTextOffer | null;
};

export type MediaUsage = {
    id: string;
    title: string;
    type: string;
    status: string;
    published: boolean;
};

/** One month of the Date select: its value ("2026-10") and its words ("October 2026"). */
export type MediaMonth = { value: string; label: string };

export type MediaIndexPage = {
    rows: MediaRow[];
    newer: string | null;
    older: string | null;
    tabs: { key: string; label: string; count: number }[];
    filtered: boolean;
    filters: Record<string, string | undefined>;
    months: MediaMonth[];
    siteTimezone: string;
    uploadedFor: { id: string; title: string } | null;
};
