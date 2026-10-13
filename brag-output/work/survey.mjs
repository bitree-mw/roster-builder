// Survey pass: sign in with the temporary account and screenshot every page at video size.
import { chromium } from 'playwright-core';
import fs from 'node:fs';

const BASE = 'http://roster-builder.test';
const exe = process.env.LOCALAPPDATA + '/ms-playwright/chromium-1243/chrome-win64/chrome.exe';
const pw = fs.readFileSync(new URL('./.pw', import.meta.url), 'utf8').trim();
fs.mkdirSync('survey', { recursive: true });

const browser = await chromium.launch({ executablePath: exe });
const ctx = await browser.newContext({ viewport: { width: 1600, height: 900 }, deviceScaleFactor: 1, colorScheme: 'light' });
const page = await ctx.newPage();
await page.goto(BASE + '/login');
await page.screenshot({ path: 'survey/00-login.png' });
await page.fill('#login-identifier', 'brag-video');
await page.fill('#login-password', pw);
await page.press('#login-password', 'Enter');
const resp = await page.waitForResponse(r => r.url().endsWith('/login') && r.request().method() === 'POST'); console.log('login', resp.status()); await page.waitForURL(u => !u.pathname.startsWith('/login'));

const pages = ['dashboard', 'roster', 'flights', 'aircraft', 'maintenance', 'crew', 'hours', 'rules', 'data', 'reports', 'accounts', 'airports'];
for (const [i, p] of pages.entries()) {
  await page.goto(BASE + '/' + p);
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(800);
  const n = String(i + 1).padStart(2, '0');
  await page.screenshot({ path: `survey/${n}-${p}.png` });
  await page.screenshot({ path: `survey/${n}-${p}-full.png`, fullPage: true });
}
await browser.close();
