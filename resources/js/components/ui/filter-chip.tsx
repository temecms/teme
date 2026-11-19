import type * as React from "react"

import { cn } from "@/lib/utils"

/**
 * A toggle filter drawn as a chip (docs/architecture/design-system.md,
 * section 4.14): fully rounded, an edge at rest and the accent's soft ground
 * when pressed, with an optional icon before its label and a count after it.
 */
function FilterChip({
  pressed,
  count,
  className,
  children,
  ...props
}: Omit<React.ComponentProps<"button">, "type" | "aria-pressed"> & {
  pressed: boolean
  /** How many items the chip would show; left out when not known. */
  count?: React.ReactNode
}) {
  return (
    <button
      type="button"
      data-slot="filter-chip"
      aria-pressed={pressed}
      className={cn(
        "inline-flex h-(--ctl-h) shrink-0 items-center gap-2 rounded-full border border-edge bg-surface px-3 text-small font-medium text-ink-2 transition-[background-color,color] duration-(--dur-1) outline-none hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0",
        "aria-pressed:border-transparent aria-pressed:bg-accent-soft aria-pressed:text-on-accent-soft aria-pressed:hover:bg-accent-soft",
        className
      )}
      {...props}
    >
      {children}
      {count !== undefined && <span className="tabular-nums text-ink-3 in-aria-pressed:text-on-accent-soft">{count}</span>}
    </button>
  )
}

export { FilterChip }
