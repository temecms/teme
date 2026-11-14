import { Form } from '@inertiajs/react';
import {
    SettingsPanel,
    SettingsPanelBody,
    SettingsPanelFooter,
} from './settings-panel';
import { Lock } from 'lucide-react';
import { useState } from 'react';
import BrandColourController from '@/actions/App/Http/Admin/Settings/BrandColourController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { accentProblems, darkVariant, onColor, ratio } from '@/lib/contrast';
import type { AccentSchemes, BrandColourSection } from '@/types';

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
            className="border-line inline-block size-3 rounded-sm border"
            style={{ background: colour }}
        />
    );
}

/**
 * General's Brand colour (I-457): the design group's accent, saved at once by
 * its own Operation. While it is typed, contrast.ts reads it against the live
 * theme's backgrounds, the light one checked and the dark one lightened; once
 * saved, the server's answer is drawn. Read-only for someone who may not
 * change the site's design.
 */
export function BrandColourSetting({ brand }: { brand: BrandColourSection }) {
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
                bare
                title="Brand colour"
                description="The colour of links and buttons on the public site. Themes read it but don’t own it, so it stays when you change theme."
            >
                <Form
                    {...BrandColourController.form()}
                    options={{ preserveScroll: true }}
                    className="grid"
                >
                    {({ processing, errors }) => (
                        <>
                            <SettingsPanelBody className="gap-3">
                                <input
                                    type="hidden"
                                    name="expected_version"
                                    value={brand.version}
                                />
                                <div className="flex flex-wrap items-center gap-2">
                                    <input
                                        type="color"
                                        aria-label="Pick the brand colour"
                                        value={colour ?? onColors[1]}
                                        disabled={locked}
                                        onChange={(event) =>
                                            setText(event.target.value)
                                        }
                                        className="border-edge h-[34px] w-12 rounded border"
                                    />
                                    <label
                                        htmlFor="field-accent"
                                        className="sr-only"
                                    >
                                        Brand colour
                                    </label>
                                    <Input
                                        id="field-accent"
                                        name="accent"
                                        aria-describedby="brand-colour-readout"
                                        aria-invalid={
                                            errors.accent !== undefined
                                        }
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
                                            className="inline-flex h-[34px] items-center rounded px-3 text-[13px] font-medium"
                                            style={{
                                                background: colour,
                                                color: onColor(
                                                    colour,
                                                    onColors,
                                                ),
                                            }}
                                        >
                                            Sample button
                                        </span>
                                    )}
                                </div>
                                <p
                                    id="brand-colour-readout"
                                    className="text-ink-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px]"
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
                                                <Swatch
                                                    colour={
                                                        schemes.light.accent
                                                    }
                                                />
                                                {`${schemes.light.ratio}:1 on light ${schemes.light.ratio >= 4.5 ? '✓' : '✗'}`}
                                            </span>
                                            {schemes.dark !== null && (
                                                <span className="inline-flex items-center gap-1">
                                                    ·{' '}
                                                    <Swatch
                                                        colour={
                                                            schemes.dark.accent
                                                        }
                                                    />
                                                    {`dark uses ${schemes.dark.accent}, ${schemes.dark.ratio}:1 ${schemes.dark.ratio >= 4.5 ? '✓' : '✗'}`}
                                                </span>
                                            )}
                                        </>
                                    )}
                                </p>
                                {problems.length > 0 && (
                                    <ul className="text-danger-ink list-disc pl-5 text-[13px]">
                                        {problems.map((problem) => (
                                            <li key={problem}>{problem}</li>
                                        ))}
                                    </ul>
                                )}
                                <InputError message={errors.accent} />
                            </SettingsPanelBody>

                            <SettingsPanelFooter>
                                {locked ? (
                                    <p className="text-ink-2 flex items-center gap-2 text-sm">
                                        <Lock
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                        Only someone who may change the site’s
                                        design can change the brand colour.
                                    </p>
                                ) : (
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        data-test="brand-colour-save"
                                    >
                                        Save brand colour
                                    </Button>
                                )}
                            </SettingsPanelFooter>
                        </>
                    )}
                </Form>
            </SettingsPanel>
        </div>
    );
}
