import * as React from "react"

import { cn } from "@/lib/utils"

/**
 * A text area (docs/architecture/design-system.md, section 4.5). With
 * `autoGrow` it draws without edge or ground and grows with its content.
 */
function Textarea({
  className,
  autoGrow = false,
  rows = 3,
  onInput,
  ...props
}: React.ComponentProps<"textarea"> & { autoGrow?: boolean }) {
  const ref = React.useRef<HTMLTextAreaElement>(null)

  const grow = React.useCallback(() => {
    const area = ref.current

    if (autoGrow && area) {
      area.style.height = "auto"
      area.style.height = `${area.scrollHeight}px`
    }
  }, [autoGrow])

  React.useLayoutEffect(grow, [grow, props.value])

  return (
    <textarea
      ref={ref}
      data-slot="textarea"
      rows={Math.max(rows, 3)}
      onInput={(event) => {
        grow()
        onInput?.(event)
      }}
      className={cn(
        "flex w-full min-w-0 rounded-md px-3 py-2 text-body leading-normal text-ink placeholder:text-ink-3 transition-[border-color] duration-(--dur-1) ease-(--ease) outline-none focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-ring",
        autoGrow
          ? "resize-none overflow-hidden border-0 bg-transparent"
          : "resize-y border border-edge bg-surface focus-visible:border-transparent",
        "aria-invalid:border-danger disabled:cursor-not-allowed disabled:bg-surface-2 disabled:text-ink-3 read-only:border-transparent read-only:bg-surface-2",
        className
      )}
      {...props}
    />
  )
}

export { Textarea }
