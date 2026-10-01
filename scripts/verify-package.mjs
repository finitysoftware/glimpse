import assert from 'node:assert/strict'
import { readdir, readFile, lstat } from 'node:fs/promises'
import { join, resolve } from 'node:path'
import { pathToFileURL } from 'node:url'

export async function files(directory, prefix = '') {
  const result = []
  for (const name of await readdir(directory)) {
    const path = join(directory, name)
    const relative = prefix + name
    const stat = await lstat(path)
    assert(!stat.isSymbolicLink(), `Symlink is not allowed in a release: ${relative}`)
    if (stat.isDirectory()) result.push(...await files(path, relative + '/'))
    else result.push(relative)
  }
  return result
}

// The landing page, robots.txt, sitemap.xml and the structured data name one canonical domain, and the FAQ data repeats the visible FAQ.
// siteRoot is the directory served at /; the canonical URL is returned for callers that need it.
export async function verifySearchMetadata(siteRoot, landing, publicFiles) {
  const canonical = landing.match(/<link rel="canonical" href="(https:\/\/[^"/]+\/)">/)?.[1]
  assert(canonical, 'The landing page needs an https canonical URL')
  const meta = property => landing.match(new RegExp(`<meta property="${property}" content="([^"]+)">`))?.[1]
  assert.equal(meta('og:url'), canonical, 'og:url must be the canonical URL')
  const image = meta('og:image') ?? ''
  assert(image.startsWith(canonical) && publicFiles.includes(image.slice(canonical.length)), 'og:image must be a packaged file on the canonical domain')
  const robots = await readFile(join(siteRoot, 'robots.txt'), 'utf8')
  assert(robots.includes(`Sitemap: ${canonical}sitemap.xml`) && !/^Disallow: \/\s*$/m.test(robots), 'robots.txt must allow the landing page and name the sitemap')
  const sitemap = await readFile(join(siteRoot, 'sitemap.xml'), 'utf8')
  assert(sitemap.includes(`<loc>${canonical}</loc>`), 'sitemap.xml must list the canonical URL')
  const data = JSON.parse(landing.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/)?.[1] ?? 'null')
  assert(data?.['@graph'], 'Structured data is missing')
  // The publisher's own website is the one address allowed outside the canonical domain.
  const publisher = data['@graph'].find(node => node['@type'] === 'Organization')
  assert(!publisher?.url || /^https:\/\/[^/]+\/$/.test(publisher.url), 'The publisher URL must be an https site root')
  const urls = JSON.stringify(data['@graph'].map(node => node === publisher ? { ...node, url: undefined } : node)).match(/https:\/\/[^"#]+/g)
  assert(urls.every(url => url.startsWith(canonical)), 'Structured data must use the canonical domain')
  const decode = text => text.replace(/&(?:lt|gt|quot|#39);/g, entity => ({ '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#39;': "'" })[entity]).replaceAll('&amp;', '&')
  const visible = [...landing.matchAll(/<summary data-i18n="faq\.q\d+">(.*?)<i data-icon="chevron-down"><\/i><\/summary><p data-i18n="faq\.a\d+">(.*?)<\/p>/g)]
    .map(([, question, answer]) => ({ question: decode(question), answer: decode(answer) }))
  const faq = data['@graph'].find(node => node['@type'] === 'FAQPage')?.mainEntity
    .map(item => ({ question: item.name, answer: item.acceptedAnswer.text }))
  assert(visible.length > 0, 'The landing page FAQ was not found')
  assert.deepEqual(faq, visible, 'FAQ structured data must repeat the visible FAQ')
  return canonical
}

export async function verifyPackage(release) {
  // All three rule files are required for the nested public/private layout.
  // This checks packaging integrity, not whether a web server enforces the rules.
  for (const path of ['.htaccess', 'public/.htaccess', 'private/.htaccess']) {
    assert(!(await lstat(join(release, path))).isSymbolicLink(), `Symlink is not allowed: ${path}`)
    const expected = await readFile(new URL(`../deploy/${path}`, import.meta.url), 'utf8')
    assert.equal(await readFile(join(release, path), 'utf8'), expected, `Missing or changed hosting rules: ${path}`)
  }
  const publicFiles = await files(join(release, 'public'))
  for (const path of publicFiles) {
    assert(['index.html', 'workspace.html', 'favicon.svg', '.htaccess', 'robots.txt', 'sitemap.xml', 'api/index.php'].includes(path)
      || /^assets\/[a-zA-Z0-9_-]+\.(js|css|woff2?|png|webp|svg)$/.test(path)
      || /^assets\/(?:css\/[a-zA-Z0-9_-]+\.css|js\/[a-zA-Z0-9_-]+\.js|icons\/[a-zA-Z0-9_-]+\.(?:svg|png)|images\/[a-zA-Z0-9_-]+\.png)$/.test(path), `Unexpected public file: ${path}`)
  }
  for (const path of ['api/index.php', 'index.html', 'workspace.html', 'favicon.svg', '.htaccess', 'robots.txt', 'sitemap.xml']) assert(publicFiles.includes(path), `Missing public file: ${path}`)
  // The workspace tab shows the same icon as the landing page; frontend/public keeps a copy for the Vite dev server.
  assert.equal(await readFile(join(release, 'public/favicon.svg'), 'utf8'), await readFile(new URL('../website/assets/icons/favicon.svg', import.meta.url), 'utf8'), 'public/favicon.svg must be the landing page icon')
  // / is the static landing page and must lead to sign-in; only workspace.html mounts the React application.
  const landing = await readFile(join(release, 'public/index.html'), 'utf8')
  const workspace = await readFile(join(release, 'public/workspace.html'), 'utf8')
  assert(!landing.includes('id="root"') && landing.includes('href="/login"') && !landing.includes('/admin'), 'public/index.html must be the landing page')
  assert(workspace.includes('id="root"') && /src="\/assets\/[^"]+\.js"/.test(workspace), 'public/workspace.html must be the React entry')
  await verifySearchMetadata(join(release, 'public'), landing, publicFiles)
  const privateFiles = await files(join(release, 'private'))
  assert(privateFiles.includes('backend/vendor/autoload.php'))
  assert(!privateFiles.some(path => /(?:^|\/)(?:[^/]+\.local\.php|\.env)(?:\/|$)/.test(path)), 'Release must not contain local configuration or encryption keys')
  assert(!privateFiles.some(path => path.startsWith('backend/tests/') || /^backend\/vendor\/(phpunit|phpstan)\//.test(path)))
  const installed = JSON.parse(await readFile(join(release, 'private/backend/vendor/composer/installed.json'), 'utf8'))
  assert.equal(installed.dev, false, 'Release contains development dependencies')
  const entry = await readFile(join(release, 'public/api/index.php'), 'utf8')
  assert(entry.includes("'/private/backend/bootstrap.php'"))
  console.log(`Package contents verified: ${publicFiles.length} public files, three hosting rule files and production-only private PHP dependencies.`)
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const release = process.argv[2] || (await readFile(new URL('../artifacts/latest-package.txt', import.meta.url), 'utf8')).trim()
  await verifyPackage(resolve(release))
}
