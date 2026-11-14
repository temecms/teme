import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import { FIELD, pickedCategory, trailOf } from '@/lib/search-preview';
import type { EditorField, EditorSeo, EditorSession } from '@/types/editor';
import type { ChosenTerm } from './taxonomy-panel';

/**
 * The category that stands for the item when it has several (I-869, I-881),
 * chosen in its Categories box once two are ticked: it names the item's
 * section, can set its address, and leads its trail where the theme draws one.
 */
export function PrimaryCategory({
    field,
    taxonomy,
    chosen,
    value,
    onChange,
    seo,
    noun,
    title,
    published,
}: {
    field: EditorField;
    taxonomy: EditorSession['taxonomies'][number];
    chosen: ChosenTerm[];
    value: unknown;
    onChange: (key: string, value: unknown) => void;
    seo: Pick<EditorSeo, 'addressFollowsCategory' | 'trail'>;
    noun: string;
    title: string;
    published: boolean;
}) {
    const ticked = chosen.filter((term) => term.taxonomy === taxonomy.key);

    if (ticked.length < 2) {
        return null;
    }

    // A stored choice that is no longer ticked is kept and ignored, as the site ignores it.
    const current =
        typeof value === 'string' && ticked.some((term) => term.id === value)
            ? value
            : '';
    const picked = pickedCategory([taxonomy], ticked, {
        [FIELD.primaryCategory]: current,
    });

    const hint = [
        `Names this ${noun}’s section above its title and in lists.`,
        seo.addressFollowsCategory
            ? 'It also sets the category in its address.'
            : null,
        seo.trail
            ? `Its trail: ${trailOf(taxonomy.terms ?? [], picked?.id ?? null, title).join(' › ')}.`
            : null,
        published && seo.addressFollowsCategory
            ? `Changing it moves this ${noun}’s address when you update it. The old address leads to the new one.`
            : null,
    ].filter((sentence): sentence is string => sentence !== null);

    return (
        <div className="grid gap-2 pt-3">
            <Label htmlFor="editor-primary-category">{field.label}</Label>
            <SelectField
                id="editor-primary-category"
                aria-describedby="editor-primary-category-hint"
                emptyLabel={`First ticked: ${ticked[0].name}`}
                options={ticked.map((term) => ({
                    value: term.id,
                    label: term.name,
                }))}
                value={current}
                onValueChange={(next) =>
                    onChange(field.key, next === '' ? null : next)
                }
            />
            <p
                id="editor-primary-category-hint"
                data-test="primary-category-hint"
                className="text-ink-2 text-xs"
            >
                {hint.join(' ')}
            </p>
        </div>
    );
}
