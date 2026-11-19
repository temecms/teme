import type * as React from "react"

import { __ } from "@/lib/i18n"
import { cn } from "@/lib/utils"

/**
 * The quiet tag on something planned and not built
 * (docs/architecture/design-system.md, section 4.46): never a state's
 * colour, 8px after its label. `later` says Later instead, for a tool drawn
 * beside a working one and built after it (Edit image's Crop, Rotate, Flip).
 */
function SoonTag({
  className,
  later = false,
  ...props
}: Omit<React.ComponentProps<"span">, "children"> & { later?: boolean }) {
  return (
    <span
      data-slot="soon-tag"
      className={cn(
        "ms-2 inline-flex h-5 shrink-0 items-center rounded-full bg-surface-3 px-2 text-caption font-medium text-ink-3",
        className
      )}
      {...props}
    >
      {later ? __("Later") : __("Soon")}
    </span>
  )
}

export { SoonTag }
