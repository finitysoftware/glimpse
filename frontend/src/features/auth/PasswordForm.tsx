import { useState, type FormEvent } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Eye, EyeOff, KeyRound } from 'lucide-react'
import { errorMessage } from '../../api/client'
import { changePassword } from './api'
import { passwordProblem } from './passwordRules'
import common from '../../styles/app.module.css'
import styles from './Auth.module.css'
import { ErrorMessage } from '../../components/ErrorMessage'

/** Own password change for the workspace or the admin panel. The server answers with the renewed session. */
export function PasswordForm<T>({
  endpoint,
  csrfToken,
  onChanged,
}: {
  endpoint: `/api/${string}`
  csrfToken: string
  onChanged: (session: T) => void
}) {
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirm, setConfirm] = useState('')
  const [visible, setVisible] = useState(false)
  const [problem, setProblem] = useState('')
  const change = useMutation({
    mutationFn: () => changePassword<T>(endpoint, current, next, csrfToken),
    onSuccess: session => {
      setCurrent('')
      setNext('')
      setConfirm('')
      onChanged(session)
    },
  })
  const pending = change.isPending
  const done = change.isSuccess
  const error = problem || errorMessage(change.error, 'Unable to change the password.')

  function submit(event: FormEvent) {
    event.preventDefault()
    const found =
      passwordProblem(next) || (next !== confirm ? 'The new passwords do not match.' : '')
    setProblem(found)
    if (!found) change.mutate()
  }

  const type = visible ? 'text' : 'password'
  return (
    <form className={styles.passwordForm} onSubmit={submit}>
      <ErrorMessage message={error} />
      {done && (
        <p className={common.noticeLine} role="status">
          Password changed. Other devices were signed out.
        </p>
      )}
      <label htmlFor="current-password">Current password</label>
      <input
        className={common.textField}
        id="current-password"
        type={type}
        autoComplete="current-password"
        required
        maxLength={1024}
        value={current}
        onChange={event => setCurrent(event.target.value)}
        disabled={pending}
      />
      <label htmlFor="new-password">New password</label>
      <input
        className={common.textField}
        id="new-password"
        type={type}
        autoComplete="new-password"
        required
        maxLength={512}
        value={next}
        onChange={event => setNext(event.target.value)}
        disabled={pending}
        aria-describedby="new-password-hint"
      />
      <label htmlFor="confirm-password">Confirm new password</label>
      <input
        className={common.textField}
        id="confirm-password"
        type={type}
        autoComplete="new-password"
        required
        maxLength={512}
        value={confirm}
        onChange={event => setConfirm(event.target.value)}
        disabled={pending}
      />
      <p id="new-password-hint" className={common.hintText}>
        Use 8-128 characters. Changing it signs you out on other devices.
      </p>
      <div className={common.buttonRow}>
        <button className={common.primary} type="submit" disabled={pending}>
          <KeyRound size={16} /> {pending ? 'Saving…' : 'Change password'}
        </button>
        <button
          className={common.secondary}
          type="button"
          aria-pressed={visible}
          onClick={() => setVisible(!visible)}
        >
          {visible ? <EyeOff size={16} /> : <Eye size={16} />}{' '}
          {visible ? 'Hide passwords' : 'Show passwords'}
        </button>
      </div>
    </form>
  )
}
