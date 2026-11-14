import * as React from "react"

import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover"
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import { __ } from "@/lib/i18n"
import { cn } from "@/lib/utils"

type Term = { id: string; name: string }

/** The gap between chips, in pixels: gap-1. */
const GAP = 4

const chipClass =
  "inline-flex h-6 max-w-33 shrink-0 items-center rounded-sm bg-surface-3 px-2 text-small text-ink-2"

/** One term: at most 132px, its whole name in a tooltip. */
function TermChip({ name }: { name: string }) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span data-slot="term-chip" className={chipClass}>
          <span className="truncate">{name}</span>
        </span>
      </TooltipTrigger>
      <TooltipContent>{name}</TooltipContent>
    </Tooltip>
  )
}

const moreClass =
  "inline-flex h-6 shrink-0 cursor-pointer items-center rounded-sm bg-transparent px-2 text-small font-medium text-ink-2 inset-ring inset-ring-edge outline-none hover:bg-surface-3 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"

/**
 * A list cell's terms (docs/architecture/design-system.md, section 4.14):
 * the first two chips when both fit the cell, else the first, then a "+N"
 * that opens the rest and names them. One line, never wrapping; an empty
 * cell is a dash.
 */
function TermChips({
  terms,
  noun,
  emptyLabel,
  className,
  ...props
}: Omit<React.ComponentProps<"span">, "children"> & {
  terms: Term[]
  /** What one term and several are called, for the "+N" button's name: "tag" and "tags". */
  noun: { one: string; many: string }
  /** What an empty cell says to a screen reader, such as "No tags". */
  emptyLabel: string
}) {
  const cell = React.useRef<HTMLSpanElement>(null)
  const measure = React.useRef<HTMLSpanElement>(null)
  const [two, setTwo] = React.useState(true)

  React.useLayoutEffect(() => {
    const element = cell.current
    const sizes = measure.current

    if (element === null || sizes === null || terms.length < 2) {
      return
    }

    // The measuring row holds the first chip, the second, and the "+N" two chips leave.
    const fit = () => {
      const [first, second, more] = Array.from(sizes.children).map(
        (child) => child.getBoundingClientRect().width
      )
      const needed =
        first + GAP + second + (terms.length > 2 ? GAP + more : 0)

      setTwo(needed <= element.clientWidth)
    }

    fit()

    const observer = new ResizeObserver(fit)
    observer.observe(element)

    return () => observer.disconnect()
  }, [terms])

  if (terms.length === 0) {
    return (
      <span
        data-slot="term-chips"
        role="img"
        aria-label={emptyLabel}
        className={cn("text-ink-3", className)}
        {...props}
      >
        —
      </span>
    )
  }

  const shown = terms.slice(0, two ? 2 : 1)
  const rest = terms.slice(shown.length)

  return (
    <span
      ref={cell}
      data-slot="term-chips"
      className={cn(
        "relative flex w-full min-w-0 flex-nowrap items-center gap-1 overflow-hidden",
        className
      )}
      {...props}
    >
      {shown.map((term) => (
        <TermChip key={term.id} name={term.name} />
      ))}
      {rest.length > 0 && (
        <Popover>
          <PopoverTrigger asChild>
            <button
              type="button"
              data-test="term-chips-more"
              aria-label={__(":count more :noun: :names", {
                count: rest.length,
                noun: rest.length === 1 ? noun.one : noun.many,
                names: rest.map((term) => term.name).join(", "),
              })}
              className={moreClass}
            >
              {__("+:count", { count: rest.length })}
            </button>
          </PopoverTrigger>
          <PopoverContent align="start" className="w-auto max-w-100 p-2">
            <ul className="flex flex-wrap gap-1">
              {rest.map((term) => (
                <li key={term.id} className={chipClass}>
                  {term.name}
                </li>
              ))}
            </ul>
          </PopoverContent>
        </Popover>
      )}
      {terms.length > 1 && (
        <span
          ref={measure}
          aria-hidden="true"
          className="pointer-events-none invisible absolute start-0 top-0 flex gap-1"
        >
          <span className={chipClass}>
            <span className="truncate">{terms[0].name}</span>
          </span>
          <span className={chipClass}>
            <span className="truncate">{terms[1].name}</span>
          </span>
          <span className={moreClass}>
            {__("+:count", { count: Math.max(terms.length - 2, 1) })}
          </span>
        </span>
      )}
    </span>
  )
}

export { TermChips }
