import * as React from "react"

import { cn } from "@/lib/utils"

const TONES = {
  accent: "bg-accent",
  ok: "bg-ok",
  warn: "bg-warn",
  danger: "bg-danger",
  quiet: "bg-neutral-dot",
} as const

/** How far a task has gone (docs/architecture/design-system.md, section 4.40). */
function Progress({
  className,
  value,
  max = 100,
  tone = "accent",
  label,
  ...props
}: Omit<React.ComponentProps<"div">, "children"> & {
  value: number
  max?: number
  tone?: keyof typeof TONES
  /** What is progressing, for the progress bar's name. */
  label: string
}) {
  const share = max > 0 ? Math.min(Math.max(value / max, 0), 1) : 0

  return (
    <div
      data-slot="progress"
      role="progressbar"
      aria-label={label}
      aria-valuemin={0}
      aria-valuemax={max}
      aria-valuenow={value}
      className={cn(
        "h-2 w-full overflow-hidden rounded-full bg-surface-4 rtl:-scale-x-100",
        className
      )}
      {...props}
    >
      <div
        data-slot="progress-fill"
        className={cn(
          "h-full w-full rounded-full transition-[transform] duration-(--dur-2) ease-(--ease)",
          TONES[tone]
        )}
        style={{ transform: `translateX(${(share - 1) * 100}%)` }}
      />
    </div>
  )
}

export { Progress }
