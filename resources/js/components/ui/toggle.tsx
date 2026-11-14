import * as TogglePrimitive from "@radix-ui/react-toggle"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

const toggleVariants = cva(
  "inline-flex items-center justify-center gap-2 rounded-md text-small font-medium text-ink-2 hover:bg-surface-3 hover:text-ink disabled:cursor-not-allowed disabled:opacity-55 data-[state=on]:bg-accent-soft data-[state=on]:text-on-accent-soft [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring transition-[background-color,color] duration-(--dur-1) ease-(--ease) aria-invalid:outline-2 aria-invalid:outline-danger",
  {
    variants: {
      variant: {
        default: "bg-transparent",
        outline:
          "border border-edge bg-transparent",
      },
      size: {
        default: "h-(--ctl-h) min-w-(--ctl-h) px-2",
        sm: "h-(--ctl-h-sm) min-w-(--ctl-h-sm) px-1.5",
        lg: "h-(--ctl-h) min-w-(--ctl-h) px-3",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Toggle({
  className,
  variant,
  size,
  ...props
}: React.ComponentProps<typeof TogglePrimitive.Root> &
  VariantProps<typeof toggleVariants>) {
  return (
    <TogglePrimitive.Root
      data-slot="toggle"
      className={cn(toggleVariants({ variant, size, className }))}
      {...props}
    />
  )
}

export { Toggle, toggleVariants }
