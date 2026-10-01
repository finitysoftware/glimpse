import { mkdir } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { chromium } from '@playwright/test'

// Renders the landing page's PNG images (link preview and home screen icon) from inline markup.
// Run from tests/e2e after changing the design: node render-website-images.mjs
const website = fileURLToPath(new URL('../../website/assets/', import.meta.url))
const eye = '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>'
const icon = (paths, size) => `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${paths}</svg>`
const check = icon('<path d="m5 12 4 4L19 6"/>', 22)

const preview = `<!DOCTYPE html><html><head><style>
  * { box-sizing: border-box; margin: 0; }
  body { position: relative; width: 1200px; height: 630px; padding: 64px 80px; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between;
    font-family: 'Segoe UI', system-ui, sans-serif; color: #0b1324; -webkit-font-smoothing: antialiased;
    background: radial-gradient(ellipse 760px 460px at 82% 18%, #3b6fff24, transparent 70%), #ffffff; }
  body::before { content: ''; position: absolute; inset: 0; z-index: -1; background-image: linear-gradient(#e4e9f2 1px, transparent 1px), linear-gradient(90deg, #e4e9f2 1px, transparent 1px);
    background-size: 56px 56px; mask-image: radial-gradient(ellipse 70% 80% at 80% 20%, black 20%, transparent 75%); }
  .brand { display: flex; align-items: center; gap: 16px; font-size: 42px; font-weight: 700; letter-spacing: -1.8px; }
  .mark { width: 60px; height: 60px; border-radius: 18px; background: linear-gradient(145deg, #5a86ff, #3b6fff 55%, #2f5ae8); color: white; display: flex; align-items: center; justify-content: center;
    box-shadow: 0 12px 24px -10px #3b6fffcc; }
  .dot { color: #3b6fff; }
  h1 { font-size: 68px; font-weight: 700; line-height: 1.06; letter-spacing: -3px; max-width: 980px; }
  h1 span { background: linear-gradient(92deg, #2a55f5 10%, #5b82ff 60%, #7f9dff); -webkit-background-clip: text; background-clip: text; color: transparent; }
  .foot { display: flex; align-items: center; gap: 34px; font-size: 22px; color: #5b6780; }
  .link { display: flex; align-items: center; gap: 12px; padding: 14px 20px; border: 1px solid #d4dbe8; border-radius: 14px; background: white; color: #8a94a9;
    font-family: Consolas, monospace; font-size: 21px; box-shadow: 0 10px 28px -10px #10182824; }
  .link svg { color: #0e9a63; }
  .link b { color: #0b1324; font-weight: 600; }
  .check { display: flex; align-items: center; gap: 9px; }
  .check svg { color: #0e9a63; }
</style></head><body>
  <div class="brand"><span class="mark">${icon(eye, 38)}</span><span>glimpse<span class="dot">.</span></span></div>
  <h1>Share private GitHub and GitLab repositories <span>with one link.</span></h1>
  <div class="foot">
    <span class="link">${icon('<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>', 22)} glimpse / s / <b>6e1ed7fc…a6ce</b></span>
    <span class="check">${check} No account for visitors</span>
    <span class="check">${check} Read-only</span>
  </div>
</body></html>`

// iOS rounds the corners itself, so the home screen icon is a full square without transparency.
const touchIcon = `<!DOCTYPE html><html><head><style>
  * { margin: 0; }
  body { width: 180px; height: 180px; display: flex; align-items: center; justify-content: center; background: #3b6fff; color: white; }
</style></head><body>${icon(eye, 118)}</body></html>`

await mkdir(website + 'images', { recursive: true })
const browser = await chromium.launch()
try {
  for (const [html, width, height, path] of [
    [preview, 1200, 630, 'images/og-image.png'],
    [touchIcon, 180, 180, 'icons/apple-touch-icon.png'],
  ]) {
    const page = await browser.newPage({ viewport: { width, height } })
    await page.setContent(html)
    await page.screenshot({ path: website + path, omitBackground: false })
    await page.close()
    console.log(`website/assets/${path} (${width}×${height})`)
  }
} finally {
  await browser.close()
}
