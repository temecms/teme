import * as TabsPrimitive from "@radix-ui/react-tabs"
import * as React from "react"

import { ScrollArea } from "@/components/ui/scroll-area"
import { cn } from "@/lib/utils"

/** How a row of tabs is drawn: pills on `--surface-3`, or a line under the selected one (section 4.27). */
type TabsVariant = "pill" | "underline"

const TabsVariantContext = React.createContext<TabsVariant>("pill")

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
  variant = "pill",
  ...props
}: React.ComponentProps<typeof TabsPrimitive.List> & {
  /** Pills, or the underline a box's own tabs use under its header. */
  variant?: TabsVariant
}) {
  const list = (
    <TabsPrimitive.List
      data-slot="tabs-list"
      data-variant={variant}
      className={cn(
        "flex items-center gap-1",
        variant === "underline" && "w-max min-w-full px-4 pt-2",
        className
      )}
      {...props}
    />
  )

  return (
    <TabsVariantContext.Provider value={variant}>
      {variant === "underline" ? (
        <ScrollArea axis="x" className="border-b border-line">
          {list}
        </ScrollArea>
      ) : (
        list
      )}
    </TabsVariantContext.Provider>
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
  const variant = React.useContext(TabsVariantContext)

  return (
    <TabsPrimitive.Trigger
      data-slot="tabs-trigger"
      className={cn(
        "inline-flex shrink-0 items-center gap-2 text-body font-medium whitespace-nowrap text-ink-2 transition-[background-color,border-color,color] duration-(--dur-1) ease-(--ease) outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-55 data-[state=active]:text-ink",
        variant === "underline"
          ? "h-12 border-b-2 border-transparent px-4 hover:text-ink data-[state=active]:border-accent"
          : "h-(--ctl-h) rounded-md px-3 hover:bg-surface-3 data-[state=active]:bg-surface-3",
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
