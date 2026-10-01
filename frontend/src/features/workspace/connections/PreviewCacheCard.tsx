import { platformLabels, platforms } from '../../../api/platforms'
import type { PreviewCacheStatus } from './api'
import common from '../../../styles/app.module.css'

/** The preview cache shared by the workspace and access links, and each platform's API quota. */
export function PreviewCacheCard({
  cache,
  gitlabConfigured,
}: {
  cache: PreviewCacheStatus
  gitlabConfigured: boolean
}) {
  return (
    <section className={common.card} aria-labelledby="preview-cache-title">
      <h2 id="preview-cache-title">Preview cache</h2>
      <p>Cache for your workspace and visitors using your access links.</p>
      <dl className={common.details}>
        <div>
          <dt>Storage used</dt>
          <dd>
            {(cache.bytes / 1048576).toFixed(2)} / {cache.maxBytes / 1048576} MiB
          </dd>
        </div>
        <div>
          <dt>Cached responses</dt>
          <dd>
            {cache.entries} / {cache.maxEntries}
          </dd>
        </div>
        <div>
          <dt>Cache hits / misses</dt>
          <dd>
            {cache.hits} / {cache.misses}
          </dd>
        </div>
        <div>
          <dt>Upstream requests / unchanged responses</dt>
          <dd>
            {cache.upstreamCalls} / {cache.revalidations}
          </dd>
        </div>
        <div>
          <dt>Evicted responses</dt>
          <dd>{cache.evictions}</dd>
        </div>
        {platforms
          .filter(platform => platform === 'github' || gitlabConfigured)
          .map(platform => {
            const quota = cache.providers[platform]
            return (
              <div key={platform}>
                <dt>{platformLabels[platform]} quota remaining</dt>
                <dd>
                  {quota.rateRemaining == null
                    ? 'Not reported yet'
                    : `${quota.rateRemaining} / ${quota.rateLimit ?? '-'}`}
                  {quota.rateReset != null && (
                    <small className={common.hintText}>
                      {' '}
                      · resets {new Date(quota.rateReset * 1000).toLocaleString()}
                    </small>
                  )}
                </dd>
              </div>
            )
          })}
      </dl>
    </section>
  )
}
