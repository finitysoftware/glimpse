import {
  useEffect,
  useRef,
  useState,
  type CSSProperties,
  type KeyboardEvent,
  type PointerEvent,
} from 'react'
import styles from './ResizablePane.module.css'

const MIN_WIDTH = 200
const MAX_WIDTH = 640

/** Widths chosen in this tab, by pane label, so a pane keeps its width when the preview opens another path. */
const chosenWidths = new Map<string, number>()

/**
 * A side panel the owner resizes by dragging the separator or with the arrow keys, Home and End.
 * The container gets the width as --pane-width through paneStyle; the separator goes after the panel.
 * `enabled` is false while the container is not rendered.
 */
export function useResizablePane(label: string, initialWidth: number, enabled = true) {
  const container = useRef<HTMLDivElement>(null)
  const [chosen, setChosen] = useState(() => chosenWidths.get(label) ?? initialWidth)
  // The rest of the layout keeps at least 326 px; a narrow window shrinks the panel without forgetting its width.
  const [maximum, setMaximum] = useState(MAX_WIDTH)
  const width = Math.max(MIN_WIDTH, Math.min(maximum, chosen))
  const drag = useRef<{ x: number; width: number } | null>(null)

  useEffect(() => {
    const element = container.current
    if (!element) return
    const measure = () =>
      setMaximum(
        Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, element.getBoundingClientRect().width - 326)),
      )
    measure()
    const observer = new ResizeObserver(measure)
    observer.observe(element)
    return () => observer.disconnect()
  }, [enabled])

  function resize(value: number) {
    const next = Math.round(Math.max(MIN_WIDTH, Math.min(maximum, value)))
    chosenWidths.set(label, next)
    setChosen(next)
  }

  function key(event: KeyboardEvent<HTMLDivElement>) {
    const targets: Record<string, number> = {
      ArrowLeft: width - 20,
      ArrowRight: width + 20,
      Home: MIN_WIDTH,
      End: maximum,
    }
    const next = targets[event.key]
    if (next === undefined) return
    event.preventDefault()
    resize(next)
  }

  function stop(event: PointerEvent<HTMLDivElement>) {
    drag.current = null
    if (event.currentTarget.hasPointerCapture(event.pointerId))
      event.currentTarget.releasePointerCapture(event.pointerId)
  }

  const separator = (
    <div
      className={styles.separator}
      role="separator"
      tabIndex={0}
      aria-label={label}
      aria-orientation="vertical"
      aria-valuemin={MIN_WIDTH}
      aria-valuemax={maximum}
      aria-valuenow={width}
      aria-valuetext={`${width} pixels`}
      onKeyDown={key}
      onDoubleClick={() => resize(initialWidth)}
      onPointerDown={event => {
        if (event.button !== 0) return
        event.preventDefault()
        event.currentTarget.focus()
        event.currentTarget.setPointerCapture(event.pointerId)
        drag.current = { x: event.clientX, width }
      }}
      onPointerMove={event => {
        if (drag.current) resize(drag.current.width + event.clientX - drag.current.x)
      }}
      onPointerUp={stop}
      onPointerCancel={stop}
      onLostPointerCapture={() => {
        drag.current = null
      }}
    />
  )
  const paneStyle = { '--pane-width': `${width}px` } as CSSProperties
  return { container, paneStyle, separator }
}
