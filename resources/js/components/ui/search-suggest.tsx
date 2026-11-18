import { Search } from "lucide-react"
import { Fragment, useEffect, useId, useRef, useState } from "react"
import type { CSSProperties, KeyboardEvent, ReactNode, Ref } from "react"

import { Input } from "@/components/ui/input"

/** One result under the box: its name and the line under it. */
export type SearchSuggestion = { key: string; label: string; line: string }

/** The list's width: wider than a side column, never wider than the window. */
const LIST = {
  "--suggest-w": "384px",
  "--suggest-max": "calc(100vw - 32px)",
} as CSSProperties

/** The text with each typed word marked where it appears. */
function marked(text: string, words: string[]): ReactNode {
  const escaped = words
    .filter((word) => word !== "")
    .map((word) => word.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"))

  if (escaped.length === 0) {
    return text
  }

  const pattern = new RegExp(`(${escaped.join("|")})`, "ig")

  return text.split(pattern).map((part, index) =>
    index % 2 === 1 ? (
      <mark key={index} className="rounded-xs bg-accent-soft text-on-accent-soft">
        {part}
      </mark>
    ) : (
      <Fragment key={index}>{part}</Fragment>
    )
  )
}

/**
 * A search box whose results open as a list under it, as you type
 * (docs/architecture/design-system.md, 4.7 and 4.42's "Find a setting"):
 * the first results shown with the typed words marked, ↑↓ to move, Enter to
 * choose, Escape to close, a line for nothing found and one for the results
 * left out. The caller does the searching and says what was answered.
 */
function SearchSuggest({
  value,
  onValueChange,
  results,
  answered,
  total,
  onChoose,
  label,
  listLabel,
  emptyText,
  moreText,
  status,
  inputRef,
  id,
  "data-test": testId,
}: {
  value: string
  onValueChange: (value: string) => void
  /** The results to show, already cut to how many the list holds. */
  results: SearchSuggestion[]
  /** Whether the results answer what is typed now. */
  answered: boolean
  /** How many there are in all, shown or not. */
  total: number
  onChoose: (result: SearchSuggestion) => void
  label: string
  listLabel: string
  emptyText: string
  /** The line for results left out, given how many. */
  moreText: (count: number) => string
  /** Read out to screen readers when the results change. */
  status: string
  inputRef?: Ref<HTMLInputElement>
  id?: string
  "data-test"?: string
}) {
  const [open, setOpen] = useState(false)
  const [selected, setSelected] = useState(0)
  const box = useRef<HTMLDivElement>(null)
  const listId = useId()
  const showing = open && answered && value.trim() !== ""
  const words = value.trim().toLowerCase().split(/\s+/)
  const more = total - results.length

  // A click outside closes the list.
  useEffect(() => {
    const onDown = (event: MouseEvent) => {
      if (!box.current?.contains(event.target as Node)) {
        setOpen(false)
      }
    }

    document.addEventListener("mousedown", onDown)

    return () => document.removeEventListener("mousedown", onDown)
  }, [])

  const choose = (result: SearchSuggestion) => {
    setOpen(false)
    onChoose(result)
  }

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    const count = results.length

    if (event.key === "ArrowDown" && showing && count > 0) {
      event.preventDefault()
      setSelected((index) => (index + 1) % count)
    } else if (event.key === "ArrowUp" && showing && count > 0) {
      event.preventDefault()
      setSelected((index) => (index - 1 + count) % count)
    } else if (event.key === "Enter" && showing && results[selected]) {
      event.preventDefault()
      choose(results[selected])
    } else if (event.key === "Escape") {
      if (showing) {
        event.preventDefault()
        event.stopPropagation()
        setOpen(false)
      } else if (value !== "") {
        onValueChange("")
      }
    }
  }

  return (
    <div ref={box} role="search" className="relative">
      <Search
        aria-hidden="true"
        className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-ink-3"
      />
      <Input
        ref={inputRef}
        id={id}
        type="search"
        value={value}
        onChange={(event) => {
          onValueChange(event.target.value)
          setSelected(0)
          setOpen(true)
        }}
        onFocus={() => setOpen(true)}
        onKeyDown={onKeyDown}
        placeholder={label}
        aria-label={label}
        role="combobox"
        aria-expanded={showing}
        aria-controls={listId}
        aria-autocomplete="list"
        aria-activedescendant={
          showing && results[selected] ? `${listId}-${selected}` : undefined
        }
        autoComplete="off"
        className="ps-10"
        data-test={testId}
      />
      <ul
        id={listId}
        role="listbox"
        aria-label={listLabel}
        hidden={!showing}
        data-test={testId === undefined ? undefined : `${testId}-list`}
        style={LIST}
        className="absolute start-0 top-full z-(--z-float) mt-1 grid w-(--suggest-w) max-w-(--suggest-max) gap-0.5 rounded-lg bg-surface p-1 shadow-float"
      >
        {results.map((result, index) => (
          <li
            key={result.key}
            id={`${listId}-${index}`}
            role="option"
            aria-selected={index === selected}
            data-test={testId === undefined ? undefined : `${testId}-item`}
            data-key={result.key}
            onMouseDown={(event) => event.preventDefault()}
            onMouseEnter={() => setSelected(index)}
            onClick={() => choose(result)}
            className="grid cursor-pointer gap-0.5 rounded-md px-3 py-2 aria-selected:bg-surface-3"
          >
            <span className="text-body font-medium text-ink">
              {marked(result.label, words)}
            </span>
            <span className="text-small text-ink-3">{result.line}</span>
          </li>
        ))}
        {answered && total === 0 && (
          <li role="presentation" className="p-3 text-small text-ink-2">
            {emptyText}
          </li>
        )}
        {more > 0 && (
          <li role="presentation" className="p-3 text-small text-ink-2">
            {moreText(more)}
          </li>
        )}
      </ul>
      <span className="sr-only" role="status">
        {status}
      </span>
    </div>
  )
}

export { SearchSuggest }
