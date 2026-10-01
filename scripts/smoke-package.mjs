import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { resolve, join } from 'node:path'
import { spawn } from 'node:child_process'
import { request } from 'node:http'
import { setTimeout } from 'node:timers/promises'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('../', import.meta.url))
const release = (await readFile(join(root, 'artifacts/latest-package.txt'), 'utf8')).trim()
const publicPath = join(release, 'public')
const port = 8091
const base = `http://127.0.0.1:${port}`
const server = spawn(process.env.PHP_BIN || 'php', ['-S', `127.0.0.1:${port}`, '-t', publicPath, resolve(root, 'scripts/serve-package.php')], {
  cwd: publicPath,
  env: { ...process.env, GLIMPSE_ENV: 'test', GLIMPSE_ORIGIN: base, GLIMPSE_BOOTSTRAP: '', GLIMPSE_DB_DSN: '' },
  stdio: ['ignore', 'ignore', 'pipe'],
})
let startupError = ''
server.stderr.on('data', chunk => { startupError = String(chunk).slice(-1000) })
try {
  let ready = false
  for (let attempt = 0; attempt < 30; attempt++) {
    if (server.exitCode !== null) throw new Error(`Package server failed: ${startupError}`)
    try { ready = (await fetch(`${base}/api/health`)).ok } catch { /* Wait for loopback listener. */ }
    if (ready) break
    await setTimeout(100)
  }
  assert(ready, 'Package server did not become ready')
  for (const path of ['/api', '/api/missing', '/api/.env']) {
    const response = await fetch(base + path)
    assert.equal(response.status, 404)
    assert.match(response.headers.get('content-type'), /^application\/json/)
  }
  for (const path of ['/api/workspace/preview-cache', '/api/workspace/connections/github', '/api/workspace/repositories', '/api/workspace/repositories/owner/repo/shares']) {
    const privateResponse = await fetch(base + path)
    assert.equal(privateResponse.status, 401)
    assert.equal(privateResponse.headers.get('cache-control'), 'private, no-store')
  }
  const share = await fetch(base + '/api/shares/' + 'a'.repeat(32) + '/browse')
  assert.equal(share.status, 404)
  assert.equal(share.headers.get('cache-control'), 'private, no-store')
  for (const path of ['/private/backend/config/settings.local.php', '/vendor/autoload.php', '/.env', '/assets/missing.js',
    '/assets/css/missing.css', '/website/index.html', '/unknown', '/login/extra', '/s/', '/workspace']) {
    assert.equal((await fetch(base + path)).status, 404, path)
  }
  // / is the static landing page; its calls to action open sign-in.
  const landing = await fetch(base + '/')
  assert.equal(landing.status, 200)
  const landingHtml = await landing.text()
  assert(!landingHtml.includes('id="root"') && landingHtml.includes('href="/login"'), 'Landing page is not served at /')
  for (const path of ['/favicon.svg', '/assets/css/style.css', '/assets/js/main.js', '/assets/js/translations.js', '/assets/icons/favicon.svg',
    '/assets/icons/apple-touch-icon.png', '/assets/images/og-image.png', '/sitemap.xml']) {
    assert.equal((await fetch(base + path)).status, 200, path)
  }
  // Search engines may crawl the landing page; the API stays out of reach.
  const robots = await fetch(base + '/robots.txt')
  assert.equal(robots.status, 200)
  assert.match(await robots.text(), /^Disallow: \/api\/$/m)
  // Only sign-in, the workspace, the admin panel and visitor links receive the React entry.
  let asset = ''
  for (const path of ['/login', '/app', '/app/connections', '/app/repositories/owner/repo', '/app/gitlab/42', '/admin', '/admin/users', '/admin/login',
    '/s/' + 'a'.repeat(32), '/s/' + 'a'.repeat(32) + '/src/app.ts']) {
    const document = await fetch(base + path)
    assert.equal(document.status, 200, path)
    const html = await document.text()
    assert.match(html, /id="root"/, path)
    asset = html.match(/src="(\/assets\/[^\"]+\.js)"/)?.[1] ?? asset
  }
  assert(asset, 'Built JavaScript asset is missing')
  assert.equal((await fetch(base + asset)).status, 200)
  // The admin API is private: without an admin session every route answers 401 JSON, never a page.
  for (const path of ['/api/admin/auth/session', '/api/admin/dashboard', '/api/admin/users', '/api/admin/shares']) {
    const response = await fetch(base + path)
    assert.equal(response.status, 401, path)
    assert.equal(response.headers.get('cache-control'), 'private, no-store')
  }
  // The API answers only for configured addresses; forwarded host headers do not change that.
  const foreign = await new Promise((resolveStatus, reject) => {
    request(`${base}/api/workspace/preview-cache`, { headers: { Host: 'attacker.example', 'X-Forwarded-Host': `127.0.0.1:${port}` } },
      response => { response.resume(); resolveStatus(response.statusCode) }).on('error', reject).end()
  })
  assert.equal(foreign, 421)
  console.log('Landing page, workspace and admin entries, API JSON, host checks, authorization and compiled assets passed local HTTP smoke checks.')
  console.log('The local PHP router simulates static routing; Apache rewrite rules remain unverified.')
} finally {
  server.kill()
}
