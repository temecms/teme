import type { ReactNode } from 'react';
import { TermTreePicker } from '@/components/term-tree-picker';
import type { EditorSession } from '@/types';
import { __ } from '@/lib/i18n';
import { RailSection } from './rail-section';
import { TagInput } from './tag-input';

export type ChosenTerm = { id: string; name: string; taxonomy: string };

/** The section's title from the taxonomies' names: "Categories and tags". */
function titleOf(names: string[]): string {
    const lowered = names.map((name, index) =>
        index === 0 ? name : name.toLowerCase(),
    );

    return lowered.length <= 1
        ? (lowered[0] ?? '')
        : `${lowered.slice(0, -1).join(', ')} ${__('and')} ${lowered.at(-1)}`;
}

/**
 * The item's taxonomies as one rail section (docs/architecture/design-system.md,
 * 4.39), "Categories and tags", its summary how many of each are chosen
 * ("2 · 3"). In the type's order: a hierarchical taxonomy as a ticked list
 * that scrolls past 176px, with the primary category under the first one; a
 * flat one as removable pills over "Add a tag and press Enter". A custom
 * taxonomy follows the same pattern; a type with none draws nothing.
 */
export function TaxonomyPanel({
    taxonomies,
    chosen,
    onChange,
    canAdd,
    primaryCategory,
}: {
    taxonomies: EditorSession['taxonomies'];
    chosen: ChosenTerm[];
    onChange: (next: ChosenTerm[]) => void;
    canAdd: boolean;
    primaryCategory?: ReactNode;
}) {
    if (taxonomies.length === 0) {
        return null;
    }

    const first = taxonomies.find((taxonomy) => taxonomy.hierarchical)?.key;
    const mineOf = (key: string) =>
        chosen.filter((term) => term.taxonomy === key);

    // Replaces one taxonomy's choices and keeps the rest in their order.
    const replace = (taxonomy: string, next: ChosenTerm[]) =>
        onChange([
            ...chosen.filter((term) => term.taxonomy !== taxonomy),
            ...next,
        ]);

    return (
        <RailSection
            title={titleOf(taxonomies.map((taxonomy) => taxonomy.plural))}
            testId="rail-terms"
            summary={taxonomies
                .map((taxonomy) => mineOf(taxonomy.key).length)
                .join(' · ')}
        >
            {taxonomies.map((taxonomy) => {
                const mine = mineOf(taxonomy.key);

                return taxonomy.hierarchical && taxonomy.terms !== null ? (
                    <div
                        key={taxonomy.key}
                        className="grid gap-3"
                        data-test={`rail-terms-${taxonomy.key}`}
                    >
                        <TermTreePicker
                            label={taxonomy.plural}
                            labelShown
                            segment={taxonomy.segment}
                            terms={taxonomy.terms}
                            value={mine.map((term) => term.id)}
                            canAdd={canAdd}
                            onChange={(ids) =>
                                replace(
                                    taxonomy.key,
                                    ids.map((id) => ({
                                        id,
                                        name:
                                            taxonomy.terms?.find(
                                                (term) => term.id === id,
                                            )?.name ??
                                            mine.find((term) => term.id === id)
                                                ?.name ??
                                            '',
                                        taxonomy: taxonomy.key,
                                    })),
                                )
                            }
                        />
                        {taxonomy.key === first && primaryCategory}
                    </div>
                ) : (
                    <TagInput
                        key={taxonomy.key}
                        taxonomy={taxonomy.key}
                        label={taxonomy.plural}
                        singular={taxonomy.singular}
                        segment={taxonomy.segment}
                        canCreate={taxonomy.canCreate}
                        value={mine}
                        onChange={(next) => replace(taxonomy.key, next)}
                    />
                );
            })}
        </RailSection>
    );
}
