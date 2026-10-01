import { useEffect, useRef, type RefObject } from 'react'
import { useLocation } from 'react-router-dom'

/**
 * When another page opens (a new path, not just new search parameters), focus moves to its h1, so a screen reader
 * announces the page and Tab continues from its top. That includes the first page after signing in, which arrives
 * through an in-app navigation; only the page a browser load opened (location key 'default') leaves focus to the
 * browser. Page headings carry tabIndex -1 for this.
 */
export function useHeadingFocus(main: RefObject<HTMLElement | null>) {
  const { pathname, key } = useLocation()
  const shown = useRef(key === 'default' ? pathname : '')
  useEffect(() => {
    if (shown.current === pathname) return
    shown.current = pathname
    main.current?.querySelector<HTMLElement>('h1')?.focus()
  }, [pathname, main])
}
