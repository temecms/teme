import * as ToggleGroupPrimitive from "@radix-ui/react-toggle-group"
import * as React from "react"

import { cn } from "@/lib/utils"

/** A few choices side by side, one always pressed (docs/architecture/design-system.md, section 4.40). */
function Segmented({
  className,
  value,
  defaultValue,
  onValueChange,
  ...props
}: Omit<
  React.ComponentProps<typeof ToggleGroupPrimitive.Root>,
  "type" | "defaultValue" | "value" | "onValueChange"
> & {
  value?: string
  defaultValue?: string
  onValueChange?: (value: string) => void
}) {
  const [chosen, setChosen] = React.useState(defaultValue ?? "")

  return (
    <ToggleGroupPrimitive.Root
      data-slot="segmented"
      type="single"
      className={cn(
        "inline-flex items-center gap-0.5 rounded-md bg-surface-3 p-0.5",
        className
      )}
      {...props}
      value={value ?? chosen}
      onValueChange={(next: string) => {
        // One stays pressed: a click on the pressed segment changes nothing.
        if (next !== "") {
          setChosen(next)
          onValueChange?.(next)
        }
      }}
    />
  )
}

function SegmentedItem({
  className,
  ...props
}: React.ComponentProps<typeof ToggleGroupPrimitive.Item>) {
  return (
    <ToggleGroupPrimitive.Item
      data-slot="segmented-item"
      className={cn(
        "inline-flex h-(--ctl-h-xs) items-center justify-center gap-1 rounded-sm px-3 text-small font-medium text-ink-2 transition-[background-color,color] duration-(--dur-1) ease-(--ease) hover:text-ink outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring data-[state=on]:bg-surface data-[state=on]:text-ink data-[state=on]:shadow-raised disabled:cursor-not-allowed disabled:opacity-55",
        className
      )}
      {...props}
    />
  )
}

export { Segmented, SegmentedItem }
