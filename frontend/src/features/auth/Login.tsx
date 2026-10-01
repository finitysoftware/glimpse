import { useState, type ReactNode } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Eye, EyeOff, LockKeyhole } from 'lucide-react'
import { errorMessage } from '../../api/client'
import { signIn, workspaceAuth, type Session } from './api'
import common from '../../styles/app.module.css'
import styles from './Auth.module.css'
import { ErrorMessage } from '../../components/ErrorMessage'
import { PageTitle } from '../../components/PageTitle'

/** Sign-in form of the workspace; the admin panel uses the same form with its own endpoint and texts. */
export function Login<T = Session>({
  initialError,
  onSignedIn,
  endpoint = workspaceAuth.login,
  subtitle = 'Sign in to your private workspace.',
  footnote = 'Private workspace access',
  pageTitle = 'Sign in',
  extra,
}: {
  initialError: string
  onSignedIn: (session: T) => void
  endpoint?: `/api/${string}`
  subtitle?: string
  footnote?: string
  /** The page in the browser tab. */
  pageTitle?: string
  extra?: ReactNode
}) {
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [visible, setVisible] = useState(false)
  const login = useMutation({
    mutationFn: () => signIn<T>(endpoint, username, password),
    onSuccess: session => {
      setPassword('')
      onSignedIn(session)
    },
  })
  const pending = login.isPending
  // The gate's error (e.g. maintenance) shows until the first attempt.
  const error = login.isIdle ? initialError : errorMessage(login.error, 'Unable to sign in.')

  return (
    <main className={common.loginWrap}>
      <PageTitle>{pageTitle}</PageTitle>
      <form
        className={common.loginCard}
        onSubmit={event => {
          event.preventDefault()
          login.mutate()
        }}
      >
        <div className={styles.loginBrand}>
          <span className={common.brandMark}>
            <Eye size={26} />
          </span>
          <strong>Glimpse</strong>
        </div>
        <h1>Welcome back</h1>
        <p className={styles.subtitle}>{subtitle}</p>
        <ErrorMessage message={error} />
        <label htmlFor="username">Username</label>
        <input
          className={common.textField}
          id="username"
          name="username"
          type="text"
          // Signing in is all this page is for, also after signing out or an expired session.
          autoFocus
          autoComplete="username"
          autoCapitalize="none"
          spellCheck={false}
          required
          maxLength={32}
          value={username}
          onChange={event => setUsername(event.target.value)}
          disabled={pending}
        />
        <label htmlFor="password">Password</label>
        <div className={common.passwordField}>
          <input
            id="password"
            name="password"
            type={visible ? 'text' : 'password'}
            autoComplete="current-password"
            required
            maxLength={1024}
            value={password}
            onChange={event => setPassword(event.target.value)}
            disabled={pending}
          />
          <button
            className={common.eyeButton}
            type="button"
            aria-label={visible ? 'Hide password' : 'Show password'}
            onClick={() => setVisible(!visible)}
          >
            {visible ? <EyeOff size={18} /> : <Eye size={18} />}
          </button>
        </div>
        <button className={common.primary} disabled={pending} type="submit">
          {pending ? 'Signing in…' : 'Sign in'}
        </button>
        <p className={common.loginFootnote}>
          <LockKeyhole size={14} /> {footnote}
        </p>
        {extra}
      </form>
      <span className={styles.wordmarkFootnote}>A closer look. A controlled view.</span>
    </main>
  )
}
