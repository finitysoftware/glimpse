/** The first line of a commit message. */
export const title = (message: string) => message.split('\n')[0] || 'Untitled commit'

/** A commit date as a day, or with the time. */
export function dateLabel(value: string | null, time = false): string {
  if (!value) return 'Unknown date'
  if (time) return new Date(value).toLocaleString()
  return new Date(value).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}
