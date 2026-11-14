import type { LucideIcon } from "lucide-react"
import {
  CircleCheckIcon,
  InfoIcon,
  OctagonAlertIcon,
  TriangleAlertIcon,
  XIcon,
} from "lucide-react"
import type * as React from "react"

import { Button } from "@/components/ui/button"
import { __ } from "@/lib/i18n"
import { cn } from "@/lib/utils"

const TONES: Record<
  "info" | "ok" | "warn" | "danger",
  { ground: string; ink: string; Icon: LucideIcon }
> = {
  info: { ground: "bg-info-soft", ink: "text-info-ink", Icon: InfoIcon },
  ok: { ground: "bg-ok-soft", ink: "text-ok-ink", Icon: CircleCheckIcon },
  warn: { ground: "bg-warn-soft", ink: "text-warn-ink", Icon: TriangleAlertIcon },
  danger: {
    ground: "bg-danger-soft",
    ink: "text-danger-ink",
    Icon: OctagonAlertIcon,
  },
}

/**
 * The one notice (docs/architecture/design-system.md, section 4.22):
 * something to know about this screen before acting, or the page banner
 * from the status checks. The tone's soft ground and icon, an optional
 * title, the words, at most one action, and an optional dismiss. It is read
 * in place unless the caller gives it a role.
 */
function Notice({
  tone,
  title,
  action,
  onDismiss,
  className,
  children,
  ...props
}: React.ComponentProps<"div"> & {
  tone: keyof typeof TONES
  title?: React.ReactNode
  /** At most one: a neutral sm button or a link. */
  action?: React.ReactNode
  onDismiss?: () => void
}) {
  const { ground, ink, Icon } = TONES[tone]

  return (
    <div
      data-slot="notice"
      data-tone={tone}
      className={cn(
        "soft-edge flex items-start gap-3 rounded-md px-5 py-4 text-body text-ink",
        ground,
        className
      )}
      {...props}
    >
      <Icon aria-hidden="true" className={cn("mt-px size-5 shrink-0", ink)} />
      <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-2">
        <div className="grid min-w-0 flex-1 basis-60 gap-1">
          {title && <p className="font-semibold">{title}</p>}
          <div className="min-w-0">{children}</div>
        </div>
        {action && <div className="shrink-0">{action}</div>}
      </div>
      {onDismiss && (
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label={__("Dismiss")}
          onClick={onDismiss}
          className="-my-1 -me-2 text-ink-2"
        >
          <XIcon aria-hidden="true" />
        </Button>
      )}
    </div>
  )
}

export { Notice }
