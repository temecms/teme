import {
  useCombobox,
  useMultipleSelection,
  type UseComboboxState,
  type UseComboboxStateChangeOptions,
} from "downshift"
import { CheckIcon, ChevronsUpDownIcon, SearchIcon, type LucideIcon } from "lucide-react"
import * as React from "react"

import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { ScrollArea } from "@/components/ui/scroll-area"
import { Spinner } from "@/components/ui/spinner"
import { StatusPill } from "@/components/ui/status-pill"
import { __, __n } from "@/lib/i18n"
import { cn } from "@/lib/utils"

export type ComboboxOption = {
  value: string
  label: string
  description?: string
  icon?: LucideIcon
  keywords?: string[]
  group?: string
  disabled?: boolean
  /** Choosing it closes the list even where several may be chosen, as an action rather than a choice does. */
  closes?: boolean
}

type ComboboxBase = {
  options: ComboboxOption[]
  id?: string
  placeholder: string
  searchPlaceholder: string
  /** Shown on the button in place of the chosen labels (the tag picker shows its tags beside it). */
  triggerLabel?: string
  emptyText?: string
  /** Remote search: the caller fetches and passes `options`; local filtering is then off. */
  search?: { value: string; onChange: (value: string) => void; loading: boolean }
  /** Writes one hidden input per chosen value under this name, as given (`terms[]`). */
  name?: string
  disabled?: boolean
  size?: "md" | "sm"
  "aria-describedby"?: string
  "aria-invalid"?: boolean
}

export type ComboboxProps = ComboboxBase &
  (
    | {
        multiple?: false
        value: ComboboxOption | null
        onValueChange: (value: ComboboxOption | null) => void
      }
    | {
        multiple: true
        value: ComboboxOption[]
        onValueChange: (value: ComboboxOption[]) => void
        /** The removable tags beside the button; their remove button's name. */
        removeLabel: (option: ComboboxOption) => string
      }
  )

/** Matches when every word typed is among the option's words; the list keeps its own order. */
export function matchesEveryWord(option: ComboboxOption, search: string): boolean {
  const words = search
    .toLocaleLowerCase()
    .split(/\s+/)
    .filter((word) => word !== "")
  const haystack = [option.label, ...(option.keywords ?? [])].join(" ").toLocaleLowerCase()

  return words.every((word) => haystack.includes(word))
}

const itemToKey = (option: ComboboxOption | null) => option?.value
const itemToString = (option: ComboboxOption | null) => option?.label ?? ""

// The popover's search is never the value: leaving or Esc chooses nothing and clears nothing.
function comboboxReducer(multiple: boolean, loading: boolean) {
  return (
    state: UseComboboxState<ComboboxOption>,
    { type, changes, props }: UseComboboxStateChangeOptions<ComboboxOption>
  ): Partial<UseComboboxState<ComboboxOption>> => {
    switch (type) {
      case useCombobox.stateChangeTypes.InputBlur:
        // Downshift chooses the highlighted item on blur; here only Enter or a click chooses.
        return { ...changes, selectedItem: state.selectedItem, inputValue: state.inputValue }
      case useCombobox.stateChangeTypes.InputKeyDownEscape:
        // Downshift clears the choice on a second Esc; here Esc only closes.
        return { ...changes, selectedItem: state.selectedItem, inputValue: state.inputValue }
      case useCombobox.stateChangeTypes.InputClick:
        // The search sits inside an open popover: a click there keeps it open.
        return { ...changes, isOpen: state.isOpen }
      case useCombobox.stateChangeTypes.ControlledPropUpdatedSelectedItem:
        // A new choice never writes its name into the search.
        return { ...changes, inputValue: state.inputValue }
      case useCombobox.stateChangeTypes.InputKeyDownEnter:
      case useCombobox.stateChangeTypes.ItemClick: {
        // Enter with nothing highlighted and one result chooses that result, never
        // while a new search is loading and the result shown is the last search's.
        const only =
          type === useCombobox.stateChangeTypes.InputKeyDownEnter &&
          !loading &&
          state.highlightedIndex < 0 &&
          props.items.length === 1 &&
          !props.items[0].disabled
            ? { selectedItem: props.items[0] }
            : {}
        const chosen = { ...changes, ...only }

        if (multiple && !chosen.selectedItem?.closes) {
          return {
            ...chosen,
            isOpen: true,
            highlightedIndex: state.highlightedIndex,
            inputValue: state.inputValue,
          }
        }

        return { ...chosen, isOpen: false, inputValue: "" }
      }
      default:
        return changes
    }
  }
}

/** The option's rows in order, with a heading wherever its group changes. */
function rows(options: ComboboxOption[]) {
  const groups: { name?: string; items: { option: ComboboxOption; index: number }[] }[] = []

  options.forEach((option, index) => {
    const last = groups[groups.length - 1]

    if (last === undefined || last.name !== option.group) {
      groups.push({ name: option.group, items: [{ option, index }] })
    } else {
      last.items.push({ option, index })
    }
  })

  return groups
}

/**
 * The chosen names on one line, then "and N more" for those that do not fit
 * (docs/proposals/combobox.md, Settled 2). Measured in a hidden copy.
 */
function ChosenNames({ names }: { names: string[] }) {
  const lineRef = React.useRef<HTMLSpanElement>(null)
  const measureRef = React.useRef<HTMLSpanElement>(null)
  const [shown, setShown] = React.useState(names.length)

  React.useLayoutEffect(() => {
    const line = lineRef.current
    const measure = measureRef.current

    if (line === null || measure === null) {
      return
    }

    const fit = () => {
      const room = line.clientWidth
      // The hidden copy holds each name, then the "and N more" note last.
      const all = Array.from(measure.children) as HTMLElement[]
      const pieces = all.slice(0, -1)
      const more = all[all.length - 1]?.offsetWidth ?? 0
      let used = 0
      let count = 0

      for (const piece of pieces) {
        const next = used + piece.offsetWidth
        const rest = count + 1 < pieces.length ? more : 0

        if (next + rest > room && count > 0) {
          break
        }

        used = next
        count += 1
      }

      setShown(Math.max(1, count))
    }

    fit()

    // Measured a frame later, so the change it makes is never inside the observer's own loop.
    let frame = 0
    const observer = new ResizeObserver(() => {
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(fit)
    })
    observer.observe(line)

    return () => {
      cancelAnimationFrame(frame)
      observer.disconnect()
    }
  }, [names])

  const hidden = names.length - shown

  return (
    <span ref={lineRef} data-slot="combobox-names" className="relative flex min-w-0 flex-1 overflow-hidden">
      <span className="truncate">
        {names.slice(0, shown).join(", ")}
        {hidden > 0 && " " + __("and :count more", { count: hidden })}
      </span>
      <span
        ref={measureRef}
        aria-hidden="true"
        className="invisible absolute start-0 top-0 flex whitespace-nowrap"
      >
        {names.map((name, index) => (
          <span key={index}>
            {index === 0 ? name : ", " + name}
          </span>
        ))}
        <span>{" " + __("and :count more", { count: names.length })}</span>
      </span>
    </span>
  )
}

/**
 * One searchable list for the whole admin (docs/architecture/design-system.md,
 * section 4.7): a button like a Select opens a popover whose search field is
 * the combobox (I-3436). Several may be chosen, shown beside it as tags.
 */
function Combobox(props: ComboboxProps) {
  const {
    options,
    id,
    placeholder,
    searchPlaceholder,
    triggerLabel,
    emptyText,
    search,
    name,
    disabled = false,
    size = "md",
  } = props
  const multiple = props.multiple === true
  const chosenList = React.useMemo(
    () => (props.multiple === true ? props.value : props.value === null ? [] : [props.value]),
    [props.multiple, props.value]
  )
  const chosenKeys = new Set(chosenList.map((option) => option.value))

  const baseId = React.useId()
  const inputRef = React.useRef<HTMLInputElement>(null)
  const triggerRef = React.useRef<HTMLButtonElement>(null)
  const [open, setOpen] = React.useState(false)
  const [localSearch, setLocalSearch] = React.useState("")
  const [opening, setOpening] = React.useState<ComboboxOption[]>([])
  const searchValue = search?.value ?? localSearch
  const previousChosen = React.useRef(chosenList)

  const changeSearch = (next: string) => {
    if (search) {
      search.onChange(next)
    } else {
      setLocalSearch(next)
    }
  }

  // Fixed as it opens: ticking an item never moves it under the pointer.
  const changeOpen = (next: boolean, typed = "") => {
    if (next) {
      changeSearch(typed)

      if (multiple) {
        setOpening([...chosenList, ...options.filter((option) => !chosenKeys.has(option.value))])
      }
    }

    setOpen(next)
  }

  const shown =
    multiple && searchValue === "" && open
      ? opening
      : search
        ? options
        : options.filter((option) => matchesEveryWord(option, searchValue))

  const multipleSelection = useMultipleSelection<ComboboxOption>({
    selectedItems: multiple ? chosenList : [],
    itemToKey,
    // Backspace on the closed button removes nothing; on a focused tag it still does.
    stateReducer: (state, { type, changes }) =>
      type === useMultipleSelection.stateChangeTypes.DropdownKeyDownBackspace ? state : changes,
    onSelectedItemsChange: ({ selectedItems }) => {
      if (props.multiple === true) {
        props.onValueChange(selectedItems ?? [])
      }
    },
    getA11yStatusMessage: ({ selectedItems }) => {
      if (!multiple) {
        return ""
      }

      const before = previousChosen.current
      previousChosen.current = selectedItems

      if (selectedItems.length < before.length) {
        const gone = before.find((option) => !selectedItems.some((kept) => kept.value === option.value))

        return gone ? __(":name removed", { name: gone.label }) : ""
      }

      if (selectedItems.length > before.length) {
        const added = selectedItems.find((option) => !before.some((had) => had.value === option.value))

        return added ? __(":name added", { name: added.label }) : ""
      }

      return ""
    },
  })

  const combobox = useCombobox<ComboboxOption>({
    id: `${baseId}combobox`,
    items: shown,
    itemToKey,
    itemToString,
    isItemDisabled: (option) => option.disabled === true,
    isOpen: open,
    inputValue: searchValue,
    selectedItem: multiple ? null : (chosenList[0] ?? null),
    stateReducer: comboboxReducer(multiple, search?.loading === true),
    onIsOpenChange: ({ isOpen }) => {
      if (!isOpen) {
        changeOpen(false)
      }
    },
    onSelectedItemChange: ({ selectedItem }) => {
      if (props.multiple === true) {
        if (selectedItem === null) {
          return
        }

        const present = props.value.some((option) => option.value === selectedItem.value)

        if (selectedItem.closes) {
          props.onValueChange([...props.value, selectedItem])
        } else if (present) {
          multipleSelection.removeSelectedItem(
            props.value.find((option) => option.value === selectedItem.value) ?? selectedItem
          )
        } else {
          multipleSelection.addSelectedItem(selectedItem)
        }
      } else {
        props.onValueChange(selectedItem)
      }
    },
    getA11yStatusMessage: ({ isOpen }) => {
      if (!isOpen) {
        return ""
      }

      if (search?.loading) {
        return __("Searching…")
      }

      return shown.length === 0
        ? (emptyText ?? __("Nothing matches."))
        : __n(":count result|:count results", shown.length)
    },
  })

  const { getInputProps, getMenuProps, getItemProps, highlightedIndex } = combobox

  const buttonText =
    triggerLabel ?? (chosenList.length === 0 ? null : chosenList.map((option) => option.label))

  // A single choice's name comes from its label, so the chosen value is read as its description.
  const valueId = `${baseId}value`
  const describesValue = !multiple && triggerLabel === undefined && chosenList.length > 0
  const describedBy =
    [props["aria-describedby"], describesValue ? valueId : null].filter(Boolean).join(" ") || undefined

  const dropdownProps = multiple
    ? multipleSelection.getDropdownProps({ ref: triggerRef, preventKeyAction: open }, { suppressRefError: true })
    : {}

  const onTriggerKeyDown = (event: React.KeyboardEvent<HTMLButtonElement>) => {
    if (event.key === "ArrowDown") {
      event.preventDefault()
      changeOpen(true)
    } else if (
      event.key.length === 1 &&
      event.key !== " " &&
      !event.ctrlKey &&
      !event.metaKey &&
      !event.altKey
    ) {
      // A letter typed on the button opens it with that letter searched.
      event.preventDefault()
      changeOpen(true, event.key)
    }
  }

  return (
    <div data-slot="combobox" className="grid min-w-0 gap-2">
      <Popover open={open} onOpenChange={(next) => changeOpen(next)}>
        <PopoverTrigger asChild>
          <button
            ref={triggerRef}
            type="button"
            id={id}
            disabled={disabled}
            data-slot="combobox-trigger"
            data-size={size}
            data-placeholder={buttonText === null ? "" : undefined}
            aria-describedby={describedBy}
            aria-invalid={props["aria-invalid"]}
            {...dropdownProps}
            onKeyDown={(event) => {
              onTriggerKeyDown(event)
              ;(dropdownProps as { onKeyDown?: React.KeyboardEventHandler<HTMLButtonElement> }).onKeyDown?.(event)
            }}
            className="relative flex w-full min-w-0 items-center gap-2 rounded-md border border-edge bg-surface ps-3 pe-8 text-start text-body text-ink transition-[border-color] duration-(--dur-1) ease-(--ease) outline-none focus-visible:border-transparent focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-0 focus-visible:outline-ring aria-invalid:border-danger disabled:cursor-not-allowed disabled:bg-surface-2 disabled:text-ink-3 data-[placeholder]:text-ink-3 data-[size=md]:h-(--ctl-h) data-[size=sm]:h-(--ctl-h-sm)"
          >
            {buttonText === null ? (
              <span className="truncate">{placeholder}</span>
            ) : typeof buttonText === "string" ? (
              <span className="truncate">{buttonText}</span>
            ) : multiple ? (
              <ChosenNames names={buttonText} />
            ) : (
              <span id={valueId} className="truncate">
                {buttonText.join(", ")}
              </span>
            )}
            <ChevronsUpDownIcon
              aria-hidden="true"
              className="pointer-events-none absolute end-3 size-4 text-ink-2"
            />
          </button>
        </PopoverTrigger>
        <PopoverContent
          align="start"
          aria-label={searchPlaceholder}
          className="w-(--radix-popover-trigger-width) min-w-60 p-1"
          onOpenAutoFocus={(event) => {
            event.preventDefault()
            inputRef.current?.focus()
          }}
        >
          <div
            data-slot="combobox-search"
            className="flex h-(--ctl-h) items-center gap-2 border-b border-line px-3"
          >
            <SearchIcon aria-hidden="true" className="size-4 shrink-0 text-ink-3" />
            <input
              {...getInputProps(
                {
                  ref: inputRef,
                  "aria-label": searchPlaceholder,
                  placeholder: searchPlaceholder,
                  // Set as the key goes in, so a quick typist's next letter lands on this one.
                  onChange: (event) => changeSearch(event.currentTarget.value),
                  onKeyDown: (event) => {
                    // Tab leaves the popover for its button; the blur chooses nothing.
                    if (event.key === "Tab") {
                      event.preventDefault()
                      triggerRef.current?.focus()
                    }
                  },
                },
                { suppressRefError: true }
              )}
              data-slot="combobox-input"
              className="h-full min-w-0 flex-1 rounded-sm bg-transparent text-body text-ink outline-none placeholder:text-ink-3 focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-1 focus-visible:outline-ring"
            />
          </div>
          <ScrollArea label={searchPlaceholder} className="max-h-80 pt-1">
            <div
              {...getMenuProps({ "aria-label": searchPlaceholder }, { suppressRefError: true })}
              data-slot="combobox-list"
            >
              {rows(shown).map((group, groupIndex) => {
                const items = group.items.map(({ option, index }) => {
                  const chosen = chosenKeys.has(option.value)
                  const Icon = option.icon

                  return (
                    <div
                      key={option.value}
                      {...getItemProps({ item: option, index })}
                      aria-checked={multiple ? chosen : undefined}
                      data-slot="combobox-item"
                      data-chosen={chosen ? "" : undefined}
                      className="flex min-h-(--ctl-h) cursor-default items-center gap-3 rounded-md px-3 py-1 text-body text-ink select-none aria-disabled:opacity-55 aria-selected:bg-surface-3"
                    >
                      {Icon && <Icon aria-hidden="true" className="size-5 shrink-0 text-ink-2" />}
                      <span className="grid min-w-0 flex-1">
                        <span className="truncate">{option.label}</span>
                        {option.description && (
                          <span className="truncate text-small text-ink-3">{option.description}</span>
                        )}
                      </span>
                      <CheckIcon
                        aria-hidden="true"
                        className={cn("size-4 shrink-0 text-ink-2", !chosen && "invisible")}
                      />
                    </div>
                  )
                })

                return group.name === undefined ? (
                  <React.Fragment key={`group-${groupIndex}`}>{items}</React.Fragment>
                ) : (
                  <div
                    key={`group-${groupIndex}`}
                    role="group"
                    aria-labelledby={`${baseId}group-${groupIndex}`}
                  >
                    <div
                      id={`${baseId}group-${groupIndex}`}
                      className="px-3 pt-2 pb-1 text-caption font-semibold text-ink-3 uppercase"
                    >
                      {group.name}
                    </div>
                    {items}
                  </div>
                )
              })}
            </div>
            {search?.loading ? (
              <p
                data-slot="combobox-searching"
                className="flex items-center gap-2 px-3 py-2 text-body text-ink-2"
              >
                <Spinner />
                {__("Searching…")}
              </p>
            ) : (
              shown.length === 0 && (
                <p data-slot="combobox-empty" className="px-3 py-2 text-body text-ink-2">
                  {emptyText ?? __("Nothing matches.")}
                </p>
              )
            )}
          </ScrollArea>
        </PopoverContent>
      </Popover>
      {props.multiple === true && props.value.length > 0 && (
        <div data-slot="combobox-chosen" className="flex flex-wrap gap-1">
          {props.value.map((option, index) => (
            <StatusPill
              key={option.value}
              tone="neutral"
              flat
              {...multipleSelection.getSelectedItemProps({ selectedItem: option, index })}
              onRemove={() => multipleSelection.removeSelectedItem(option)}
              removeLabel={props.removeLabel(option)}
              className="outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
            >
              {option.label}
            </StatusPill>
          ))}
        </div>
      )}
      {name !== undefined &&
        (chosenList.length === 0 && !multiple ? (
          <input type="hidden" name={name} value="" />
        ) : (
          chosenList.map((option) => <input key={option.value} type="hidden" name={name} value={option.value} />)
        ))}
    </div>
  )
}

export { Combobox }
