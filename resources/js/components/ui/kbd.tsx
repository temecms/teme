import * as React from "react"

import { cn } from "@/lib/utils"

/** A key on the keyboard, as a shortcut names it (docs/architecture/design-system.md, section 4.40). */
function Kbd({ className, ...props }: React.ComponentProps<"kbd">) {
  return (
    <kbd
      data-slot="kbd"
      className={cn(
        "inline-flex h-5 min-w-5 items-center justify-center rounded-sm bg-surface-3 px-1 font-sans text-caption font-medium text-ink-3",
        className
      )}
      {...props}
    />
  )
}

export { Kbd }
