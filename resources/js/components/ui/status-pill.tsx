import { OctagonAlertIcon, TriangleAlertIcon, XIcon } from "lucide-react"
import type * as React from "react"

import { cn } from "@/lib/utils"

const TONES = {
  ok: { ground: "bg-ok-soft text-ok-ink", dot: "bg-ok" },
  neutral: { ground: "bg-surface-3 text-ink-2", dot: "bg-neutral-dot" },
  info: { ground: "bg-info-soft text-info-ink", dot: "bg-info" },
  warn: { ground: "bg-warn-soft text-warn-ink", dot: null },
  danger: { ground: "bg-danger-soft text-danger-ink", dot: null },
} as const

/**
 * A state in a word (docs/architecture/design-system.md, section 4.13): the
 * tone's ground and ink, a dot, a triangle or an octagon before the word.
 * Flat, it has no mark, and with `onRemove` it is a tag that can be taken off.
 */
function StatusPill({
  tone,
  flat = false,
  onRemove,
  removeLabel,
  className,
  children,
  ...props
}: Omit<React.ComponentProps<"span">, "children"> & {
  tone: keyof typeof TONES
  /** The word: a pill always has one. */
  children: React.ReactNode
  flat?: boolean
} & (
    | { onRemove?: undefined; removeLabel?: undefined }
    | {
        onRemove: () => void
        /** The remove button's name, such as "Remove Harbour". */
        removeLabel: string
      }
  )) {
  const { ground, dot } = TONES[tone]

  return (
    <span
      data-slot="status-pill"
      data-tone={tone}
      className={cn(
        "soft-edge inline-flex h-6 max-w-full shrink-0 items-center gap-1.5 rounded-full px-2.5 text-small font-medium whitespace-nowrap",
        onRemove && "pe-1",
        ground,
        className
      )}
      {...props}
    >
      {!flat && dot !== null && (
        <span aria-hidden="true" className={cn("size-1.75 shrink-0 rounded-full", dot)} />
      )}
      {!flat && tone === "warn" && (
        <TriangleAlertIcon aria-hidden="true" className="size-3.5 shrink-0 text-warn-ink" />
      )}
      {!flat && tone === "danger" && (
        <OctagonAlertIcon aria-hidden="true" className="size-3.5 shrink-0" />
      )}
      <span className="truncate">{children}</span>
      {onRemove && (
        <button
          type="button"
          data-slot="status-pill-remove"
          aria-label={removeLabel}
          onClick={onRemove}
          className="grid size-5 shrink-0 cursor-pointer place-items-center rounded-full outline-none hover:bg-surface-4 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
        >
          <XIcon aria-hidden="true" className="size-3.5" />
        </button>
      )}
    </span>
  )
}

export { StatusPill }
