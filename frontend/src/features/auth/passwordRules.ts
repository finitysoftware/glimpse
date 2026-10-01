/** Same rules as the server: 8-128 characters; the server also limits UTF-8 bytes. */
export function passwordProblem(password: string): string {
  const length = [...password].length
  return length < 8 || length > 128 ? 'Use 8-128 characters.' : ''
}

/** Strong password from an alphabet without look-alike characters, generated in the browser for the password field. */
export function generatePassword(length = 20): string {
  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_.!'
  const values = crypto.getRandomValues(new Uint32Array(length))
  return Array.from(values, value => alphabet[value % alphabet.length]).join('')
}
