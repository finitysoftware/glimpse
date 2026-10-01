import { Link } from 'react-router-dom'
import { ArrowRight, GitBranch as Github, LockKeyhole } from 'lucide-react'
import { clsx } from 'clsx'
import { platformLabels } from '../../api/platforms'
import type { Repository } from './api'
import styles from './Repositories.module.css'

/** Workspace address of one repository: GitHub by owner and name, GitLab by its stable project ID. */
function repositoryPath(
  repository: Pick<Repository, 'provider' | 'id' | 'owner' | 'name'>,
): string {
  return repository.provider === 'gitlab'
    ? `/app/gitlab/${repository.id}`
    : `/app/repositories/${encodeURIComponent(repository.owner)}/${encodeURIComponent(repository.name)}`
}

/** One repository of the catalogue, linking to its page. */
export function RepositoryCard({
  repository,
  showPlatform,
}: {
  repository: Repository
  showPlatform: boolean
}) {
  return (
    <Link
      className={styles.repository}
      to={repositoryPath(repository)}
      state={{ fullName: repository.fullName }}
    >
      <span className={styles.repoIcon}>
        <Github size={22} />
      </span>
      <div>
        <h2>{repository.fullName}</h2>
        <p>{repository.description || 'Open files and manage access links.'}</p>
      </div>
      <span className={styles.badges}>
        {showPlatform && (
          <span className={clsx(styles.badge, styles.platformBadge)}>
            {platformLabels[repository.provider]}
          </span>
        )}
        <span className={styles.badge}>
          {repository.visibility === 'public' && 'Public'}
          {repository.visibility === 'internal' && 'Internal'}
          {repository.visibility === 'private' && (
            <>
              <LockKeyhole size={12} /> Private
            </>
          )}
        </span>
      </span>
      <ArrowRight size={17} />
    </Link>
  )
}
