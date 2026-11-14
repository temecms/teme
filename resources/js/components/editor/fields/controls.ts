import type { ComponentType } from 'react';
import { BooleanControl } from '@/components/editor/fields/boolean-control';
import { DatetimeControl } from '@/components/editor/fields/datetime-control';
import { EntryControl } from '@/components/editor/fields/entry-control';
import type { FieldControlProps } from '@/components/editor/fields/field-control';
import { MediaControl } from '@/components/editor/fields/media-control';
import { NumberControl } from '@/components/editor/fields/number-control';
import { SelectControl } from '@/components/editor/fields/select-control';
import { TextControl } from '@/components/editor/fields/text-control';
import { TextareaControl } from '@/components/editor/fields/textarea-control';
import { UrlControl } from '@/components/editor/fields/url-control';

/** The control that draws each component a field type names: all nine. */
export const FIELD_CONTROLS: Record<
    string,
    ComponentType<FieldControlProps>
> = {
    text: TextControl,
    textarea: TextareaControl,
    number: NumberControl,
    boolean: BooleanControl,
    select: SelectControl,
    url: UrlControl,
    datetime: DatetimeControl,
    media: MediaControl,
    entry: EntryControl,
};
