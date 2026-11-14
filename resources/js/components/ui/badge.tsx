import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

// docs/architecture/design-system.md, section 4.15: a count or a short tag,
// fully rounded; a status is a tint with its own ink, never a solid fill.
const badgeVariants = cva(
  "inline-flex h-5.5 min-w-5.5 w-fit shrink-0 items-center justify-center gap-1 overflow-hidden whitespace-nowrap rounded-full px-1.75 text-caption font-semibold tabular-nums [&>svg]:pointer-events-none [&>svg]:size-3 outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring",
  {
    variants: {
      variant: {
        default: "bg-accent-soft text-on-accent-soft soft-edge",
        secondary: "bg-surface-3 text-ink-2",
        success: "bg-ok-soft text-ok-ink soft-edge",
        destructive: "bg-danger-soft text-danger-ink soft-edge",
        outline: "bg-transparent text-ink-2 inset-ring inset-ring-edge",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Badge({
  className,
  variant,
  asChild = false,
  ...props
}: React.ComponentProps<"span"> &
  VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : "span"

  return (
    <Comp
      data-slot="badge"
      className={cn(badgeVariants({ variant }), className)}
      {...props}
    />
  )
}

export { Badge, badgeVariants }
