/**
 * =====================================================================
 * CHỨC NĂNG FILE: Build UI thật, seed SQLite tạm và phục vụ Laravel cho E2E.
 * CÁC HÀM/METHOD TRONG FILE: Không có; entrypoint chạy build/seed rồi PHP server.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): thư mục tạm riêng -> process HTTP chỉ bind localhost.
 * =====================================================================
 */
import { execFileSync, spawn } from 'node:child_process'
import { realpathSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { basename, dirname, join } from 'node:path'

const root = process.env.MAKERT_E2E_ROOT
if (!root) throw new Error('Run E2E through Playwright configuration.')

const target = realpathSync(root)

if (dirname(target) !== realpathSync(tmpdir()) || !basename(target).startsWith('makert-e2e-'))
  throw new Error('E2E storage must be a dedicated temporary directory.')
const database = join(root, 'database.sqlite')
const php = process.env.PHP_BINARY || 'php'

const env = {
  ...process.env,
  APP_ENV: 'testing',
  APP_DEBUG: 'false',
  APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
  APP_URL: 'http://127.0.0.1:8179',
  APP_CONFIG_CACHE: join(root, 'config.php'),
  APP_ROUTES_CACHE: join(root, 'routes.php'),
  DB_CONNECTION: 'sqlite',
  DB_URL: '',
  DB_DATABASE: database,
  SESSION_DRIVER: 'array',
  CACHE_STORE: 'array',
  QUEUE_CONNECTION: 'database',
  MAIL_MAILER: 'array',
  LOG_CHANNEL: 'stderr',
  BCRYPT_ROUNDS: '4',
  VITE_ENABLE_MSW: 'false',
  VITE_API_BASE_URL: '/api',
}

writeFileSync(database, '', { flag: 'wx' })
execFileSync(process.execPath, ['node_modules/vite/bin/vite.js', 'build'], { env, stdio: 'inherit' })
execFileSync(php, ['tests/e2e/seed.php'], { env, stdio: 'inherit' })

const server = spawn(php, ['-S', '127.0.0.1:8179', '-t', 'public', 'tests/e2e/router.php'], { env, stdio: 'inherit' })

server.on('error', error => { throw error })
server.on('exit', code => process.exit(code ?? 1))
process.on('SIGTERM', () => server.kill())
process.on('SIGINT', () => server.kill())
