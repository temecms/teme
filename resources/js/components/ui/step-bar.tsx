import { CheckIcon } from "lucide-react"
import type * as React from "react"

import { __ } from "@/lib/i18n"
import { cn } from "@/lib/utils"

/**
 * The steps of a task done in several (docs/architecture/design-system.md,
 * section 4.45): those before `current` are done, `current` is where the
 * person is (counted from 0), and `current` equal to the number of steps
 * marks every one done. Under 640px only the current step keeps its words.
 */
function StepBar({
  steps,
  current,
  className,
  ...props
}: Omit<React.ComponentProps<"ol">, "children"> & {
  steps: string[]
  current: number
}) {
  return (
    <ol
      data-slot="step-bar"
      className={cn("flex flex-wrap gap-2", className)}
      {...props}
    >
      {steps.map((step, index) => {
        const done = index < current
        const here = index === current

        return (
          <li
            key={index}
            data-state={done ? "done" : here ? "current" : "upcoming"}
            aria-current={here ? "step" : undefined}
            className={cn(
              "flex min-w-0 items-center gap-2 rounded-md px-3 py-2 text-small font-medium",
              here
                ? "soft-edge bg-accent-soft text-on-accent-soft"
                : "bg-surface-2 text-ink-2"
            )}
          >
            <span
              aria-hidden={done ? undefined : true}
              className={cn(
                "grid size-6 shrink-0 place-items-center rounded-full text-caption font-semibold",
                done
                  ? "bg-ok-soft text-ok-ink"
                  : here
                    ? "bg-accent text-on-accent"
                    : "bg-surface-3 text-ink-2"
              )}
            >
              {done ? (
                <>
                  <CheckIcon aria-hidden="true" className="size-4" />
                  <span className="sr-only">{__("Done")}</span>
                </>
              ) : (
                index + 1
              )}
            </span>
            <span className={cn("truncate", !here && "max-sm:sr-only")}>
              {step}
            </span>
          </li>
        )
      })}
    </ol>
  )
}

export { StepBar }
