import * as SwitchPrimitive from "@radix-ui/react-switch"
import * as React from "react"

import { cn } from "@/lib/utils"

/** An on and off control (docs/architecture/design-system.md, section 4.10). */
function Switch({
  className,
  ...props
}: React.ComponentProps<typeof SwitchPrimitive.Root>) {
  return (
    <SwitchPrimitive.Root
      data-slot="switch"
      className={cn(
        "peer inline-flex h-5 w-9 shrink-0 items-center rounded-full bg-surface-4 inset-ring inset-ring-edge transition-[background-color] duration-(--dur-2) ease-(--ease) outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring data-[state=checked]:bg-accent data-[state=checked]:inset-ring-transparent disabled:cursor-not-allowed disabled:opacity-55",
        className
      )}
      {...props}
    >
      <SwitchPrimitive.Thumb
        data-slot="switch-thumb"
        className="pointer-events-none block size-3.5 translate-x-0.75 rounded-full bg-surface transition-[translate] duration-(--dur-2) ease-(--ease) data-[state=checked]:translate-x-4.75 data-[state=checked]:bg-on-accent"
      />
    </SwitchPrimitive.Root>
  )
}

export { Switch }
