import { useNavigate, useSearchParams } from 'react-router-dom'
import common from '../../styles/app.module.css'
import { PasswordForm } from '../auth/PasswordForm'
import { adminAuth, type AdminSession } from './api'
import { useAdminSession } from './session'
import { PageHeading } from '../../components/PageHeading'
import { PageTitle } from '../../components/PageTitle'

export function AdminAccount() {
  const { session, replace } = useAdminSession()
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const required = session.passwordChangeRequired || params.get('required') === '1'
  return (
    <>
      <PageTitle>Account · Admin</PageTitle>
      <PageHeading eyebrow="ADMINISTRATION" title="Account">
        Signed in as <strong>{session.admin.username}</strong>.
      </PageHeading>
      {session.passwordChangeRequired && (
        <p className={common.noticeLine} role="status">
          Set a new password before you continue.
        </p>
      )}
      <section className={common.card} aria-labelledby="admin-password-heading">
        <h2 id="admin-password-heading">Change password</h2>
        <PasswordForm<AdminSession>
          endpoint={adminAuth.password}
          csrfToken={session.csrfToken}
          onChanged={next => {
            replace(next)
            if (required) void navigate('/admin', { replace: true })
          }}
        />
      </section>
    </>
  )
}
