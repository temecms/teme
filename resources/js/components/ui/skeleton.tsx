import { cn } from "@/lib/utils"

function Skeleton({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="skeleton"
      className={cn("skeleton-shimmer h-3.5 rounded-sm motion-safe:animate-shimmer", className)}
      {...props}
    />
  )
}

export { Skeleton }
