import { Slot } from "@radix-ui/react-slot"
import * as React from "react"

import { cn } from "@/lib/utils"

/**
 * A button that reads as words (docs/architecture/design-system.md,
 * section 4.3): underlined on hover, `asChild` for a link.
 */
function TextButton({
  className,
  asChild = false,
  tone = "accent",
  type = "button",
  ...props
}: React.ComponentProps<"button"> & {
  asChild?: boolean
  tone?: "accent" | "danger"
}) {
  const Comp = asChild ? Slot : "button"

  return (
    <Comp
      data-slot="textbutton"
      type={asChild ? undefined : type}
      className={cn(
        "inline-flex min-h-6 items-center gap-1 font-medium underline decoration-transparent underline-offset-3 transition-[text-decoration-color] duration-(--dur-1) ease-(--ease) hover:decoration-current outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-55",
        tone === "danger" ? "text-danger-ink" : "text-accent-ink",
        className
      )}
      {...props}
    />
  )
}

export { TextButton }
