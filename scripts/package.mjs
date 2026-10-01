import { cp, mkdir, readFile, rename, writeFile } from 'node:fs/promises'
import { resolve, join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { verifyPackage } from './verify-package.mjs'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const php = process.env.PHP_BIN || 'php'
const release = join(root, 'artifacts', 'releases', `${new Date().toISOString().replace(/[:.]/g, '-')}-${randomBytes(3).toString('hex')}`)
const privateBackend = join(release, 'private', 'backend')

// On Windows, pnpm and Composer are .cmd shims (or pnpm.exe), which only a shell resolves.
function run(command, args, cwd, shell = process.platform === 'win32' && command.endsWith('.cmd')) {
  const result = spawnSync(command, args, { cwd, stdio: 'inherit', shell })
  if (result.error) throw result.error
  if (result.status !== 0) throw new Error(`${command} failed with status ${result.status}`)
}
function composer(args) {
  if (process.env.COMPOSER_PHAR) run(php, [resolve(process.env.COMPOSER_PHAR), ...args], privateBackend)
  else run(process.platform === 'win32' ? 'composer.cmd' : 'composer', args, privateBackend)
}

// Fail before creating a candidate if the frontend cannot be built.
run('pnpm', ['run', 'build'], join(root, 'frontend'), process.platform === 'win32')
await mkdir(privateBackend, { recursive: true })
await mkdir(join(release, 'private', 'logs'), { recursive: true })
await cp(join(root, 'deploy', '.htaccess'), join(release, '.htaccess'))
await cp(join(root, 'deploy', 'private', '.htaccess'), join(release, 'private', '.htaccess'))
for (const path of ['src', 'bin', 'migrations', 'bootstrap.php', 'composer.json', 'composer.lock', 'config/settings.example.php']) {
  await cp(join(root, 'backend', path), join(privateBackend, path), { recursive: true })
}
composer(['install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction', '--no-scripts', '--no-plugins'])
composer(['check-platform-reqs', '--no-dev'])
await cp(join(root, 'frontend', 'dist'), join(release, 'public'), { recursive: true })
// The React build becomes the workspace entry; the static landing page owns /, robots.txt and sitemap.xml and brings its own assets/css|js|icons|images.
await rename(join(release, 'public', 'index.html'), join(release, 'public', 'workspace.html'))
for (const file of ['index.html', 'robots.txt', 'sitemap.xml']) {
  await cp(join(root, 'website', file), join(release, 'public', file), { errorOnExist: true, force: false })
}
for (const folder of ['css', 'js', 'icons', 'images']) {
  await cp(join(root, 'website', 'assets', folder), join(release, 'public', 'assets', folder), { recursive: true, errorOnExist: true, force: false })
}
await cp(join(root, 'deploy', 'public', '.htaccess'), join(release, 'public', '.htaccess'))
await mkdir(join(release, 'public', 'api'), { recursive: true })
const entry = await readFile(join(root, 'backend', 'public', 'api', 'index.php'), 'utf8')
await writeFile(join(release, 'public', 'api', 'index.php'), entry
  .replace('// Development default. The package builder replaces this path for the hosting layout.', '// Hosting default: public/ and private/ are siblings inside the subdomain document root.')
  .replace("dirname(__DIR__, 2) . '/bootstrap.php'", "dirname(__DIR__, 2) . '/private/backend/bootstrap.php'"))
await verifyPackage(release)
await writeFile(join(root, 'artifacts', 'latest-package.txt'), release + '\n')
console.log(`Production candidate prepared: ${release}`)
console.log('Upload .htaccess, public/ and private/ into the document root of the site (build-package.ps1 prints it). No deployment was performed.')
