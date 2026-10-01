/** A count with its noun, e.g. "1 file" or "1,200 files"; nouns with an irregular plural pass it. */
export function plural(count: number, noun: string, nouns = `${noun}s`): string {
  return `${count.toLocaleString()} ${count === 1 ? noun : nouns}`
}
