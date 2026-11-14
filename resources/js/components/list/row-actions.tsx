// Later (I-200): plugins contribute columns and actions to these lists in phase 5.
import { Form, Link } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type {
    ComponentProps,
    MouseEvent,
    ReactElement,
    ReactNode,
} from 'react';
import InputError from '@/components/input-error';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type Entry = {
    id: string;
    owner: string;
    label: string;
    destructive?: boolean;
};

/**
 * One thing a row can do. An action that sends a request carries Wayfinder's
 * form() and is a real form's submit button, so it sends exactly what a
 * button in the row did; `wrap` puts that button inside something, such as
 * a confirmation. An action that opens something here carries `onSelect`.
 * An action that goes somewhere carries `href`.
 */
export type RowAction =
    | (Entry & {
          form: {
              action: string;
              method: ComponentProps<typeof Form>['method'];
          };
          /** Sent with the form, as hidden inputs. */
          fields?: Record<string, string>;
          wrap?: (
              button: ReactElement<{
                  onClick?: (event: MouseEvent<HTMLButtonElement>) => void;
              }>,
          ) => ReactNode;
      })
    | (Entry & {
          onSelect: () => void;
          /** The action puts focus somewhere of its own, such as a form it opens in the row, so the menu doesn't hand focus back to its button as it closes. */
          movesFocus?: boolean;
      })
    | (Entry & {
          href: string;
          /** Opens in a new tab, as a plain link: the page isn't the admin's own, such as a preview, so Inertia mustn't visit it. */
          newTab?: boolean;
      });

/**
 * The menu behind a row's More: the actions that don't fit on its line of
 * links (I-3130), drawn as the menu always drew them. It opens below its
 * button and never locks the page, so nothing is chosen by the press that
 * opened it. RowLinks is the only place that draws it.
 */
export function RowActionsMenu({
    items,
    label,
}: {
    items: RowAction[];
    /** Names the row, as in "More actions for Hello". */
    label: string;
}) {
    const [open, setOpen] = useState(false);
    const handOff = useRef(false);

    return (
        <DropdownMenu open={open} onOpenChange={setOpen} modal={false}>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label={label}
                    data-action="more"
                    className="text-link focus-visible:ring-ring -ml-1 inline-flex min-h-6 items-center rounded-sm px-1 hover:underline focus-visible:ring-2 focus-visible:outline-none"
                >
                    More
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                side="bottom"
                sideOffset={4}
                align="start"
                onCloseAutoFocus={(event) => {
                    // Radix runs this before its own handler, which then
                    // leaves focus where the action put it.
                    if (handOff.current) {
                        handOff.current = false;
                        event.preventDefault();
                    }
                }}
            >
                {items.map((item) =>
                    'href' in item ? (
                        <DropdownMenuItem key={item.id} asChild>
                            {item.newTab ? (
                                <a
                                    href={item.href}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    {item.label}
                                    <span className="sr-only">
                                        , opens in a new tab
                                    </span>
                                </a>
                            ) : (
                                // A real link, so it opens in a new tab like any other.
                                <Link href={item.href}>{item.label}</Link>
                            )}
                        </DropdownMenuItem>
                    ) : 'onSelect' in item ? (
                        <DropdownMenuItem
                            key={item.id}
                            variant={
                                item.destructive ? 'destructive' : 'default'
                            }
                            onSelect={() => {
                                handOff.current = Boolean(item.movesFocus);
                                item.onSelect();
                            }}
                        >
                            {item.label}
                        </DropdownMenuItem>
                    ) : (
                        <Form
                            key={item.id}
                            {...item.form}
                            onSuccess={() => setOpen(false)}
                        >
                            {({ processing, errors }) => {
                                const hidden = Object.entries(
                                    item.fields ?? {},
                                ).map(([name, value]) => (
                                    <input
                                        key={name}
                                        type="hidden"
                                        name={name}
                                        value={value}
                                    />
                                ));
                                const button = (
                                    <DropdownMenuItem
                                        asChild
                                        variant={
                                            item.destructive
                                                ? 'destructive'
                                                : 'default'
                                        }
                                        disabled={processing}
                                        // The form submits; the menu stays
                                        // open until it has, so an error can
                                        // show beside the action.
                                        onSelect={(event) =>
                                            event.preventDefault()
                                        }
                                    >
                                        <button
                                            type="submit"
                                            className="w-full"
                                        >
                                            {item.label}
                                        </button>
                                    </DropdownMenuItem>
                                );

                                return (
                                    <>
                                        {hidden}
                                        {item.wrap ? item.wrap(button) : button}
                                        <InputError
                                            message={Object.values(errors)[0]}
                                            className="px-2 pb-1"
                                        />
                                    </>
                                );
                            }}
                        </Form>
                    ),
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
