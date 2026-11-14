import * as TabsPrimitive from "@radix-ui/react-tabs"
import * as React from "react"

import { cn } from "@/lib/utils"

/** Panels of one screen that keep its address (docs/architecture/design-system.md, section 4.27). */
function Tabs({
  className,
  ...props
}: React.ComponentProps<typeof TabsPrimitive.Root>) {
  return (
    <TabsPrimitive.Root
      data-slot="tabs"
      className={cn("grid gap-4", className)}
      {...props}
    />
  )
}

function TabsList({
  className,
  ...props
}: React.ComponentProps<typeof TabsPrimitive.List>) {
  return (
    <TabsPrimitive.List
      data-slot="tabs-list"
      className={cn("flex items-center gap-1", className)}
      {...props}
    />
  )
}

function TabsTrigger({
  className,
  count,
  children,
  ...props
}: React.ComponentProps<typeof TabsPrimitive.Trigger> & {
  /** Shown after the label, quieter. */
  count?: number
}) {
  return (
    <TabsPrimitive.Trigger
      data-slot="tabs-trigger"
      className={cn(
        "inline-flex h-(--ctl-h) shrink-0 items-center gap-2 rounded-md px-3 text-body font-medium whitespace-nowrap text-ink-2 transition-[background-color,color] duration-(--dur-1) ease-(--ease) outline-none hover:bg-surface-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-55 data-[state=active]:bg-surface-3 data-[state=active]:text-ink",
        className
      )}
      {...props}
    >
      {children}
      {count !== undefined ? (
        <span className="tabular-nums text-ink-3">{count}</span>
      ) : null}
    </TabsPrimitive.Trigger>
  )
}

function TabsContent({
  className,
  ...props
}: React.ComponentProps<typeof TabsPrimitive.Content>) {
  return (
    <TabsPrimitive.Content
      data-slot="tabs-content"
      className={cn(
        "outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring",
        className
      )}
      {...props}
    />
  )
}

export { Tabs, TabsContent, TabsList, TabsTrigger }
