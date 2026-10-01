import { passwordProblem } from '../../auth/passwordRules'
import {
  createUser,
  replaceGitHubToken,
  replaceGitLabConnection,
  updateUser,
  type AdminUserDetail,
} from '../api'

export interface UserForm {
  firstName: string
  lastName: string
  email: string
  username: string
  password: string
  requireChange: boolean
  role: 'user' | 'admin'
  active: boolean
}

/** The first problem the browser can find before anything is sent, or '' when there is none. */
export function formProblem(
  form: UserForm,
  editing: boolean,
  gitlab: { url: string; savedUrl: string; token: string },
): string {
  if (!form.firstName.trim() || !form.lastName.trim()) return 'Enter the first and last name.'
  if (!/^[a-z0-9_.-]{3,32}$/i.test(form.username.trim()))
    return 'Use 3-32 characters for the username: letters, digits, "_", "-" or ".".'
  // When editing, an empty password keeps the current one.
  if (!editing || form.password) {
    const problem = passwordProblem(form.password)
    if (problem) return problem
  }
  if (gitlab.url !== gitlab.savedUrl && gitlab.url !== '' && gitlab.token === '')
    return 'Enter the GitLab token for this GitLab URL.'
  if (gitlab.token !== '' && gitlab.url === '') return 'Enter the GitLab URL for this GitLab token.'
  return ''
}

/** Tokens typed into the dialog that differ from the saved ones. */
export interface NewTokens {
  github?: string
  gitlab?: { url: string; token: string }
}

/**
 * Creates the account, or saves only the changed fields of an existing one and then its new tokens.
 * The version detects edits made by someone else in the meantime. onAccountSaved receives the account
 * before the token steps, so a failing token step keeps the account changes and their new version.
 */
export async function saveUser(
  current: AdminUserDetail | null,
  form: UserForm,
  tokens: NewTokens,
  csrfToken: string,
  onAccountSaved: (user: AdminUserDetail) => void,
): Promise<AdminUserDetail> {
  if (!current)
    return createUser(
      {
        firstName: form.firstName,
        lastName: form.lastName,
        email: form.email,
        username: form.username,
        password: form.password,
        requirePasswordChange: form.requireChange,
        role: form.role,
        ...(tokens.github && { githubToken: tokens.github }),
        ...(tokens.gitlab && { gitlabUrl: tokens.gitlab.url, gitlabToken: tokens.gitlab.token }),
      },
      csrfToken,
    )
  const changes: Record<string, unknown> = {}
  if (form.firstName !== (current.firstName ?? '')) changes.firstName = form.firstName
  if (form.lastName !== (current.lastName ?? '')) changes.lastName = form.lastName
  if (form.email !== (current.email ?? '')) changes.email = form.email
  if (form.username.trim().toLowerCase() !== current.username) changes.username = form.username
  if (form.password) {
    changes.password = form.password
    changes.requirePasswordChange = form.requireChange
  }
  if (form.role !== current.role) changes.role = form.role
  if (form.active !== current.active) changes.active = form.active
  let saved = Object.keys(changes).length
    ? await updateUser(current.id, { expectedVersion: current.version, ...changes }, csrfToken)
    : current
  onAccountSaved(saved)
  if (tokens.github)
    saved = await replaceGitHubToken(
      current.id,
      tokens.github,
      saved.connection.credentialVersion,
      csrfToken,
    )
  if (tokens.gitlab)
    saved = await replaceGitLabConnection(
      current.id,
      tokens.gitlab.url,
      tokens.gitlab.token,
      saved.gitlabConnection.credentialVersion,
      csrfToken,
    )
  return saved
}
