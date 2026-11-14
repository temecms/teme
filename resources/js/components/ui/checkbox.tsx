import * as CheckboxPrimitive from "@radix-ui/react-checkbox"
import { CheckIcon, MinusIcon } from "lucide-react"
import * as React from "react"

import { cn } from "@/lib/utils"

function Checkbox({
  className,
  ...props
}: React.ComponentProps<typeof CheckboxPrimitive.Root>) {
  return (
    <CheckboxPrimitive.Root
      data-slot="checkbox"
      className={cn(
        "peer group size-4.5 shrink-0 rounded-xs border-control bg-surface text-on-accent transition-[background-color] duration-(--dur-1) ease-(--ease) outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring aria-invalid:border-danger data-[state=checked]:border-transparent data-[state=checked]:bg-accent data-[state=indeterminate]:border-transparent data-[state=indeterminate]:bg-accent disabled:cursor-not-allowed disabled:opacity-55",
        className
      )}
      {...props}
    >
      <CheckboxPrimitive.Indicator
        data-slot="checkbox-indicator"
        className="flex items-center justify-center text-current transition-none"
      >
        {/* A dash for "some of them" (aria-checked="mixed"), as in Bulk Edit. */}
        <CheckIcon className="size-3.5 group-data-[state=indeterminate]:hidden" />
        <MinusIcon className="hidden size-3.5 group-data-[state=indeterminate]:block" />
      </CheckboxPrimitive.Indicator>
    </CheckboxPrimitive.Root>
  )
}

export { Checkbox }
