import { router } from "@inertiajs/react"
import { Slot } from "@radix-ui/react-slot"
import type { VariantProps} from "class-variance-authority";
import { cva } from "class-variance-authority"
import { PanelLeftCloseIcon, PanelLeftOpenIcon } from "lucide-react"
import * as React from "react"

import { badgeVariants } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Separator } from "@/components/ui/separator"
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import type { ShellWidth } from "@/hooks/use-shell-width"
import { useShellWidth } from "@/hooks/use-shell-width"
import { cn } from "@/lib/utils"

const SIDEBAR_COOKIE_NAME = "sidebar_state"
const SIDEBAR_COOKIE_MAX_AGE = 60 * 60 * 24 * 7
const SIDEBAR_WIDTH = "var(--side-w)"
const SIDEBAR_WIDTH_ICON = "var(--side-w-rail)"
const SIDEBAR_KEYBOARD_SHORTCUT = "b"

type SidebarContext = {
  state: "expanded" | "collapsed"
  open: boolean
  setOpen: (open: boolean, options?: { persist?: boolean }) => void
  rememberIn: (cookie: string | null) => void
  openMobile: boolean
  setOpenMobile: (open: boolean) => void
  /** Under 768px: the menu is a drawer. */
  isMobile: boolean
  /** small under 768px, compact (the rail) to 1023, wide from 1024. */
  width: ShellWidth
  /** Whether the full menu is drawn over the page from the rail (compact only). */
  overlayOpen: boolean
  setOverlayOpen: (open: boolean) => void
  toggleSidebar: () => void
}

const SidebarContext = React.createContext<SidebarContext | null>(null)

function useSidebar() {
  const context = React.useContext(SidebarContext)
  if (!context) {
    throw new Error("useSidebar must be used within a SidebarProvider.")
  }

  return context
}

function SidebarProvider({
  defaultOpen = true,
  open: openProp,
  onOpenChange: setOpenProp,
  className,
  style,
  children,
  ...props
}: React.ComponentProps<"div"> & {
  defaultOpen?: boolean
  open?: boolean
  onOpenChange?: (open: boolean) => void
}) {
  const width = useShellWidth()
  const isMobile = width === "small"
  const [openMobile, setOpenMobile] = React.useState(false)
  const [overlayRequested, setOverlayOpen] = React.useState(false)
  // Only the rail opens the full menu over the page.
  const overlayOpen = overlayRequested && width === "compact"

  // Leaving the rail's widths forgets the request, so coming back does not reopen it.
  React.useEffect(() => {
    if (width !== "compact") {
      setOverlayOpen(false)
    }
  }, [width])

  // Choosing a page on the phone closes the drawer as the new page opens.
  React.useEffect(
    () =>
      router.on("start", () => {
        setOpenMobile(false)
      }),
    [],
  )

  // This is the internal state of the sidebar.
  // We use openProp and setOpenProp for control from outside the component.
  const [_open, _setOpen] = React.useState(defaultOpen)
  const open = openProp ?? _open
  // The cookie a change the person makes is written to: the sidebar's own,
  // or the one a screen that folds the sidebar for itself names while it is
  // open, such as the editor's.
  const cookieName = React.useRef(SIDEBAR_COOKIE_NAME)
  const rememberIn = React.useCallback((cookie: string | null) => {
    cookieName.current = cookie ?? SIDEBAR_COOKIE_NAME
  }, [])
  const setOpen = React.useCallback(
    (
      value: boolean | ((value: boolean) => boolean),
      options?: { persist?: boolean }
    ) => {
      const openState = typeof value === "function" ? value(open) : value
      if (setOpenProp) {
        setOpenProp(openState)
      } else {
        _setOpen(openState)
      }

      // This sets the cookie to keep the sidebar state. A screen that folds
      // the sidebar for itself, such as the editor, does not persist it, so
      // the person's own choice is what the next page opens with.
      if (options?.persist !== false) {
        document.cookie = `${cookieName.current}=${openState}; path=/; max-age=${SIDEBAR_COOKIE_MAX_AGE}`
      }
    },
    [setOpenProp, open]
  )

  // Helper to toggle the sidebar.
  const toggleSidebar = React.useCallback(() => {
    if (width === "small") {
      return setOpenMobile((open) => !open)
    }

    return width === "compact"
      ? setOverlayOpen((open) => !open)
      : setOpen((open) => !open)
  }, [width, setOpen, setOpenMobile])

  // Adds a keyboard shortcut to toggle the sidebar.
  React.useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      if (
        event.key === SIDEBAR_KEYBOARD_SHORTCUT &&
        (event.metaKey || event.ctrlKey)
      ) {
        event.preventDefault()
        toggleSidebar()
      }
    }

    window.addEventListener("keydown", handleKeyDown)
    return () => window.removeEventListener("keydown", handleKeyDown)
  }, [toggleSidebar])

  // We add a state so that we can do data-state="expanded" or "collapsed".
  // This makes it easier to style the sidebar with Tailwind classes. The
  // rail is folded whatever the person chose for wide screens.
  const state = open && width !== "compact" ? "expanded" : "collapsed"

  const contextValue = React.useMemo<SidebarContext>(
    () => ({
      state,
      open,
      setOpen,
      rememberIn,
      isMobile,
      width,
      overlayOpen,
      setOverlayOpen,
      openMobile,
      setOpenMobile,
      toggleSidebar,
    }),
    [
      state,
      open,
      setOpen,
      rememberIn,
      isMobile,
      width,
      overlayOpen,
      setOverlayOpen,
      openMobile,
      setOpenMobile,
      toggleSidebar,
    ]
  )

  return (
    <SidebarContext.Provider value={contextValue}>
      <div
        data-slot="sidebar-wrapper"
        style={
          {
            "--sidebar-width": SIDEBAR_WIDTH,
            "--sidebar-width-icon": SIDEBAR_WIDTH_ICON,
            ...style,
          } as React.CSSProperties
        }
        className={cn(
          "group/sidebar-wrapper has-data-[variant=inset]:bg-chrome flex min-h-svh w-full",
          className
        )}
        {...props}
      >
        {children}
      </div>
    </SidebarContext.Provider>
  )
}

/** The elements Tab may reach inside the menu drawn over the page. */
const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'

/**
 * The full menu over the page, from the rail (compact widths only): focus
 * moves in and stays, Escape and the scrim close it, and focus goes back to
 * what opened it. A followed link closes it too.
 */
function useMenuOverlay(panel: React.RefObject<HTMLDivElement | null>) {
  const { overlayOpen, setOverlayOpen } = useSidebar()

  React.useEffect(() => {
    const element = panel.current

    if (!overlayOpen || element === null) {
      return
    }

    const opener = document.activeElement as HTMLElement | null
    const focusables = () =>
      Array.from(element.querySelectorAll<HTMLElement>(FOCUSABLE))

    focusables()[0]?.focus()

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        event.preventDefault()
        setOverlayOpen(false)

        return
      }

      if (event.key !== "Tab") {
        return
      }

      const all = focusables()
      const first = all[0]
      const last = all[all.length - 1]

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last?.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first?.focus()
      }
    }

    const onClick = (event: MouseEvent) => {
      if ((event.target as HTMLElement).closest("a[href]") !== null) {
        setOverlayOpen(false)
      }
    }

    document.addEventListener("keydown", onKeyDown)
    element.addEventListener("click", onClick)

    return () => {
      document.removeEventListener("keydown", onKeyDown)
      element.removeEventListener("click", onClick)
      opener?.focus()
    }
  }, [overlayOpen, setOverlayOpen, panel])
}

function Sidebar({
  side = "left",
  variant = "sidebar",
  collapsible = "offcanvas",
  className,
  children,
  ...props
}: React.ComponentProps<"div"> & {
  side?: "left" | "right"
  variant?: "sidebar" | "floating" | "inset"
  collapsible?: "offcanvas" | "icon" | "none"
}) {
  const { isMobile, state, openMobile, setOpenMobile, overlayOpen, setOverlayOpen } =
    useSidebar()
  const panel = React.useRef<HTMLDivElement>(null)

  useMenuOverlay(panel)

  if (collapsible === "none") {
    return (
      <div
        data-slot="sidebar"
        className={cn(
          "bg-chrome text-ink-2 flex h-full w-(--sidebar-width) flex-col",
          className
        )}
        {...props}
      >
        {children}
      </div>
    )
  }

  if (isMobile) {
    return (
      <Sheet open={openMobile} onOpenChange={setOpenMobile} {...props}>
        <SheetContent
          data-sidebar="sidebar"
          data-slot="sidebar"
          data-mobile="true"
          className="bg-chrome text-ink-2 w-(--sidebar-width) p-0 sm:max-w-(--sidebar-width) [&>button]:hidden"
          side={side}
        >
          <SheetHeader className="sr-only">
            <SheetTitle>Main menu</SheetTitle>
            <SheetDescription>The menu for every admin screen.</SheetDescription>
          </SheetHeader>
          <div className="flex h-full w-full flex-col">{children}</div>
        </SheetContent>
      </Sheet>
    )
  }

  return (
    <div
      className="group peer text-ink-2 hidden md:block"
      // Over the page, the rail draws the full menu; its gap stays the rail's.
      data-state={overlayOpen ? "expanded" : state}
      data-collapsible={state === "collapsed" && !overlayOpen ? collapsible : ""}
      data-overlay={overlayOpen ? "true" : undefined}
      data-variant={variant}
      data-side={side}
      data-slot="sidebar"
    >
      {/* This is what handles the sidebar gap on desktop */}
      <div
        data-slot="sidebar-gap"
        className={cn(
          "relative h-svh w-(--sidebar-width) bg-transparent transition-[width] duration-(--dur-2) ease-(--ease)",
          "group-data-[collapsible=offcanvas]:w-0",
          "group-data-[side=right]:rotate-180",
          variant === "floating" || variant === "inset"
            ? "group-data-[collapsible=icon]:w-[calc(var(--sidebar-width-icon)+(--spacing(4)))]"
            : "group-data-[collapsible=icon]:w-(--sidebar-width-icon)",
          "group-data-[overlay=true]:w-(--sidebar-width-icon)"
        )}
      />
      {overlayOpen && (
        <div
          data-slot="sidebar-scrim"
          aria-hidden="true"
          onClick={() => setOverlayOpen(false)}
          className="fixed inset-0 z-(--z-overlay) bg-scrim duration-(--dur-2) ease-(--ease) motion-safe:animate-in motion-safe:fade-in-0"
        />
      )}
      <div
        ref={panel}
        data-test={overlayOpen ? "menu-overlay" : undefined}
        className={cn(
          "fixed inset-y-0 z-(--z-sticky) hidden h-svh w-(--sidebar-width) transition-[left,right,width] duration-(--dur-2) ease-(--ease) md:flex",
          side === "left"
            ? "left-0 group-data-[collapsible=offcanvas]:left-[calc(var(--sidebar-width)*-1)]"
            : "right-0 group-data-[collapsible=offcanvas]:right-[calc(var(--sidebar-width)*-1)]",
          // Adjust the padding for floating and inset variants.
          variant === "floating" || variant === "inset"
            ? "p-2 group-data-[collapsible=icon]:w-[calc(var(--sidebar-width-icon)+(--spacing(4))+2px)]"
            : "group-data-[collapsible=icon]:w-(--sidebar-width-icon)",
          "group-data-[overlay=true]:z-(--z-overlay) group-data-[overlay=true]:shadow-modal",
          className
        )}
        {...props}
      >
        {/* A landmark, so the menu isn't page content outside every region (axe's region rule). */}
        <aside
          data-sidebar="sidebar"
          aria-label="Main menu"
          className="bg-chrome group-data-[variant=floating]:border-line flex h-full w-full flex-col group-data-[variant=floating]:rounded-lg group-data-[variant=floating]:border group-data-[variant=floating]:shadow-raised"
        >
          {children}
        </aside>
      </div>
    </div>
  )
}

function SidebarTrigger({
  className,
  onClick,
  ...props
}: React.ComponentProps<typeof Button>) {
  const { toggleSidebar, isMobile, state } = useSidebar()

  return (
    <Button
      data-sidebar="trigger"
      data-slot="sidebar-trigger"
      variant="ghost"
      size="icon"
      className={cn("h-7 w-7", className)}
      onClick={(event) => {
        onClick?.(event)
        toggleSidebar()
      }}
      {...props}
    >
      {isMobile || state === "collapsed" ? <PanelLeftOpenIcon /> : <PanelLeftCloseIcon />}
      <span className="sr-only">Toggle sidebar</span>
    </Button>
  )
}

function SidebarRail({ className, ...props }: React.ComponentProps<"button">) {
  const { toggleSidebar } = useSidebar()

  return (
    <button
      data-sidebar="rail"
      data-slot="sidebar-rail"
      aria-label="Toggle sidebar"
      tabIndex={-1}
      onClick={toggleSidebar}
      title="Toggle sidebar"
      className={cn(
        "hover:after:bg-line absolute inset-y-0 z-20 hidden w-4 -translate-x-1/2 transition-all ease-linear group-data-[side=left]:-right-4 group-data-[side=right]:left-0 after:absolute after:inset-y-0 after:left-1/2 after:w-[2px] sm:flex",
        "in-data-[side=left]:cursor-w-resize in-data-[side=right]:cursor-e-resize",
        "[[data-side=left][data-state=collapsed]_&]:cursor-e-resize [[data-side=right][data-state=collapsed]_&]:cursor-w-resize",
        "hover:group-data-[collapsible=offcanvas]:bg-chrome group-data-[collapsible=offcanvas]:translate-x-0 group-data-[collapsible=offcanvas]:after:left-full",
        "[[data-side=left][data-collapsible=offcanvas]_&]:-right-2",
        "[[data-side=right][data-collapsible=offcanvas]_&]:-left-2",
        className
      )}
      {...props}
    />
  )
}

function SidebarInset({ className, ...props }: React.ComponentProps<"main">) {
  return (
    <main
      data-slot="sidebar-inset"
      className={cn(
        "bg-page relative flex max-w-full min-h-svh flex-1 flex-col",
        "peer-data-[variant=inset]:min-h-[calc(100svh-(--spacing(4)))] md:peer-data-[variant=inset]:m-2 md:peer-data-[variant=inset]:ml-0 md:peer-data-[variant=inset]:rounded-xl md:peer-data-[variant=inset]:peer-data-[state=collapsed]:ml-0",
        className
      )}
      {...props}
    />
  )
}

function SidebarInput({
  className,
  ...props
}: React.ComponentProps<typeof Input>) {
  return (
    <Input
      data-slot="sidebar-input"
      data-sidebar="input"
      className={cn("bg-page h-8 w-full shadow-none", className)}
      {...props}
    />
  )
}

function SidebarHeader({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="sidebar-header"
      data-sidebar="header"
      className={cn("flex flex-col gap-2 p-2", className)}
      {...props}
    />
  )
}

function SidebarFooter({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="sidebar-footer"
      data-sidebar="footer"
      className={cn("flex flex-col gap-2 p-2", className)}
      {...props}
    />
  )
}

function SidebarSeparator({
  className,
  ...props
}: React.ComponentProps<typeof Separator>) {
  return (
    <Separator
      data-slot="sidebar-separator"
      data-sidebar="separator"
      className={cn("bg-line mx-2 w-auto", className)}
      {...props}
    />
  )
}

function SidebarContent({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="sidebar-content"
      data-sidebar="content"
      className={cn(
        "flex min-h-0 flex-1 flex-col gap-2 overflow-auto group-data-[collapsible=icon]:overflow-hidden",
        className
      )}
      {...props}
    />
  )
}

function SidebarGroup({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="sidebar-group"
      data-sidebar="group"
      className={cn("relative flex w-full min-w-0 flex-col p-2", className)}
      {...props}
    />
  )
}

const groupLabelClass = cn(
  "flex h-8 shrink-0 items-center rounded-md px-3 text-caption font-semibold tracking-[0.05em] text-ink-3 uppercase outline-hidden transition-[margin,opacity] duration-(--dur-2) ease-(--ease) focus-visible:outline-2 focus-visible:outline-ring [&>svg]:size-4 [&>svg]:shrink-0",
  "group-data-[collapsible=icon]:-mt-8 group-data-[collapsible=icon]:opacity-0 group-data-[collapsible=icon]:select-none group-data-[collapsible=icon]:pointer-events-none"
)

function SidebarGroupLabel({
  className,
  asChild = false,
  ...props
}: React.ComponentProps<"div"> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : "div"

  return (
    <Comp
      data-slot="sidebar-group-label"
      data-sidebar="group-label"
      className={cn(groupLabelClass, className)}
      {...props}
    />
  )
}

/** A group's heading that folds the group: a button, `aria-expanded` from the caller; hidden, and out of the tab order, on the rail. */
function SidebarGroupFold({
  className,
  ...props
}: React.ComponentProps<"button">) {
  return (
    <button
      type="button"
      data-slot="sidebar-group-fold"
      data-sidebar="group-label"
      className={cn(groupLabelClass, "w-full justify-between hover:text-ink group-data-[collapsible=icon]:invisible", className)}
      {...props}
    />
  )
}

function SidebarGroupAction({
  className,
  asChild = false,
  ...props
}: React.ComponentProps<"button"> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : "button"

  return (
    <Comp
      data-slot="sidebar-group-action"
      data-sidebar="group-action"
      className={cn(
        "text-ink-2 ring-ring hover:bg-accent-soft hover:text-on-accent-soft absolute top-3.5 right-3 flex aspect-square w-5 items-center justify-center rounded-md p-0 outline-hidden transition-transform focus-visible:ring-2 [&>svg]:size-4 [&>svg]:shrink-0",
        // Increases the hit area of the button on mobile.
        "after:absolute after:-inset-2 md:after:hidden",
        "group-data-[collapsible=icon]:hidden",
        className
      )}
      {...props}
    />
  )
}

function SidebarGroupContent({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="sidebar-group-content"
      data-sidebar="group-content"
      className={cn("w-full text-body", className)}
      {...props}
    />
  )
}

function SidebarMenu({ className, ...props }: React.ComponentProps<"ul">) {
  return (
    <ul
      data-slot="sidebar-menu"
      data-sidebar="menu"
      className={cn("flex w-full min-w-0 flex-col gap-1", className)}
      {...props}
    />
  )
}

function SidebarMenuItem({ className, ...props }: React.ComponentProps<"li">) {
  return (
    <li
      data-slot="sidebar-menu-item"
      data-sidebar="menu-item"
      className={cn("group/menu-item relative", className)}
      {...props}
    />
  )
}

const sidebarMenuButtonVariants = cva(
  "peer/menu-button flex min-h-9.5 w-full items-center gap-3 overflow-hidden rounded-md px-3 text-start text-body font-medium text-ink-2 outline-hidden transition-[width,height,padding,background-color,color] duration-(--dur-1) ease-(--ease) hover:bg-surface-3 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring active:bg-surface-3 active:text-ink disabled:pointer-events-none disabled:opacity-55 group-has-data-[sidebar=menu-action]/menu-item:pe-8 aria-disabled:pointer-events-none aria-disabled:opacity-55 data-[active=true]:bg-accent-soft data-[active=true]:text-on-accent-soft data-[state=open]:hover:bg-surface-3 data-[state=open]:hover:text-ink group-data-[collapsible=icon]:size-8! group-data-[collapsible=icon]:p-2! [&>span:last-child]:truncate [&>svg]:size-5 [&>svg]:shrink-0",
  {
    variants: {
      variant: {
        default: "",
        outline: "bg-surface inset-ring inset-ring-edge",
      },
      size: {
        default: "",
        sm: "min-h-(--ctl-h-sm) text-small",
        lg: "min-h-12 group-data-[collapsible=icon]:p-0!",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function SidebarMenuButton({
  asChild = false,
  isActive = false,
  variant = "default",
  size = "default",
  tooltip,
  className,
  onClick,
  ...props
}: React.ComponentProps<"button"> & {
  asChild?: boolean
  isActive?: boolean
  tooltip?: string | React.ComponentProps<typeof TooltipContent>
} & VariantProps<typeof sidebarMenuButtonVariants>) {
  const Comp = asChild ? Slot : "button"
  const { isMobile, state, setOpenMobile } = useSidebar()

  const button = (
    <Comp
      data-slot="sidebar-menu-button"
      data-sidebar="menu-button"
      data-size={size}
      data-active={isActive}
      className={cn(sidebarMenuButtonVariants({ variant, size }), className)}
      onClick={(event) => {
        if (isMobile) {
          setOpenMobile(false)
        }
        onClick?.(event)
      }}
      {...props}
    />
  )

  if (!tooltip) {
    return button
  }

  if (typeof tooltip === "string") {
    tooltip = {
      children: tooltip,
    }
  }

  return (
    <Tooltip>
      <TooltipTrigger asChild>{button}</TooltipTrigger>
      <TooltipContent
        side="right"
        align="center"
        hidden={state !== "collapsed" || isMobile}
        {...tooltip}
      />
    </Tooltip>
  )
}

function SidebarMenuAction({
  className,
  asChild = false,
  showOnHover = false,
  ...props
}: React.ComponentProps<"button"> & {
  asChild?: boolean
  showOnHover?: boolean
}) {
  const Comp = asChild ? Slot : "button"

  return (
    <Comp
      data-slot="sidebar-menu-action"
      data-sidebar="menu-action"
      className={cn(
        "text-ink-2 ring-ring hover:bg-accent-soft hover:text-on-accent-soft peer-hover/menu-button:text-on-accent-soft absolute top-1.5 right-1 flex aspect-square w-5 items-center justify-center rounded-md p-0 outline-hidden transition-transform focus-visible:ring-2 [&>svg]:size-4 [&>svg]:shrink-0",
        // Increases the hit area of the button on mobile.
        "after:absolute after:-inset-2 md:after:hidden",
        "peer-data-[size=sm]/menu-button:top-1",
        "peer-data-[size=default]/menu-button:top-1.5",
        "peer-data-[size=lg]/menu-button:top-2.5",
        "group-data-[collapsible=icon]:hidden",
        showOnHover &&
          "peer-data-[active=true]/menu-button:text-on-accent-soft group-focus-within/menu-item:opacity-100 group-hover/menu-item:opacity-100 data-[state=open]:opacity-100 md:opacity-0",
        className
      )}
      {...props}
    />
  )
}

function SidebarMenuBadge({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="sidebar-menu-badge"
      data-sidebar="menu-badge"
      className={cn(
        badgeVariants({ variant: "secondary" }),
        "pointer-events-none absolute end-2 top-1/2 -translate-y-1/2 select-none",
        "group-data-[collapsible=icon]:hidden",
        className
      )}
      {...props}
    />
  )
}

function SidebarMenuSkeleton({
  className,
  showIcon = false,
  ...props
}: React.ComponentProps<"div"> & {
  showIcon?: boolean
}) {

  // wrapping in useState to ensure the width is stable across renders
  // also ensures we have a stable reference to the style object
  const [skeletonStyle] = React.useState(() => (
      {
        "--skeleton-width": `${Math.floor(Math.random() * 40) + 50}%` // Random width between 50 to 90%.
    } as React.CSSProperties
  ))

  return (
    <div
      data-slot="sidebar-menu-skeleton"
      data-sidebar="menu-skeleton"
      className={cn("flex h-8 items-center gap-2 rounded-md px-2", className)}
      {...props}
    >
      {showIcon && (
        <Skeleton
          className="size-4 rounded-md"
          data-sidebar="menu-skeleton-icon"
        />
      )}
      <Skeleton
        className="h-4 max-w-(--skeleton-width) flex-1"
        data-sidebar="menu-skeleton-text"
        style={skeletonStyle}
      />
    </div>
  )
}

function SidebarMenuSub({ className, ...props }: React.ComponentProps<"ul">) {
  return (
    <ul
      data-slot="sidebar-menu-sub"
      data-sidebar="menu-sub"
      className={cn(
        "ms-6 flex min-w-0 flex-col gap-0.5 border-s border-line py-1 ps-2",
        "group-data-[collapsible=icon]:hidden",
        className
      )}
      {...props}
    />
  )
}

function SidebarMenuSubItem({
  className,
  ...props
}: React.ComponentProps<"li">) {
  return (
    <li
      data-slot="sidebar-menu-sub-item"
      data-sidebar="menu-sub-item"
      className={cn("group/menu-sub-item relative", className)}
      {...props}
    />
  )
}

const subButtonClass =
  "flex min-h-8 w-full min-w-0 items-center gap-2 overflow-hidden rounded-md px-3 text-start font-medium text-ink-2 outline-hidden transition-[background-color,color] duration-(--dur-1) ease-(--ease) hover:bg-surface-3 hover:text-ink focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring active:bg-surface-3 disabled:pointer-events-none disabled:opacity-55 aria-disabled:pointer-events-none aria-disabled:opacity-55 [&>span:first-child]:truncate [&>svg]:size-4 [&>svg]:shrink-0 group-data-[collapsible=icon]:hidden"

function SidebarMenuSubButton({
  asChild = false,
  size = "md",
  isActive = false,
  className,
  onClick,
  ...props
}: React.ComponentProps<"a"> & {
  asChild?: boolean
  size?: "sm" | "md"
  isActive?: boolean
}) {
  const Comp = asChild ? Slot : "a"
  const { isMobile, setOpenMobile } = useSidebar()

  return (
    <Comp
      data-slot="sidebar-menu-sub-button"
      data-sidebar="menu-sub-button"
      data-size={size}
      data-active={isActive}
      className={cn(
        subButtonClass,
        "data-[active=true]:bg-accent-soft data-[active=true]:text-on-accent-soft",
        size === "sm" && "text-caption",
        size === "md" && "text-small",
        "group-data-[collapsible=icon]:hidden",
        className
      )}
      onClick={(event) => {
        if (isMobile) {
          setOpenMobile(false)
        }
        onClick?.(event)
      }}
      {...props}
    />
  )
}

/**
 * A planned sub-entry (I-3408): a button, never a link, that stays clickable
 * so it can say it opens nothing yet, and quiet.
 */
function SidebarMenuSubSoon({
  className,
  ...props
}: React.ComponentProps<"button">) {
  return (
    <button
      type="button"
      aria-disabled="true"
      data-slot="sidebar-menu-sub-button"
      data-sidebar="menu-sub-button"
      className={cn(
        subButtonClass,
        "text-small text-ink-3 aria-disabled:pointer-events-auto aria-disabled:opacity-100",
        className
      )}
      {...props}
    />
  )
}

export {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupAction,
  SidebarGroupContent,
  SidebarGroupFold,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarInput,
  SidebarInset,
  SidebarMenu,
  SidebarMenuAction,
  SidebarMenuBadge,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarMenuSkeleton,
  SidebarMenuSub,
  SidebarMenuSubButton,
  SidebarMenuSubItem,
  SidebarMenuSubSoon,
  SidebarProvider,
  SidebarRail,
  SidebarSeparator,
  SidebarTrigger,
  useSidebar,
}
