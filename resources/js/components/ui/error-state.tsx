import { OctagonAlertIcon } from "lucide-react"
import type * as React from "react"

import { Button } from "@/components/ui/button"
import { __ } from "@/lib/i18n"
import { cn } from "@/lib/utils"

/**
 * What a panel or an area shows when its part failed
 * (docs/architecture/design-system.md, section 4.25): one sentence the
 * caller writes, naming what failed and that the rest is fine, and a way to
 * try again.
 */
function ErrorState({
  message,
  onRetry,
  retryLabel,
  className,
  ...props
}: Omit<React.ComponentProps<"div">, "children"> & {
  message: string
  onRetry?: () => void
  retryLabel?: string
}) {
  return (
    <div
      data-slot="error-state"
      role="alert"
      className={cn("grid justify-items-start gap-3 px-5 pt-2 pb-5", className)}
      {...props}
    >
      <div className="flex items-start gap-3">
        <OctagonAlertIcon
          aria-hidden="true"
          className="mt-px size-5 shrink-0 text-danger-ink"
        />
        <p className="text-body text-ink">{message}</p>
      </div>
      {onRetry && (
        <Button type="button" variant="neutral" size="sm" onClick={onRetry}>
          {retryLabel ?? __("Try again")}
        </Button>
      )}
    </div>
  )
}

export { ErrorState }
