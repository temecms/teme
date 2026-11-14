import { Loader2Icon } from "lucide-react"

import { __ } from "@/lib/i18n"
import { cn } from "@/lib/utils"

/** A 16px turning icon, while something loads (docs/architecture/design-system.md, section 4.40). */
function Spinner({ className, ...props }: React.ComponentProps<"svg">) {
  return (
    <Loader2Icon
      data-slot="spinner"
      role="status"
      aria-label={__("Loading")}
      className={cn("size-4 animate-spin text-ink-3", className)}
      {...props}
    />
  )
}

export { Spinner }
