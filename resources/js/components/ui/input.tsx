import * as React from "react"

import { cn } from "@/lib/utils"

function Input({ className, type, ...props }: React.ComponentProps<"input">) {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn(
        "flex h-(--ctl-h) w-full min-w-0 rounded-md border border-edge bg-surface px-3 text-body text-ink placeholder:text-ink-3 selection:bg-accent selection:text-on-accent transition-[border-color] duration-(--dur-1) ease-(--ease) outline-none file:inline-flex file:h-(--ctl-h-xs) file:border-0 file:bg-transparent file:text-small file:font-medium file:text-ink",
        "focus-visible:border-transparent focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-ring",
        "aria-invalid:border-danger disabled:cursor-not-allowed disabled:bg-surface-2 disabled:text-ink-3 read-only:border-transparent read-only:bg-surface-2",
        className
      )}
      {...props}
    />
  )
}

export { Input }
