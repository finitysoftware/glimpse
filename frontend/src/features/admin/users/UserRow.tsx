import { Link2, LogOut, Pencil, Trash2 } from 'lucide-react'
import { clsx } from 'clsx'
import { connectionLabels } from '../../../api/platforms'
import common from '../../../styles/app.module.css'
import styles from '../Admin.module.css'
import { type AdminUser } from '../api'
import { displayName, formatDate } from '../format'

/** One account in the users table, with its links, edit, sign-out and delete actions. */
export function UserRow({
  user,
  self,
  onLinks,
  onEdit,
  onSignOut,
  onDelete,
}: {
  user: AdminUser
  /** The signed-in admin, who cannot delete their own account. */
  self: boolean
  onLinks: () => void
  onEdit: () => void
  onSignOut: () => void
  onDelete: () => void
}) {
  const name = displayName(user)
  return (
    <tr className={user.active ? undefined : styles.inactive}>
      <td>
        <div className={styles.userCell}>
          <span className={common.avatar} aria-hidden="true">
            {name.charAt(0).toUpperCase()}
          </span>
          <div>
            <strong>
              {name}
              {self && <span className={styles.you}>You</span>}
            </strong>
            <small>
              {user.email ? `${user.email} · ` : ''}
              {user.username}
            </small>
          </div>
        </div>
      </td>
      <td>
        <span className={clsx(styles.badge, user.role === 'admin' && styles.badgeAdmin)}>
          {user.role === 'admin' ? 'Admin' : 'User'}
        </span>
      </td>
      <td>
        <span className={styles.status}>
          <i className={user.active ? styles.dotActive : styles.dotInactive} />
          {user.active ? 'Active' : 'Deactivated'}
        </span>
      </td>
      <td>
        <span className={styles.connection}>
          GitHub: {connectionLabels[user.github.status]}
          {user.github.login && <small> · {user.github.login}</small>}
        </span>
        <span className={styles.connection}>
          GitLab: {connectionLabels[user.gitlab.status]}
          {user.gitlab.username && <small> · {user.gitlab.username}</small>}
        </span>
      </td>
      <td className={styles.number}>
        {user.links.active} / {user.links.total}
      </td>
      <td>{formatDate(user.lastSignInAt)}</td>
      <td>
        <div className={styles.rowActions}>
          <button
            className={common.iconButton}
            title="Access links of this user"
            aria-label={`Access links of ${name}`}
            onClick={onLinks}
          >
            <Link2 size={17} />
          </button>
          <button
            className={common.iconButton}
            title="Edit"
            aria-label={`Edit ${name}`}
            onClick={onEdit}
          >
            <Pencil size={17} />
          </button>
          <button
            className={common.iconButton}
            title="Sign out everywhere"
            aria-label={`Sign out ${name} everywhere`}
            onClick={onSignOut}
          >
            <LogOut size={17} />
          </button>
          <button
            className={clsx(common.iconButton, styles.dangerIcon)}
            title={self ? 'You cannot delete your own account' : 'Delete'}
            aria-label={`Delete ${name}`}
            disabled={self}
            onClick={onDelete}
          >
            <Trash2 size={17} />
          </button>
        </div>
      </td>
    </tr>
  )
}
