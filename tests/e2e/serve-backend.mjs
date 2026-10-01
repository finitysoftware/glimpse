import { spawn, spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const php = process.env.PHP_BIN || 'php'
const backend = fileURLToPath(new URL('../../backend/', import.meta.url))
const env = { ...process.env, GLIMPSE_ENV: 'test', GLIMPSE_ORIGIN: 'http://127.0.0.1:5173',
  GLIMPSE_DB_DSN: '', GLIMPSE_DB_USER: '', GLIMPSE_DB_PASSWORD: '',
  GLIMPSE_BOOTSTRAP: '', GLIMPSE_SHARE_ENCRYPTION_KEY: Buffer.alloc(32, 107).toString('base64'),
  GLIMPSE_GITHUB_TOKEN_KEY: Buffer.alloc(32, 116).toString('base64'), GLIMPSE_GITHUB_TOKEN_KEY_ID: 'test-key' }
if (process.env.GLIMPSE_TEST_DB_DSN) {
  if (process.env.GLIMPSE_TEST_DB_RESET !== '1' || !/dbname=glimpse_test(?:;|$)/.test(process.env.GLIMPSE_TEST_DB_DSN)) {
    throw new Error('E2E database must be glimpse_test with explicit reset permission.')
  }
  env.GLIMPSE_DB_DSN = process.env.GLIMPSE_TEST_DB_DSN
  env.GLIMPSE_DB_USER = process.env.GLIMPSE_TEST_DB_USER || 'root'
  env.GLIMPSE_DB_PASSWORD = process.env.GLIMPSE_TEST_DB_PASSWORD || ''
  env.GLIMPSE_BOOTSTRAP = fileURLToPath(new URL('../../backend/tests/Fixtures/bootstrap-e2e.php', import.meta.url))
  const prepare = spawnSync(php, ['tests/Fixtures/prepare-e2e.php'], { cwd: backend, env, stdio: 'inherit' })
  if (prepare.status !== 0) throw new Error('Cannot prepare isolated browser-test database.')
}
const server = spawn(php, ['-S', '127.0.0.1:8080', '-t', 'public', 'bin/serve.php'], { cwd: backend, env, stdio: 'inherit' })
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => server.kill())
server.on('exit', code => process.exit(code ?? 1))
