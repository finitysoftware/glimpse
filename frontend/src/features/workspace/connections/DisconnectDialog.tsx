import { useRef, type RefObject } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Unplug } from 'lucide-react'
import { errorMessage } from '../../../api/client'
import { platformLabels, type Platform } from '../../../api/platforms'
import { Dialog } from '../../../components/Dialog'
import common from '../../../styles/app.module.css'
import styles from './Connections.module.css'
import { disconnectPlatform, type Connection } from './api'
import { ErrorMessage } from '../../../components/ErrorMessage'

export function DisconnectDialog({
  platform,
  card,
  csrfToken,
  onClose,
  onDone,
}: {
  platform: Platform
  /** Takes the focus after a successful disconnect removed the button that opened the dialog. */
  card: RefObject<HTMLElement | null>
  csrfToken: string
  onClose: () => void
  onDone: (saved: Connection) => void
}) {
  const label = platformLabels[platform]
  const disconnect = useMutation({
    mutationFn: () => disconnectPlatform(platform, csrfToken),
    onSuccess: onDone,
  })
  const pending = disconnect.isPending
  const error = errorMessage(disconnect.error, `Unable to disconnect ${label}.`)
  const cancel = useRef<HTMLButtonElement>(null)

  return (
    <Dialog
      title={`Disconnect ${label}?`}
      description={`Its repositories disappear from your list and your ${label} access links stop working until you connect again. Links and file visibility settings are kept.`}
      onClose={onClose}
      busy={pending}
      alert
      initialFocus={cancel}
      fallbackFocus={card}
    >
      <ErrorMessage message={error} />
      <div className={common.buttonRow}>
        <button
          className={styles.danger}
          type="button"
          disabled={pending}
          onClick={() => disconnect.mutate()}
        >
          <Unplug size={16} />
          {pending ? 'Disconnecting…' : `Disconnect ${label}`}
        </button>
        <button
          ref={cancel}
          className={common.secondary}
          type="button"
          disabled={pending}
          onClick={onClose}
        >
          Cancel
        </button>
      </div>
    </Dialog>
  )
}
