import * as React from "react"

import { Skeleton } from "@/components/ui/skeleton"
import { cn } from "@/lib/utils"

/** The bars' widths, varied as text is (60 to 90%). */
const WIDTHS = ["w-9/10", "w-3/4", "w-5/6", "w-3/5", "w-4/5"]

/** How long a load may take before the skeleton shows, so a fast one does not flash. */
const DELAY = 300

/**
 * What a panel or a list shows while it loads its data
 * (docs/architecture/design-system.md, section 4.24): nothing for 300ms,
 * then 3 to 5 bars shaped like what will come, in a busy region named for
 * what is loading.
 */
function LoadingState({
  label,
  rows = 4,
  shape = "rows",
  className,
  ...props
}: Omit<React.ComponentProps<"div">, "children"> & {
  /** What is loading, such as "Loading top posts". */
  label: string
  rows?: number
  shape?: "rows" | "panel"
}) {
  const [shown, setShown] = React.useState(false)

  React.useEffect(() => {
    const timer = window.setTimeout(() => setShown(true), DELAY)

    return () => window.clearTimeout(timer)
  }, [])

  const count = Math.min(Math.max(rows, 3), 5)

  return (
    <div
      data-slot="loading-state"
      role="region"
      aria-busy="true"
      aria-label={label}
      className={cn(
        "grid gap-3",
        shape === "panel" && "rounded-lg bg-surface p-5 shadow-raised",
        className
      )}
      {...props}
    >
      {shown &&
        Array.from({ length: count }, (_, index) => (
          <Skeleton key={index} className={WIDTHS[index]} />
        ))}
    </div>
  )
}

export { LoadingState }
