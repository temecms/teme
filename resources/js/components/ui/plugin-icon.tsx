import type * as React from "react"

import { cn } from "@/lib/utils"

const SIZES = {
  32: "size-8 rounded-md text-small",
  56: "size-14 rounded-lg text-lead",
  64: "size-16 rounded-lg text-title",
  96: "size-24 rounded-xl text-display",
} as const

/** The first letters of a plugin's first two words, as its picture's stand-in. */
function pluginInitials(name: string): string {
  return name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => Array.from(word)[0] ?? "")
    .join("")
    .toUpperCase()
}

/**
 * A plugin's own picture (docs/architecture/design-system.md, section 4.47),
 * contained at 60% of its square, or its initials on a neutral square,
 * never the accent. Decorative: the plugin's name is written beside it.
 */
function PluginIcon({
  name,
  src = null,
  size,
  className,
  ...props
}: Omit<React.ComponentProps<"span">, "children"> & {
  name: string
  src?: string | null
  size: keyof typeof SIZES
}) {
  return (
    <span
      data-slot="plugin-icon"
      aria-hidden="true"
      className={cn(
        "inline-grid shrink-0 place-items-center overflow-hidden font-semibold outline outline-img-outline -outline-offset-1",
        src ? "bg-surface" : "bg-surface-3 text-ink-2",
        SIZES[size],
        className
      )}
      {...props}
    >
      {src ? (
        <img src={src} alt="" className="size-3/5 object-contain" />
      ) : (
        pluginInitials(name)
      )}
    </span>
  )
}

export { PluginIcon }
