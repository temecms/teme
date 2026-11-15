import { Lock, OctagonAlertIcon } from 'lucide-react';
import { useState } from 'react';
import type { CSSProperties } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { accentProblems, darkVariant, onColor, ratio } from '@/lib/contrast';
import type { AccentSchemes, BrandColourSection } from '@/types';
import { SettingsPanel } from './settings-panel';

/** A full colour, as the server takes it: a hash and six hex digits. */
const FULL_HEX = /^#[0-9a-f]{6}$/i;

/** A short colour written long, for the colour input, which takes only six digits. */
function longHex(hex: string | null): string | null {
    if (hex === null) {
        return null;
    }

    if (FULL_HEX.test(hex)) {
        return hex.toLowerCase();
    }

    const short = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/i.exec(hex);

    return short === null
        ? null
        : `#${short[1]}${short[1]}${short[2]}${short[2]}${short[3]}${short[3]}`.toLowerCase();
}

/** A small square of a colour, beside the words that name it. */
function Swatch({ colour }: { colour: string }) {
    return (
        <span
            aria-hidden="true"
            className="border-line inline-block size-3 rounded-sm border bg-(--swatch)"
            style={{ '--swatch': colour } as CSSProperties}
        />
    );
}

/**
 * General's Brand colour (I-457): the design group's accent, a section of
 * General's form, saved with it by the page's one save bar. While it is
 * typed, contrast.ts reads it against the live theme's backgrounds, the
 * light one checked and the dark one lightened; once saved, the server's
 * answer is drawn. Read-only, and sent with nothing, for someone who may not
 * change the site's design.
 */
export function BrandColourSetting({
    brand,
    error,
}: {
    brand: BrandColourSection;
    /** The server's refusal of the colour, drawn under the field. */
    error?: string;
}) {
    const [text, setText] = useState(brand.value);
    const { bases, onColors } = brand;
    const locked = !brand.canChange;
    // The colour being set, when it's a whole colour.
    const typed = FULL_HEX.test(text) ? text.toLowerCase() : null;
    // The colour in force: the one set, else the theme's own.
    const colour = typed ?? (text === '' ? longHex(brand.themeAccent) : null);
    const problems =
        typed === null ? [] : accentProblems(typed, bases.light, onColors);
    // Once saved, the server's answer, which is what the site draws; while
    // it is typed, contrast.ts's.
    const schemes: AccentSchemes | null =
        typed === null
            ? null
            : brand.schemes !== null && brand.schemes.light.accent === typed
              ? brand.schemes
              : {
                    light: {
                        accent: typed,
                        onAccent: onColor(typed, onColors),
                        ratio: ratio(typed, bases.light),
                    },
                    dark:
                        bases.dark === undefined
                            ? null
                            : (() => {
                                  const variant = darkVariant(
                                      typed,
                                      bases.dark,
                                      onColors,
                                  );

                                  return {
                                      accent: variant,
                                      onAccent: onColor(variant, onColors),
                                      ratio: ratio(variant, bases.dark),
                                  };
                              })(),
                };

    return (
        <div data-test="brand-colour">
            <SettingsPanel
                as="section"
                title="Brand colour"
                description="The colour of links and buttons on the public site. Themes read it but don’t own it, so it stays when you change theme."
            >
                <div className="grid gap-3">
                    <input
                        type="hidden"
                        name="accent_version"
                        value={brand.version}
                        disabled={locked}
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <input
                            type="color"
                            aria-label="Pick the brand colour"
                            value={colour ?? onColors[1]}
                            disabled={locked}
                            onChange={(event) => setText(event.target.value)}
                            className="border-edge h-(--ctl-h) w-12 rounded-md border"
                        />
                        <label htmlFor="field-accent" className="sr-only">
                            Brand colour
                        </label>
                        <Input
                            id="field-accent"
                            name="accent"
                            aria-describedby="brand-colour-readout field-accent-error"
                            aria-invalid={error !== undefined}
                            className="w-32 font-mono"
                            value={text}
                            placeholder="Theme’s own"
                            spellCheck={false}
                            disabled={locked}
                            onChange={(event) =>
                                setText(event.target.value.trim())
                            }
                        />
                        {text !== '' && !locked && (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => setText('')}
                            >
                                Use the theme’s own colour
                            </Button>
                        )}
                        {colour !== null && (
                            <span
                                className="text-small inline-flex h-(--ctl-h) items-center rounded-md bg-(--sample) px-3 font-medium text-(--sample-ink)"
                                style={
                                    {
                                        '--sample': colour,
                                        '--sample-ink': onColor(
                                            colour,
                                            onColors,
                                        ),
                                    } as CSSProperties
                                }
                            >
                                Sample button
                            </span>
                        )}
                    </div>
                    <p
                        id="brand-colour-readout"
                        className="text-small text-ink-2 flex flex-wrap items-center gap-x-2 gap-y-1"
                    >
                        {text !== '' && typed === null ? (
                            'Write the colour as # and six digits, such as the value the picker gives.'
                        ) : schemes === null ? (
                            colour === null ? (
                                'The theme sets no colour of its own.'
                            ) : (
                                'The theme’s own colour is used in each of its colour schemes.'
                            )
                        ) : (
                            <>
                                <span className="inline-flex items-center gap-1">
                                    <Swatch colour={schemes.light.accent} />
                                    {`${schemes.light.ratio}:1 on light ${schemes.light.ratio >= 4.5 ? '✓' : '✗'}`}
                                </span>
                                {schemes.dark !== null && (
                                    <span className="inline-flex items-center gap-1">
                                        ·{' '}
                                        <Swatch colour={schemes.dark.accent} />
                                        {`dark uses ${schemes.dark.accent}, ${schemes.dark.ratio}:1 ${schemes.dark.ratio >= 4.5 ? '✓' : '✗'}`}
                                    </span>
                                )}
                            </>
                        )}
                    </p>
                    {problems.length > 0 && (
                        <ul className="text-small text-danger-ink list-disc pl-5">
                            {problems.map((problem) => (
                                <li key={problem}>{problem}</li>
                            ))}
                        </ul>
                    )}
                    {error !== undefined && error !== '' && (
                        <p
                            id="field-accent-error"
                            className="text-small text-danger-ink flex items-start gap-1"
                        >
                            <span className="flex h-lh shrink-0 items-center">
                                <OctagonAlertIcon
                                    aria-hidden="true"
                                    className="size-4"
                                />
                            </span>
                            {error}
                        </p>
                    )}
                    {locked && (
                        <p className="text-caption text-ink-3 flex items-center gap-2">
                            <Lock aria-hidden="true" className="size-4" />
                            Only someone who may change the site’s design can
                            change the brand colour.
                        </p>
                    )}
                </div>
            </SettingsPanel>
        </div>
    );
}
