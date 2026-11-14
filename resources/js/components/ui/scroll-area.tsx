import * as React from "react"

import { cn } from "@/lib/utils"

const AXES = {
  y: "overflow-x-hidden overflow-y-auto",
  x: "overflow-x-auto overflow-y-hidden",
  both: "overflow-auto",
} as const

/**
 * Every scrolling area (docs/architecture/design-system.md, sections 2.14
 * and 4.40): the scrollbar's style comes from the base rules. An area that
 * holds nothing focusable takes a `label`, so the keyboard can reach and
 * scroll it.
 */
function ScrollArea({
  className,
  axis = "y",
  label,
  ...props
}: React.ComponentProps<"div"> & {
  axis?: keyof typeof AXES
  label?: string
}) {
  return (
    <div
      data-slot="scroll-area"
      className={cn(
        "min-h-0 min-w-0 overscroll-contain",
        AXES[axis],
        label !== undefined &&
          "outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring",
        className
      )}
      {...(label !== undefined
        ? { role: "region", "aria-label": label, tabIndex: 0 }
        : {})}
      {...props}
    />
  )
}

export { ScrollArea }
