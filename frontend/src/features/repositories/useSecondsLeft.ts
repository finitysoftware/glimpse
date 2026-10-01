import { useEffect, useState } from 'react'

/**
 * Whole seconds until `until` (a time in milliseconds), counted down while it is on screen; 0 once it
 * has passed. `since` is when the wait began, e.g. when the failure that asked for it arrived.
 */
export function useSecondsLeft(until: number, since: number): number {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    if (until <= Date.now()) return
    const timer = window.setInterval(() => {
      const time = Date.now()
      setNow(time)
      if (time >= until) window.clearInterval(timer)
    }, 250)
    return () => window.clearInterval(timer)
  }, [until])
  return Math.max(0, Math.ceil((until - Math.max(now, since)) / 1000))
}
