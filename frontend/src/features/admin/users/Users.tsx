import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { errorMessage } from '../../../api/client'
import common from '../../../styles/app.module.css'
import styles from '../Admin.module.css'
import {
  adminKeys,
  deleteUser,
  loadUser,
  loadUsers,
  signOutUser,
  type AdminUser,
  type AdminUserDetail,
  type UserQuery,
} from '../api'
import { displayName } from '../format'
import { ConfirmDialog } from '../../../components/Dialog'
import { useAdminSession } from '../session'
import { UserDialog } from './UserDialog'
import { UserRow } from './UserRow'
import { UserFilters } from './UserFilters'
import { PageHeading } from '../../../components/PageHeading'
import { PageTitle } from '../../../components/PageTitle'
import { ErrorMessage } from '../../../components/ErrorMessage'
import { Pager } from '../Pager'

/** The filters and the page live in the address, so Back (e.g. from a user's links) returns to them. */
function userQuery(params: URLSearchParams): UserQuery {
  const text = (name: string) => params.get(name) ?? ''
  return {
    q: text('q'),
    status: text('status'),
    role: text('role'),
    github: text('github'),
    gitlab: text('gitlab'),
    sort: text('sort'),
    page: Math.max(1, Number(params.get('page') ?? 1) || 1),
  }
}

type Confirmation = { kind: 'delete' | 'signout'; user: AdminUser }
type Pending = { kind: 'edit'; user: AdminUserDetail | null } | Confirmation | null

export function Users() {
  const { session } = useAdminSession()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const filters = userQuery(params)
  const [dialog, setDialog] = useState<Pending>(null)
  const [notice, setNotice] = useState('')
  const queryClient = useQueryClient()
  const users = useQuery({
    queryKey: adminKeys.usersPage(filters),
    queryFn: ({ signal }) => loadUsers(filters, signal),
  })
  const reload = () => void queryClient.invalidateQueries({ queryKey: adminKeys.users })
  const opening = useMutation({
    mutationFn: (user: AdminUser) => loadUser(user.id),
    onSuccess: user => setDialog({ kind: 'edit', user }),
  })
  const action = useMutation({
    mutationFn: async ({ kind, user }: Confirmation) => {
      if (kind === 'delete') await deleteUser(user, session.csrfToken)
      else await signOutUser(user.id, session.csrfToken)
    },
    onSuccess: (_, { kind, user }) => {
      setNotice(
        `${displayName(user)} ${kind === 'delete' ? 'was deleted' : 'was signed out everywhere'}.`,
      )
      setDialog(null)
      reload()
    },
  })
  const actionError =
    errorMessage(opening.error, 'Unable to open this account.') ||
    errorMessage(action.error, 'The action could not be completed.')
  function open(next: Pending) {
    opening.reset()
    action.reset()
    setDialog(next)
  }
  function edit(user: AdminUser) {
    action.reset()
    opening.mutate(user)
  }

  /**
   * Another filter starts again from page 1; empty filters and page 1 stay out of the address. The same filters again
   * replace their own step, so Back never stays put.
   */
  function change(values: Partial<UserQuery>) {
    const next = new URLSearchParams(params)
    for (const [name, value] of Object.entries({ page: 1, ...values })) {
      if (value === '' || value === 1) next.delete(name)
      else next.set(name, String(value))
    }
    setParams(next, { replace: next.toString() === params.toString() })
  }

  const data = users.data
  return (
    <>
      <PageTitle>Users · Admin</PageTitle>
      <PageHeading
        eyebrow="ADMINISTRATION"
        title="Users"
        actions={
          <button className={common.primary} onClick={() => open({ kind: 'edit', user: null })}>
            <Plus size={17} /> Add user
          </button>
        }
      >
        Add accounts, change their details, roles, passwords and GitHub or GitLab tokens, or
        deactivate access.
      </PageHeading>
      {notice && (
        <p className={common.noticeLine} role="status">
          {notice}
        </p>
      )}
      {/* An open dialog shows the action's error itself. */}
      <ErrorMessage message={dialog ? '' : actionError} />
      <UserFilters filters={filters} onChange={change} />
      <ErrorMessage
        message={errorMessage(users.error, 'Unable to load users.')}
        onRetry={() => void users.refetch()}
      />
      {users.isPending && (
        <p role="status" className={common.hintText}>
          Loading users…
        </p>
      )}
      {data && (
        <div className={styles.tableWrap}>
          <table className={styles.table}>
            <thead>
              <tr>
                <th>User</th>
                <th>Role</th>
                <th>Status</th>
                <th>Connections</th>
                <th>Links</th>
                <th>Last sign-in</th>
                <th>
                  <span className={styles.visuallyHidden}>Actions</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {data.items.length === 0 && (
                <tr>
                  <td colSpan={7} className={styles.emptyCell}>
                    No users match these filters.
                  </td>
                </tr>
              )}
              {data.items.map(user => (
                <UserRow
                  key={user.id}
                  user={user}
                  self={user.id === session.admin.id}
                  onLinks={() => void navigate(`/admin/links?user=${user.id}`)}
                  onEdit={() => edit(user)}
                  onSignOut={() => open({ kind: 'signout', user })}
                  onDelete={() => open({ kind: 'delete', user })}
                />
              ))}
            </tbody>
          </table>
        </div>
      )}
      {data && (
        <Pager
          total={data.total}
          noun="user"
          page={filters.page}
          hasNext={!!data.nextPage}
          onPage={page => change({ page })}
        />
      )}
      {dialog?.kind === 'edit' && (
        <UserDialog
          user={dialog.user}
          selfId={session.admin.id}
          csrfToken={session.csrfToken}
          onClose={() => setDialog(null)}
          onSaved={saved => {
            setNotice(`${displayName(saved)} was saved.`)
            reload()
          }}
        />
      )}
      {dialog?.kind === 'delete' && (
        <ConfirmDialog
          title="Delete user"
          danger
          busy={action.isPending}
          error={actionError}
          confirmLabel="Delete"
          onCancel={() => setDialog(null)}
          onConfirm={() => action.mutate(dialog)}
          message={
            <>
              Are you sure you want to delete <strong>{displayName(dialog.user)}</strong>? This
              permanently removes the account, its GitHub and GitLab connections, repositories,
              access links and history. It cannot be undone.
            </>
          }
        />
      )}
      {dialog?.kind === 'signout' && (
        <ConfirmDialog
          title="Sign out everywhere"
          busy={action.isPending}
          error={actionError}
          confirmLabel="Sign out everywhere"
          onCancel={() => setDialog(null)}
          onConfirm={() => action.mutate(dialog)}
          message={
            <>
              End every workspace and admin session of <strong>{displayName(dialog.user)}</strong>?
              Their access links keep working.
            </>
          }
        />
      )}
    </>
  )
}
