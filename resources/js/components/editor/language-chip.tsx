import { Link } from '@inertiajs/react';
import { Check, ChevronDown } from 'lucide-react';
import { languageMark } from '@/components/list/language-marks';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { __ } from '@/lib/i18n';
import type { EditorSession } from '@/types/editor';
import { versionStatus } from './rail/translations-section';

/**
 * The item's language as a small chip by its status in the editor's header
 * (docs/proposals/multilingual-screens.md, 3). Its menu only switches
 * between the versions this person may open, in the same tab; making and
 * linking versions stay in the Translations section.
 */
export function LanguageChip({ session }: { session: EditorSession }) {
    const languages = session.languages ?? [];

    if (languages.length === 0) {
        return null;
    }

    const tags = languages.map((language) => language.tag);
    const current = session.language ?? tags[0];
    const name =
        languages.find((language) => language.tag === current)?.name ?? current;
    const versions = new Map(
        (session.translations ?? []).map((version) => [
            version.language,
            version,
        ]),
    );

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="xs"
                    data-test="language-chip"
                    aria-label={__(
                        'Language: :language. Switch to another version',
                        {
                            language: name,
                        },
                    )}
                >
                    {languageMark(current, tags)}
                    <ChevronDown aria-hidden="true" className="size-3" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                <DropdownMenuLabel>{__('Versions')}</DropdownMenuLabel>
                {languages.map((language) => {
                    const version = versions.get(language.tag);

                    if (language.tag === current) {
                        return (
                            <DropdownMenuItem
                                key={language.tag}
                                disabled
                                data-language={language.tag}
                            >
                                {language.name}
                                <Check
                                    aria-label={__('This version')}
                                    className="ms-auto size-4"
                                />
                            </DropdownMenuItem>
                        );
                    }

                    if (version?.editUrl == null) {
                        return null;
                    }

                    return (
                        <DropdownMenuItem
                            key={language.tag}
                            asChild
                            data-language={language.tag}
                        >
                            <Link href={version.editUrl}>
                                {language.name}
                                <span className="text-small text-ink-3 ms-auto">
                                    {versionStatus(version.status)}
                                </span>
                            </Link>
                        </DropdownMenuItem>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
