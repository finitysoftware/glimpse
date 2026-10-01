/** First and last name when the account has them, otherwise the username. */
export function displayName(user: {
  firstName: string | null
  lastName: string | null
  username: string
}): string {
  return [user.firstName, user.lastName].filter(Boolean).join(' ') || user.username
}

export function formatDate(value: string | null, time = false): string {
  if (!value) return 'Never'
  const date = new Date(value)
  return time
    ? date.toLocaleString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      })
    : date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
}

export function formatBytes(bytes: number): string {
  if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)} MiB`
  if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)} KiB`
  return `${bytes} B`
}
