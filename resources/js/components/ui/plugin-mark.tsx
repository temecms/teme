import { PlugIcon } from "lucide-react"
import type * as React from "react"

import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import { __ } from "@/lib/i18n"
import { cn } from "@/lib/utils"

/**
 * What marks a thing a plugin added to a core screen
 * (docs/architecture/design-system.md, section 4.40): a plug and the
 * plugin's name, or in a tight place the plug alone, named in a tooltip.
 */
function PluginMark({
  name,
  iconOnly = false,
  className,
  ...props
}: Omit<React.ComponentProps<"span">, "children"> & {
  name: string
  iconOnly?: boolean
}) {
  if (iconOnly) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <span
            data-slot="plugin-mark"
            // Focusable, so the tooltip opens from the keyboard too.
            tabIndex={0}
            className={cn(
              "inline-grid size-6 shrink-0 place-items-center rounded-sm text-ink-3 outline-none focus-visible:outline-2 focus-visible:outline-ring",
              className
            )}
            {...props}
          >
            <PlugIcon aria-hidden="true" className="size-3.5" />
            <span className="sr-only">{__("From :name", { name })}</span>
          </span>
        </TooltipTrigger>
        <TooltipContent>{name}</TooltipContent>
      </Tooltip>
    )
  }

  return (
    <span
      data-slot="plugin-mark"
      className={cn(
        "inline-flex min-w-0 items-center gap-1 text-caption font-medium text-ink-3",
        className
      )}
      {...props}
    >
      <PlugIcon aria-hidden="true" className="size-3.5 shrink-0" />
      <span className="truncate">{name}</span>
    </span>
  )
}

export { PluginMark }
