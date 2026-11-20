import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { readFile, mkdir } from 'node:fs/promises'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium, expect } from '@playwright/test'

// Exercise the compiled worker and CSS under the intended CSP. This does not test .htaccess execution.
const root = fileURLToPath(new URL('../../', import.meta.url))
const release = (await readFile(join(root, 'artifacts/latest-package.txt'), 'utf8')).trim()
const rules = await readFile(join(root, 'deploy/public/.htaccess'), 'utf8')
const policy = rules.match(/Header always set Content-Security-Policy "([^"]+)"/)?.[1]
assert(policy, 'Production CSP is missing')
const publicId = 'a'.repeat(32)
const samples = [
  { path: 'App.xaml.cs', selector: '.hljs-keyword', content: 'using System;\n\nnamespace Glimpse;\n\n// Application startup: čćžšđ\npublic partial class App\n{\n    private string title = "Glimpse";\n    private string example = "<script>alert(1)</script>";\n    private int count = 42;\n}\n' },
  { path: 'App.xaml', selector: '.hljs-attr', content: '<Window Title="Glimpse"><script>window.fixtureExecuted=true</script></Window>' },
  { path: 'Component.tsx', selector: '.hljs-keyword', content: 'export const App = () => <h1>Glimpse</h1>;' },
  { path: 'settings.json', selector: '.hljs-attr', content: '{"name":"Glimpse","enabled":true}' },
  { path: 'index.php', selector: '.hljs-keyword', content: '<?php function greet(): string { return "Glimpse"; }' },
]
let sample = samples[0]
let largeDiff = false
const commit = { sha: 'a'.repeat(40), message: 'Improve startup', messageLimited: false, author: 'Fixture author', date: '2026-09-14T07:00:00Z', parents: ['b'.repeat(40)] }
const server = createServer(async (request, response) => {
  try {
    const url = new URL(request.url, 'http://localhost')
    const path = url.pathname
    response.setHeader('Content-Security-Policy', policy)
    response.setHeader('X-Content-Type-Options', 'nosniff')
    response.setHeader('Cache-Control', 'no-store')
    if (path.startsWith('/api/')) {
      response.setHeader('Content-Type', 'application/json')
      if (path === `/api/shares/${publicId}/session`) response.end(JSON.stringify({ csrfToken: 'fixture-only' }))
      else if (url.searchParams.get('kind') === 'branches') response.end(JSON.stringify({ items: ['main'], defaultBranch: 'main', nextPage: null, allowHistory: true }))
      else if (path === `/api/shares/${publicId}/browse` && ['history', 'commit'].includes(url.searchParams.get('kind'))) response.end(JSON.stringify({
        repository: { id: 1, fullName: 'fixture/history-preview', private: true, defaultBranch: 'main' }, commitSha: commit.sha, path: '', kind: url.searchParams.get('kind'),
        history: { items: [commit], page: 1, nextPage: null, limited: false },
        detail: { commit, page: 1, nextPage: null, filesLimited: false, stats: { additions: 1, deletions: 1, total: 2 }, files: [
          { path: largeDiff ? 'large.txt' : 'App.xaml.cs', previousPath: null, status: 'modified', additions: 1, deletions: 1, patchState: 'complete',
            patch: largeDiff ? '@@ -0,0 +1,10000 @@\n' + Array.from({ length: 10000 }, (_, index) => `+line ${index + 1}`).join('\n')
              : '@@ -1,2 +1,2 @@\n using System;\n-string title = "Old";\n+string title = "<script>window.fixtureExecuted=true</script>";' },
        ] },
      }))
      else if (path === `/api/shares/${publicId}/browse`) response.end(JSON.stringify({
        repository: { id: 1, fullName: 'fixture/syntax-preview', private: true, defaultBranch: 'main' },
        commitSha: 'a'.repeat(40), path: sample.path, kind: 'file',
        file: { path: sample.path, commitSha: 'a'.repeat(40), bytes: Buffer.byteLength(sample.content), content: sample.content },
      }))
      else { response.statusCode = 404; response.end('{}') }
      return
    }
    if (/^\/assets\/[a-zA-Z0-9_-]+\.(?:js|css)$/.test(path)) {
      response.setHeader('Content-Type', path.endsWith('.js') ? 'text/javascript' : 'text/css')
      response.end(await readFile(join(release, 'public', path.slice(1))))
    } else if (path === `/s/${publicId}`) {
      response.setHeader('Content-Type', 'text/html')
      // public/index.html is the landing page; access links use the React entry, as in deploy/public/.htaccess.
      response.end(await readFile(join(release, 'public/workspace.html')))
    } else { response.statusCode = 404; response.end() }
  } catch { response.statusCode = 500; response.end() }
})
let browser
try {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
  const base = `http://127.0.0.1:${server.address().port}`
  browser = await chromium.launch()
  const page = await browser.newPage()
  const failures = []
  const requests = []
  page.on('pageerror', error => failures.push(error.message))
  page.on('request', request => requests.push(request.url()))
  await page.addInitScript(() => { window.cspViolations = []; document.addEventListener('securitypolicyviolation', event => window.cspViolations.push(event.violatedDirective)) })
  for (const next of samples) {
    sample = next
    await page.goto(`${base}/s/${publicId}`)
    await page.locator(`pre ${sample.selector}`).first().waitFor()
    assert.equal(await page.locator('pre').textContent(), sample.content)
    assert.equal(await page.locator('pre script, pre img, pre window').count(), 0)
    assert.equal(await page.evaluate(() => Object.hasOwn(window, 'fixtureExecuted')), false)
    assert.deepEqual(await page.evaluate(() => window.cspViolations), [])
    const tokenColor = await page.locator(`pre ${sample.selector}`).first().evaluate(element => getComputedStyle(element).color)
    const textColor = await page.locator('pre').evaluate(element => getComputedStyle(element).color)
    assert.notEqual(tokenColor, textColor)
    if (sample.path === 'App.xaml.cs') {
      await mkdir(join(root, 'artifacts/screenshots'), { recursive: true })
      await page.screenshot({ path: join(root, 'artifacts/screenshots/syntax-csharp-production.png'), fullPage: true })
      await page.setViewportSize({ width: 390, height: 844 })
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
      await page.screenshot({ path: join(root, 'artifacts/screenshots/syntax-csharp-mobile.png'), fullPage: true })
      await page.setViewportSize({ width: 1280, height: 720 })
    }
  }
  await page.goto(`${base}/s/${publicId}?view=history&sha=${commit.sha}`)
  const diff = page.getByRole('region', { name: 'Diff for App.xaml.cs' })
  await diff.locator('.hljs-keyword').first().waitFor()
  assert.equal(await diff.locator('.hljs-keyword').first().textContent(), 'using')
  assert.equal(await diff.locator('script').count(), 0)
  assert.equal(await page.evaluate(() => Object.hasOwn(window, 'fixtureExecuted')), false)
  assert.deepEqual(await page.evaluate(() => window.cspViolations), [])
  const divider = page.getByRole('separator', { name: 'Resize commits panel' })
  await divider.focus()
  await page.keyboard.press('Home')
  await page.keyboard.press('ArrowRight')
  assert.equal(await divider.getAttribute('aria-valuenow'), '220')
  assert.equal(Math.round((await page.getByRole('complementary', { name: 'Commit history' }).boundingBox()).width), 220)
  await page.getByRole('complementary', { name: 'Commit history' }).getByRole('button', { name: /Improve startup/ }).click()
  await expect(divider).toHaveAttribute('aria-valuenow', '220')
  // Opening a commit starts the preview again, so the panel is measured once it is back.
  await expect.poll(async () => Math.round((await page.getByRole('complementary', { name: 'Commit history' }).boundingBox())?.width ?? 0)).toBe(220)
  await page.setViewportSize({ width: 390, height: 844 })
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
  await page.setViewportSize({ width: 1280, height: 900 })
  largeDiff = true
  await page.reload()
  const large = page.getByRole('region', { name: 'Diff for large.txt' })
  await large.waitFor()
  assert(await large.locator('code').count() <= 50)
  assert(await large.evaluate(element => element.scrollHeight > 200000), 'Virtual diff height was blocked or incorrect')
  await large.evaluate(element => { element.scrollTop = 9000 * 24 })
  // Line numbers are read as text ("New line 9000"); a label on a plain span would be ignored by screen readers.
  await large.getByText('New line 9000', { exact: true }).waitFor()
  assert(await large.locator('code').count() <= 50)
  assert.deepEqual(await page.evaluate(() => window.cspViolations), [])
  assert.deepEqual(failures, [])
  assert(requests.every(url => url.startsWith(base + '/')), 'Highlighting must not contact third-party servers')
  assert(requests.some(url => /\/assets\/highlight-worker-[\w-]+\.js$/.test(url)), 'Compiled worker was not loaded')
  console.log('Built preview passed: C#, XAML, TSX, JSON, PHP, exact source text, History, safe highlighted diff, 10,000-line virtual diff, branch dropdown, persistent panel resize, mobile layout and production CSP. API data was simulated.')
} finally {
  await browser?.close()
  await new Promise(resolve => server.close(resolve))
}
