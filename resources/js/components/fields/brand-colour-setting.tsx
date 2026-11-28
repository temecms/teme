import {
    CheckIcon,
    Lock,
    OctagonAlertIcon,
    TriangleAlertIcon,
} from 'lucide-react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { accentProblems, darkVariant, onColor, ratio } from '@/lib/contrast';
import { __ } from '@/lib/i18n';
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

    // The text drawn on the colour, and how well it reads (v10: "White text on it: 5.5 to 1, readable").
    const onAccent = schemes?.light.onAccent ?? null;
    const onRatio =
        schemes === null || onAccent === null
            ? null
            : ratio(schemes.light.accent, onAccent);
    // The lighter of the two text colours is the one that reads worse on the light page.
    const lighter =
        ratio(onColors[0], bases.light) < ratio(onColors[1], bases.light)
            ? onColors[0]
            : onColors[1];
    const onName =
        onAccent === lighter ? __('White text on it') : __('Dark text on it');

    return (
        <div data-test="brand-colour" className="contents">
            <SettingsPanel
                as="section"
                title={__('Brand colour')}
                description={__(
                    'One colour for the public site’s links and buttons.',
                )}
            >
                <div data-slot="settings-field" className="grid gap-1">
                    <input
                        type="hidden"
                        name="accent_version"
                        value={brand.version}
                        disabled={locked}
                    />
                    <Label htmlFor="field-brand">{__('Brand colour')}</Label>
                    <div className="flex flex-wrap items-center gap-3">
                        <input
                            type="color"
                            aria-label={__('Brand colour, colour picker')}
                            value={colour ?? onColors[1]}
                            disabled={locked}
                            onChange={(event) => setText(event.target.value)}
                            className="border-edge bg-surface h-(--ctl-h) w-(--ctl-h) rounded-md border p-1"
                        />
                        <Input
                            id="field-brand"
                            name="accent"
                            aria-describedby="field-brand-hint brand-colour-readout field-brand-error"
                            aria-invalid={error !== undefined}
                            className="w-32"
                            value={text}
                            spellCheck={false}
                            autoComplete="off"
                            disabled={locked}
                            onChange={(event) =>
                                setText(event.target.value.trim())
                            }
                        />
                        <span
                            id="brand-colour-readout"
                            role="status"
                            className="text-small text-ink-2 flex items-center gap-2"
                        >
                            {text !== '' && typed === null ? (
                                <>
                                    <TriangleAlertIcon
                                        aria-hidden="true"
                                        className="size-3.5"
                                    />
                                    {__('Not a colour yet')}
                                </>
                            ) : onRatio !== null ? (
                                <>
                                    {onRatio >= 4.5 ? (
                                        <CheckIcon
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                    ) : (
                                        <TriangleAlertIcon
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                    )}
                                    {onRatio >= 4.5
                                        ? __(':who: :ratio to 1, readable', {
                                              who: onName,
                                              ratio: onRatio.toFixed(1),
                                          })
                                        : __(':who: :ratio to 1, too faint', {
                                              who: onName,
                                              ratio: onRatio.toFixed(1),
                                          })}
                                </>
                            ) : null}
                        </span>
                    </div>
                    <p
                        id="field-brand-hint"
                        className="text-caption text-ink-3 text-pretty"
                    >
                        {__(
                            'The site’s identity colour: the browser tab, and --teme-brand for themes. Empty leaves the token unset. A theme may opt to use it as its accent.',
                        )}
                    </p>
                    {problems.length > 0 && (
                        <ul className="text-small text-danger-ink list-disc ps-5">
                            {problems.map((problem) => (
                                <li key={problem}>{problem}</li>
                            ))}
                        </ul>
                    )}
                    {error !== undefined && error !== '' && (
                        <p
                            id="field-brand-error"
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
                        <p className="text-caption text-ink-2 flex items-start gap-1">
                            <span className="flex h-lh shrink-0 items-center">
                                <Lock aria-hidden="true" className="size-3.5" />
                            </span>
                            {__(
                                'Only someone who may change the site’s design can change the brand colour.',
                            )}
                        </p>
                    )}
                </div>
            </SettingsPanel>
        </div>
    );
}
