/** The source platforms and connection states that the workspace, the admin panel and the session share. */
export type Platform = 'github' | 'gitlab'

export const platforms: readonly Platform[] = ['github', 'gitlab']

export const platformLabels: Record<Platform, string> = { github: 'GitHub', gitlab: 'GitLab' }

export type ConnectionState =
  | 'not_configured'
  | 'not_verified'
  | 'connected'
  | 'credentials_rejected'
  | 'access_restricted'
  | 'temporarily_unavailable'

/** In the order of the admin filters. */
export const connectionLabels: Record<ConnectionState, string> = {
  connected: 'Connected',
  not_verified: 'Not verified',
  credentials_rejected: 'Credentials rejected',
  access_restricted: 'Access restricted',
  temporarily_unavailable: 'Temporarily unavailable',
  not_configured: 'Not configured',
}
