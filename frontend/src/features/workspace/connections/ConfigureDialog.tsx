import { useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react'
import { useMutation } from '@tanstack/react-query'
import { ExternalLink, Eye, EyeOff } from 'lucide-react'
import { ApiError, errorMessage } from '../../../api/client'
import { platformLabels, type Platform } from '../../../api/platforms'
import { Dialog } from '../../../components/Dialog'
import common from '../../../styles/app.module.css'
import styles from './Connections.module.css'
import { saveConnection, type Connection } from './api'
import { ErrorMessage } from '../../../components/ErrorMessage'

const help: Record<Platform, ReactNode> = {
  github: (
    <>
      Use a fine-grained personal access token. Choose the resource owner and the repositories you
      want to share, with read-only <strong>Metadata</strong> and <strong>Contents</strong>{' '}
      permissions. Write access is not needed.
    </>
  ),
  gitlab: (
    <>
      Use a personal access token with the <strong>read_api</strong> scope. Write access is not
      needed. The token is only ever sent to the GitLab address below.
    </>
  ),
}

/** Saves and verifies a new token; GitLab also needs the address of its instance. */
export function ConfigureDialog({
  platform,
  connection,
  csrfToken,
  onClose,
  onSaved,
  onConflict,
}: {
  platform: Platform
  connection: Connection
  csrfToken: string
  onClose: () => void
  onSaved: (saved: Connection) => void
  onConflict: () => void
}) {
  const label = platformLabels[platform]
  const gitlab = 'defaultUrl' in connection ? connection : null
  const [baseUrl, setBaseUrl] = useState(gitlab ? (gitlab.baseUrl ?? gitlab.defaultUrl) : '')
  const [token, setToken] = useState('')
  const save = useMutation({
    mutationFn: () =>
      saveConnection(
        platform,
        {
          ...(gitlab && { baseUrl: baseUrl.trim() }),
          token,
          expectedVersion: connection.credentialVersion,
        },
        csrfToken,
      ),
    onSuccess: saved => {
      setToken('')
      onSaved(saved)
    },
    onError: reason => {
      if (reason instanceof ApiError && reason.code === 'connection_changed') onConflict()
    },
  })
  const pending = save.isPending
  const error = saveError(save.error, `Unable to save the ${label} connection.`)
  const ready = token !== '' && (!gitlab || baseUrl.trim() !== '')
  const input = useRef<HTMLInputElement>(null)
  const address = gitlab ? instanceAddress(baseUrl) : null
  const otherInstance =
    gitlab?.baseUrl != null && address !== null && address.toLowerCase() !== gitlab.baseUrl

  function close() {
    setToken('')
    onClose()
  }

  function submit(event: FormEvent) {
    event.preventDefault()
    if (!pending && ready) save.mutate()
  }

  return (
    <Dialog
      title={`Configure ${label}`}
      description={help[platform]}
      onClose={close}
      busy={pending}
      initialFocus={input}
    >
      <form onSubmit={submit}>
        {!gitlab && (
          <p>
            <a
              href="https://github.com/settings/personal-access-tokens/new"
              target="_blank"
              rel="noopener noreferrer"
            >
              Create a fine-grained token on GitHub <ExternalLink size={13} />
            </a>
          </p>
        )}
        <ErrorMessage message={error} />
        {gitlab && (
          <>
            <label htmlFor="gitlab-url">GitLab URL</label>
            <input
              id="gitlab-url"
              name="gitlab-url"
              type="url"
              inputMode="url"
              autoComplete="url"
              autoCapitalize="none"
              spellCheck={false}
              required
              maxLength={255}
              value={baseUrl}
              onChange={event => setBaseUrl(event.target.value)}
              disabled={pending}
              className={styles.textInput}
            />
            <p className={common.hintText}>
              GitLab.com or the HTTPS address of your own GitLab instance.
              {address && (
                <>
                  {' '}
                  <a
                    href={`${address}/-/user_settings/personal_access_tokens?name=Glimpse&scopes=read_api`}
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    Create a token on GitLab <ExternalLink size={13} />
                  </a>
                </>
              )}
            </p>
          </>
        )}
        <label htmlFor={`${platform}-token`}>Personal access token</label>
        <TokenField
          id={`${platform}-token`}
          value={token}
          onChange={setToken}
          disabled={pending}
          input={input}
        />
        {connection.configured && (
          <p className={common.hintText}>
            {otherInstance
              ? 'This is another GitLab instance. Your links to projects of the current instance stop working until it is connected again.'
              : 'The saved token stays active until the new one is verified.'}
          </p>
        )}
        <div className={common.buttonRow}>
          <button className={common.primary} type="submit" disabled={pending || !ready}>
            {pending ? 'Verifying…' : 'Save & verify'}
          </button>
          <button className={common.secondary} type="button" disabled={pending} onClick={close}>
            Cancel
          </button>
        </div>
      </form>
    </Dialog>
  )
}

function TokenField({
  id,
  value,
  onChange,
  disabled,
  input,
}: {
  id: string
  value: string
  onChange: (value: string) => void
  disabled: boolean
  input: RefObject<HTMLInputElement | null>
}) {
  const [visible, setVisible] = useState(false)
  return (
    <div className={common.passwordField}>
      <input
        ref={input}
        id={id}
        name={id}
        type={visible ? 'text' : 'password'}
        autoComplete="off"
        autoCapitalize="none"
        spellCheck={false}
        required
        maxLength={1024}
        value={value}
        onChange={event => onChange(event.target.value)}
        disabled={disabled}
      />
      <button
        className={common.eyeButton}
        type="button"
        aria-label={visible ? 'Hide token' : 'Show token'}
        onClick={() => setVisible(!visible)}
      >
        {visible ? <EyeOff size={18} /> : <Eye size={18} />}
      </button>
    </div>
  )
}

/** A quota error also says when saving can be tried again. */
function saveError(reason: unknown, fallback: string): string {
  const message = errorMessage(reason, fallback)
  if (reason instanceof ApiError && reason.status === 429 && reason.retryAfter)
    return `${message} Try again in ${reason.retryAfter} seconds.`
  return message
}

/** The HTTPS address of a GitLab instance as typed, if it can be one; used only to offer the token page link. */
function instanceAddress(value: string): string | null {
  try {
    const url = new URL(value.trim())
    if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash)
      return null
    return `${url.origin}${url.pathname.replace(/\/+$/, '').replace(/\/api\/v4$/i, '')}`
  } catch {
    return null
  }
}
