import { useState, type FormEvent } from 'react'
import { Eye, EyeOff, Sparkles } from 'lucide-react'
import { useMutation } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { errorMessage } from '../../../api/client'
import { connectionLabels, type ConnectionState, type Platform } from '../../../api/platforms'
import { Dialog } from '../../../components/Dialog'
import common from '../../../styles/app.module.css'
import styles from '../Admin.module.css'
import { removeUserToken, revealUserToken, type AdminUserDetail } from '../api'
import { formatDate } from '../format'
import { generatePassword } from '../../auth/passwordRules'
import { formProblem, saveUser, type UserForm } from './saveUser'
import { TokenRow, type Secret } from './TokenRow'
import { ErrorMessage } from '../../../components/ErrorMessage'

const empty: UserForm = {
  firstName: '',
  lastName: '',
  email: '',
  username: '',
  password: '',
  requireChange: false,
  role: 'user',
  active: true,
}

const hidden: Secret = { value: '', original: '', revealed: false, visible: false }

/** Status, account, token expiry and last check of a saved connection, in one line. */
function describe(
  connection: { status: ConnectionState; lastCheckedAt: string | null },
  account: string | null,
  expiresAt: string | null = null,
): string {
  return (
    connectionLabels[connection.status] +
    (account ? ` as ${account}` : '') +
    (expiresAt ? ` · expires ${formatDate(expiresAt)}` : '') +
    (connection.lastCheckedAt ? ` · checked ${formatDate(connection.lastCheckedAt, true)}` : '')
  )
}

/**
 * Add user / Edit user, laid out like the reference admin panel: an empty password keeps the current one,
 * and the GitHub and GitLab tokens stay hidden until Show loads them from the server.
 */
export function UserDialog({
  user,
  selfId,
  csrfToken,
  onClose,
  onSaved,
}: {
  user: AdminUserDetail | null
  selfId: number
  csrfToken: string
  onClose: () => void
  onSaved: (user: AdminUserDetail) => void
}) {
  const editing = user !== null
  const self = user?.id === selfId
  const [current, setCurrent] = useState<AdminUserDetail | null>(user)
  const [form, setForm] = useState<UserForm>(
    user
      ? {
          firstName: user.firstName ?? '',
          lastName: user.lastName ?? '',
          email: user.email ?? '',
          username: user.username,
          password: '',
          requireChange: false,
          role: user.role,
          active: user.active,
        }
      : empty,
  )
  const [showPassword, setShowPassword] = useState(false)
  const [secrets, setSecrets] = useState<Record<Platform, Secret>>({
    github: hidden,
    gitlab: hidden,
  })
  const [gitlabUrl, setGitlabUrl] = useState(() => {
    const saved = user?.gitlabConnection.baseUrl ?? ''
    return { value: saved, original: saved }
  })
  const [error, setError] = useState('')
  const set = <K extends keyof UserForm>(key: K, value: UserForm[K]) =>
    setForm(previous => ({ ...previous, [key]: value }))
  const updateSecret = (platform: Platform, change: Partial<Secret>) =>
    setSecrets(previous => ({ ...previous, [platform]: { ...previous[platform], ...change } }))
  const configured = {
    github: current?.connection.configured ?? false,
    gitlab: current?.gitlabConnection.configured ?? false,
  }
  const typed = (platform: Platform) => {
    const secret = secrets[platform]
    return secret.value.trim() !== '' && secret.value !== secret.original
  }
  const changed = {
    github: typed('github'),
    // A GitLab token is always saved together with its URL; a new URL needs the token again.
    gitlab:
      secrets.gitlab.value.trim() !== '' &&
      (typed('gitlab') || gitlabUrl.value.trim() !== gitlabUrl.original),
  }

  // Every action starts with a clean message; a 401 ends the admin session through the QueryScope.
  const reveal = useMutation({
    mutationFn: ({ id, platform }: { id: number; platform: Platform }) =>
      revealUserToken(id, platform, csrfToken),
    onMutate: () => setError(''),
    onSuccess: ({ token, baseUrl }, { platform }) => {
      updateSecret(platform, {
        value: token ?? '',
        original: token ?? '',
        revealed: true,
        visible: true,
      })
      if (platform === 'gitlab') setGitlabUrl({ value: baseUrl ?? '', original: baseUrl ?? '' })
    },
    onError: reason => setError(errorMessage(reason, 'Unable to show the token.')),
  })
  const remove = useMutation({
    mutationFn: ({ id, platform }: { id: number; platform: Platform }) =>
      removeUserToken(id, platform, csrfToken),
    onMutate: () => setError(''),
    onSuccess: (updated, { platform }) => {
      setCurrent(updated)
      setSecrets(previous => ({ ...previous, [platform]: hidden }))
      if (platform === 'gitlab') setGitlabUrl({ value: '', original: '' })
      onSaved(updated)
    },
    onError: reason => setError(errorMessage(reason, 'Unable to remove the token.')),
  })
  const save = useMutation({
    mutationFn: () =>
      saveUser(
        current,
        form,
        {
          github: changed.github ? secrets.github.value.trim() : undefined,
          gitlab: changed.gitlab
            ? { url: gitlabUrl.value.trim(), token: secrets.gitlab.value.trim() }
            : undefined,
        },
        csrfToken,
        setCurrent,
      ),
    onMutate: () => setError(''),
    onSuccess: saved => {
      onSaved(saved)
      onClose()
    },
    onError: reason => setError(errorMessage(reason, 'Unable to save the account.')),
  })
  const pending = reveal.isPending || remove.isPending || save.isPending

  /** Show loads a saved token once; afterwards Show and Hide only switch the field. */
  function toggle(platform: Platform) {
    const secret = secrets[platform]
    if (secret.visible) updateSecret(platform, { visible: false })
    else if (!current || secret.revealed || !configured[platform])
      updateSecret(platform, { visible: true })
    else reveal.mutate({ id: current.id, platform })
  }

  function removeToken(platform: Platform) {
    if (current) remove.mutate({ id: current.id, platform })
  }

  function submit(event: FormEvent) {
    event.preventDefault()
    const problem = formProblem(form, editing, {
      url: gitlabUrl.value.trim(),
      savedUrl: gitlabUrl.original,
      token: secrets.gitlab.value.trim(),
    })
    if (problem) setError(problem)
    else save.mutate()
  }

  const githubHint = current
    ? `${describe(current.connection, current.connection.login)}. ${changed.github ? 'The new token is verified with GitHub when you save.' : 'Type a new token to replace it.'}`
    : 'Optional. The token is verified with GitHub before the account is created.'
  const gitlabHint = current
    ? `${describe(current.gitlabConnection, current.gitlabConnection.username, current.gitlabConnection.tokenExpiresAt)}. ${changed.gitlab ? 'The URL and token are verified with GitLab when you save.' : 'Type a token (and URL) to replace the connection.'}`
    : 'Optional. The URL and token are verified with GitLab before the account is created.'
  return (
    <Dialog title={editing ? 'Edit user' : 'Add user'} onClose={onClose} busy={pending}>
      <form className={styles.userForm} onSubmit={submit} noValidate>
        <ErrorMessage message={error} />
        <div className={styles.formGrid}>
          <label>
            First name
            <input
              className={common.textField}
              value={form.firstName}
              maxLength={64}
              required
              onChange={event => set('firstName', event.target.value)}
            />
          </label>
          <label>
            Last name
            <input
              className={common.textField}
              value={form.lastName}
              maxLength={64}
              required
              onChange={event => set('lastName', event.target.value)}
            />
          </label>
        </div>
        <label>
          Email
          <input
            className={common.textField}
            type="email"
            value={form.email}
            maxLength={254}
            autoComplete="off"
            onChange={event => set('email', event.target.value)}
          />
        </label>
        <label>
          Username
          <input
            className={common.textField}
            value={form.username}
            maxLength={32}
            required
            autoComplete="off"
            autoCapitalize="none"
            spellCheck={false}
            onChange={event => set('username', event.target.value)}
          />
        </label>
        <label htmlFor="admin-user-password">Password</label>
        <div className={styles.secretRow}>
          <div className={common.passwordField}>
            <input
              id="admin-user-password"
              type={showPassword ? 'text' : 'password'}
              value={form.password}
              maxLength={512}
              autoComplete="new-password"
              required={!editing}
              onChange={event => set('password', event.target.value)}
              aria-describedby="admin-user-password-hint"
            />
            <button
              className={common.eyeButton}
              type="button"
              aria-label={showPassword ? 'Hide password' : 'Show password'}
              onClick={() => setShowPassword(!showPassword)}
            >
              {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
            </button>
          </div>
          <button
            type="button"
            className={common.secondary}
            onClick={() => {
              set('password', generatePassword())
              setShowPassword(true)
            }}
          >
            <Sparkles size={16} /> Generate
          </button>
        </div>
        <p id="admin-user-password-hint" className={styles.hint}>
          {editing
            ? 'Leave empty to keep the current password. A new password signs the user out everywhere.'
            : 'Use 8-128 characters.'}
        </p>
        {(!editing || form.password) && (
          <label className={styles.checkbox}>
            <input
              type="checkbox"
              checked={form.requireChange}
              onChange={event => set('requireChange', event.target.checked)}
            />
            Require password change at next sign-in
          </label>
        )}
        <div className={styles.formGrid}>
          <label>
            Role
            <select
              className={common.textField}
              value={form.role}
              disabled={self}
              onChange={event => set('role', event.target.value === 'admin' ? 'admin' : 'user')}
            >
              <option value="user">User</option>
              <option value="admin">Admin</option>
            </select>
          </label>
          {editing && (
            <label className={clsx(styles.checkbox, styles.inlineCheckbox)}>
              <input
                type="checkbox"
                checked={form.active}
                disabled={self}
                onChange={event => set('active', event.target.checked)}
              />{' '}
              Account is active
            </label>
          )}
        </div>
        <TokenRow
          id="admin-user-token"
          platform="github"
          secret={secrets.github}
          configured={configured.github}
          pending={pending}
          hint={githubHint}
          onChange={value => updateSecret('github', { value })}
          onToggle={() => toggle('github')}
          onRemove={() => removeToken('github')}
        />
        <h3 className={styles.sectionTitle}>GitLab</h3>
        <label>
          GitLab URL
          <input
            className={common.textField}
            type="url"
            value={gitlabUrl.value}
            maxLength={255}
            autoComplete="off"
            spellCheck={false}
            placeholder="https://gitlab.com"
            onChange={event =>
              setGitlabUrl(previous => ({ ...previous, value: event.target.value }))
            }
            aria-describedby="admin-user-gitlab-token-status"
          />
        </label>
        <TokenRow
          id="admin-user-gitlab-token"
          platform="gitlab"
          secret={secrets.gitlab}
          configured={configured.gitlab}
          pending={pending}
          hint={gitlabHint}
          onChange={value => updateSecret('gitlab', { value })}
          onToggle={() => toggle('gitlab')}
          onRemove={() => removeToken('gitlab')}
        />
        {current && (
          <p className={styles.hint}>
            Created {formatDate(current.createdAt)} · last sign-in{' '}
            {formatDate(current.lastSignInAt, true)} · {current.repositories.total} repositories ·{' '}
            {current.linkStates.active} active links · {current.linkStates.opens30Days} link opens
            in 30 days
          </p>
        )}
        <div className={common.buttonRow}>
          <button type="button" className={common.secondary} disabled={pending} onClick={onClose}>
            Cancel
          </button>
          <button type="submit" className={common.primary} disabled={pending}>
            {pending ? 'Saving…' : editing ? 'Save' : 'Create'}
          </button>
        </div>
      </form>
    </Dialog>
  )
}
