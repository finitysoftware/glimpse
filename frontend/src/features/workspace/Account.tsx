import { useNavigate, useSearchParams } from 'react-router-dom'
import { workspaceAuth, type Session } from '../auth/api'
import { PasswordForm } from '../auth/PasswordForm'
import { useWorkspaceSession } from '../auth/session'
import common from '../../styles/app.module.css'
import { PageHeading } from '../../components/PageHeading'
import { PageTitle } from '../../components/PageTitle'

export function Account() {
  const { session, replace } = useWorkspaceSession()
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const required = session.passwordChangeRequired || params.get('required') === '1'
  return (
    <>
      <PageTitle>Account</PageTitle>
      <PageHeading eyebrow="WORKSPACE" title="Account">
        Signed in as <strong>{session.user.username}</strong>.
      </PageHeading>
      {session.passwordChangeRequired && (
        <p className={common.noticeLine} role="status">
          An administrator asked you to set a new password before you continue.
        </p>
      )}
      <section className={common.card} aria-labelledby="password-heading">
        <h2 id="password-heading">Change password</h2>
        <PasswordForm<Session>
          endpoint={workspaceAuth.password}
          csrfToken={session.csrfToken}
          onChanged={next => {
            replace(next)
            if (required) void navigate('/app', { replace: true })
          }}
        />
      </section>
    </>
  )
}
