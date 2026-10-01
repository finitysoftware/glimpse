import { useState } from 'react'
import { Eye, EyeOff, Trash2 } from 'lucide-react'
import { clsx } from 'clsx'
import { platformLabels, type Platform } from '../../../api/platforms'
import common from '../../../styles/app.module.css'
import styles from '../Admin.module.css'

/** A token as the dialog knows it: typed, as loaded by Show, and whether it is shown. */
export interface Secret {
  value: string
  original: string
  revealed: boolean
  visible: boolean
}

/** One platform's token in the user dialog: hidden until Show loads it, with Remove behind a confirmation. */
export function TokenRow({
  id,
  platform,
  secret,
  configured,
  pending,
  hint,
  onChange,
  onToggle,
  onRemove,
}: {
  id: string
  platform: Platform
  secret: Secret
  configured: boolean
  pending: boolean
  hint: string
  onChange: (value: string) => void
  onToggle: () => void
  onRemove: () => void
}) {
  const label = platformLabels[platform]
  const [confirming, setConfirming] = useState(false)
  return (
    <>
      <label htmlFor={id}>{label} token</label>
      <div className={styles.secretRow}>
        <div className={common.passwordField}>
          <input
            id={id}
            className={styles.token}
            type={secret.visible ? 'text' : 'password'}
            value={secret.value}
            maxLength={1024}
            autoComplete="off"
            spellCheck={false}
            placeholder={configured && !secret.revealed ? '••••••••••••••••••••' : 'Not configured'}
            onChange={event => onChange(event.target.value)}
            aria-describedby={`${id}-status`}
          />
          <button
            className={common.eyeButton}
            type="button"
            disabled={pending}
            aria-label={`${secret.visible ? 'Hide' : 'Show'} ${label} token`}
            onClick={onToggle}
          >
            {secret.visible ? <EyeOff size={18} /> : <Eye size={18} />}
          </button>
        </div>
        {configured && (
          <button
            type="button"
            className={common.secondary}
            disabled={pending}
            aria-label={`Remove ${label} token`}
            onClick={() => setConfirming(true)}
          >
            <Trash2 size={16} /> Remove
          </button>
        )}
      </div>
      <p id={`${id}-status`} className={styles.hint}>
        {hint}
      </p>
      {confirming && configured && (
        <div className={common.error} role="alertdialog" aria-label={`Remove ${label} token`}>
          Remove this user's {label} token? Their {label} access links stop working until a token is
          saved again.
          <div className={common.buttonRow}>
            <button
              type="button"
              className={common.secondary}
              autoFocus
              onClick={() => setConfirming(false)}
            >
              Keep token
            </button>
            <button
              type="button"
              className={clsx(common.primary, styles.danger)}
              disabled={pending}
              onClick={onRemove}
            >
              Remove token
            </button>
          </div>
        </div>
      )}
    </>
  )
}
