// Capture pass: drive the real app (read-only: dialogs are opened, never submitted) and save each
// state as a 2x screenshot plus the viewport boxes of the controls the video's cursor will point at.
import { chromium } from 'playwright-core';
import fs from 'node:fs';

const BASE = 'http://roster-builder.test';
const exe = process.env.LOCALAPPDATA + '/ms-playwright/chromium-1243/chrome-win64/chrome.exe';
const pw = fs.readFileSync(new URL('./.pw', import.meta.url), 'utf8').trim();
const only = process.argv[2] ? process.argv[2].split(',') : null;
fs.mkdirSync('shots', { recursive: true });
const manifestPath = 'shots/shots.json';
const manifest = fs.existsSync(manifestPath) ? JSON.parse(fs.readFileSync(manifestPath, 'utf8')) : {};

const browser = await chromium.launch({ executablePath: exe });
const ctx = await browser.newContext({ viewport: { width: 1600, height: 900 }, deviceScaleFactor: 2, colorScheme: 'light' });
const page = await ctx.newPage();

/** Save the viewport and the boxes of the named locators (CSS px, viewport-relative). */
async function shot(name, targets = {}) {
  await page.waitForTimeout(450);
  await page.mouse.move(1599, 899);
  await page.screenshot({ path: `shots/${name}.png` });
  const boxes = {};
  for (const [key, loc] of Object.entries(targets)) {
    const box = await loc.first().boundingBox().catch(() => null);
    if (box) boxes[key] = { x: Math.round(box.x), y: Math.round(box.y), w: Math.round(box.width), h: Math.round(box.height) };
    else console.warn('missing box', name, key);
  }
  manifest[name] = { file: `${name}.png`, boxes };
  fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 1));
  console.log('shot', name, Object.keys(boxes).join(','));
}
async function go(path) {
  await page.goto(BASE + path);
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(700);
}
/** Scroll the window so the element's top sits `offset` px below the viewport top. */
async function scrollTo(loc, offset = 20) {
  await loc.first().evaluate((el, off) => window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - off), offset);
  await page.waitForTimeout(300);
}
const want = n => !only || only.some(o => n.startsWith(o));

// ---------------------------------------------------------------- sign in
await go('/login');
if (want('login')) {
  await shot('login-empty', { id: page.locator('#login-identifier'), pass: page.locator('#login-password'), submit: page.locator('#login-form button[type=submit]'), forgot: page.getByRole('link', { name: /forgot/i }) });
  await page.fill('#login-identifier', 'scheduler');
  await page.fill('#login-password', 'xxxxxxxxxxxxxx');
  await shot('login-filled', { id: page.locator('#login-identifier'), pass: page.locator('#login-password'), submit: page.locator('#login-form button[type=submit]') });
  await page.fill('#login-identifier', '');
  await page.fill('#login-password', '');
}
await page.fill('#login-identifier', 'brag-video');
await page.fill('#login-password', pw);
await page.press('#login-password', 'Enter');
await page.waitForURL(u => !u.pathname.startsWith('/login'));

// ---------------------------------------------------------------- dashboard
if (want('dash')) {
  await go('/dashboard');
  await shot('dash-top', { kpis: page.locator('.kpi-grid, .kpis').first(), rosters: page.getByRole('heading', { name: 'Rosters' }), current: page.getByRole('link', { name: /review & edit/i }), next: page.getByRole('link', { name: /create & build/i }).or(page.getByRole('button', { name: /create & build/i })), reports: page.getByRole('link', { name: /^reports$/i }), alerts: page.locator('[data-alert-pill]') });
  await scrollTo(page.getByRole('heading', { name: /roster conflicts/i }), 30);
  await shot('dash-lower', { today: page.getByRole('heading', { name: /today's flying/i }), conflicts: page.getByRole('heading', { name: /roster conflicts/i }) });
}

// ---------------------------------------------------------------- airports
if (want('airports')) {
  await go('/airports');
  await shot('airports', { add: page.locator('#add-airport'), base: page.getByText('Crew base', { exact: true }).last() });
  await page.click('#add-airport');
  await shot('airports-dialog', { dialog: page.locator('#airport-dialog'), base: page.locator('#airport-dialog label', { hasText: /crew base/i }), check: page.locator('#airport-dialog input[type=checkbox]') });
  await page.keyboard.press('Escape');
}

// ---------------------------------------------------------------- flight routes
if (want('flights')) {
  await go('/flights');
  await shot('flights', { add: page.locator('#add-flight'), toggle: page.locator('tbody tr').first().locator('input[type=checkbox], [role=switch]'), breakdown: page.getByRole('heading', { name: /rotation breakdown/i }), la1: page.locator('tbody tr', { hasText: 'LA1' }).first() });
  // Edit LA1 (the night-stop rotation) to show connected legs; the dialog is closed, never saved.
  await page.locator('tbody tr', { hasText: 'LA1' }).first().getByRole('button', { name: /edit/i }).click();
  await page.waitForTimeout(600);
  await shot('flights-dialog', { dialog: page.locator('#flight-dialog'), addLeg: page.locator('#add-leg'), legs: page.locator('#flight-dialog').getByText(/night/i).first() });
  await page.locator('#flight-dialog').evaluate(d => { const s = d.querySelector('.dialog-body, form') || d; d.scrollTop = 400; s.scrollTop = 400; });
  await shot('flights-dialog2', { dialog: page.locator('#flight-dialog'), addLeg: page.locator('#add-leg') });
  await page.keyboard.press('Escape');
}

// ---------------------------------------------------------------- fleet + maintenance
if (want('fleet')) {
  await go('/aircraft');
  await scrollTo(page.getByRole('heading', { name: 'Airframes' }), 160);
  await shot('fleet', { dmb: page.locator('article, .card', { hasText: '7Q-DMB' }).first(), avail: page.locator('button', { hasText: /^s*availabilitys*$/i }).nth(1), log: page.locator('button', { hasText: /^s*logs*$/i }).first(), register: page.locator('#add-aircraft'), mlog: page.getByRole('link', { name: /maintenance log/i }) });
  await page.locator('button', { hasText: /^s*availabilitys*$/i }).nth(1).click();
  await shot('fleet-dialog', { dialog: page.locator('#status-dialog') });
  await page.keyboard.press('Escape');
}
if (want('maint')) {
  await go('/maintenance');
  await shot('maint', { add: page.locator('#add-record'), overdue: page.getByText(/overdue · 7Q-DMB/i).first(), complete: page.getByRole('button', { name: /record completion/i }).first(), soon: page.getByText(/due soon · 7Q-DMC/i).first() });
  await page.getByRole('button', { name: /record completion/i }).first().click();
  await shot('maint-dialog', { dialog: page.locator('#record-dialog') });
  await page.keyboard.press('Escape');
}

// ---------------------------------------------------------------- crew
if (want('crew')) {
  await go('/crew');
  await shot('crew', { add: page.locator('#add-crew'), expiring: page.getByText('3d left').first(), filters: page.getByRole('button', { name: /^captains$/i }), search: page.getByPlaceholder(/name, email or base/i) });
  await page.locator('tbody tr', { hasText: 'CPT Demo 01' }).first().getByRole('button', { name: /edit/i }).click();
  await page.waitForTimeout(600);
  await shot('crew-dialog', { dialog: page.locator('#crew-dialog') });
  await page.keyboard.press('Escape');
}

// ---------------------------------------------------------------- duty rules
if (want('rules')) {
  await go('/rules');
  await shot('rules', { daily: page.getByLabel(/maximum daily duty/i), rest: page.getByLabel(/minimum rest/i), block: page.getByLabel(/calendar-month block/i) });
  await scrollTo(page.getByText(/days off/i).first(), 120);
  await shot('rules2', { save: page.getByRole('button', { name: /save/i }) });
}

// ---------------------------------------------------------------- roster
if (want('roster')) {
  await go('/roster');
  await shot('roster-top', { newRoster: page.locator('#new-roster'), build: page.locator('#build-week'), publish: page.locator('#publish-week'), current: page.locator('[data-current=true]').first(), next: page.getByText('19–25 Oct 2026').first(), kpis: page.getByText('Seat coverage').first() });
  await page.click('#new-roster');
  await page.waitForTimeout(500);
  await shot('roster-create', { dialog: page.locator('#create-dialog') });
  // Pick "1 month" in the dialog to show the date-coverage preview update (not created).
  const month = page.locator('#create-dialog').getByText(/month/i).first();
  if (await month.count()) { await month.click().catch(() => {}); await page.waitForTimeout(400); }
  await shot('roster-create2', { dialog: page.locator('#create-dialog'), submit: page.locator('#create-dialog').getByRole('button', { name: /create/i }).last() });
  await page.keyboard.press('Escape');

  // The grid with the inspector: scroll so the grid header sits near the top.
  await scrollTo(page.locator('#view-switch'), 90);
  await shot('roster-grid', { grid: page.locator('.roster-grid-wrap'), open: page.getByText('Open time').first(), inspector: page.locator('.inspector') });
  // Select a future duty so the inspector shows its seats and the Change buttons.
  const grid = page.locator('.roster-grid-wrap');
  await grid.evaluate(el => { el.scrollLeft = 700; });
  await page.waitForTimeout(300);
  const duty = page.locator('button.duty[aria-label*="LB2"]').filter({ hasNotText: '→' });
  let target = null;
  for (const handle of await duty.all()) {
    const box = await handle.boundingBox();
    if (box && box.x > 700 && box.x < 1100 && box.y > 250 && box.y < 800) { target = handle; break; }
  }
  if (!target) target = page.locator('button.duty').nth(12);
  const tbox = await target.boundingBox();
  manifest.dutyTarget = tbox;
  await shot('roster-grid-scrolled', { duty: target, inspector: page.locator('.inspector') });
  await target.click();
  await page.waitForTimeout(700);
  await shot('roster-inspect', { duty: target, inspector: page.locator('.inspector'), change: page.locator('.inspector').getByRole('button', { name: /^change$/i }).first(), seat: page.locator('.seat-row').first(), never: page.getByRole('button', { name: /never on this trip/i }).first() });
  await page.locator('.inspector').getByRole('button', { name: /^change$/i }).first().click();
  await page.waitForTimeout(1200);
  await shot('roster-seat', { dialog: page.locator('#seat-dialog'), list: page.locator('#candidate-list'), assign: page.locator('#seat-dialog button[type=submit]') });
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);

  // Day planning from an empty grid cell.
  const plan = page.locator('button.plan-cell');
  let planTarget = null;
  for (const handle of await plan.all()) {
    const box = await handle.boundingBox();
    if (box && box.x > 500 && box.x < 1100 && box.y > 300 && box.y < 800) { planTarget = handle; break; }
  }
  if (planTarget) {
    await planTarget.hover();
    await page.waitForTimeout(300);
    const pbox = await planTarget.boundingBox();
    await page.screenshot({ path: 'shots/roster-plan-hover.png' });
    manifest['roster-plan-hover'] = { file: 'roster-plan-hover.png', boxes: { cell: { x: Math.round(pbox.x), y: Math.round(pbox.y), w: Math.round(pbox.width), h: Math.round(pbox.height) } } };
    await planTarget.click();
    await page.waitForTimeout(700);
    await page.selectOption('#plan-form select[name=type]', 'sim').catch(() => {});
    await page.fill('#plan-form input[name=starts_local]', '08:00').catch(() => {});
    await page.fill('#plan-form input[name=ends_local]', '12:00').catch(() => {});
    await shot('roster-plan', { dialog: page.locator('#plan-dialog'), type: page.locator('#plan-form select[name=type]'), add: page.locator('#plan-form button[type=submit]') });
    await page.keyboard.press('Escape');
  }

  // The other views: individual, trips and conflicts.
  for (const label of ['Crew grid', 'Individual', 'Trips', 'Conflicts']) {
    await page.locator('#view-switch').getByRole('button', { name: new RegExp('^' + label + '$', 'i') }).click();
    await page.waitForTimeout(800);
    await scrollTo(page.locator('#view-switch'), 170);
    await shot('roster-view-' + label.toLowerCase().replace(' ', '-'), { tab: page.locator('#view-switch').getByRole('button', { name: new RegExp('^' + label + '$', 'i') }) });
  }
  await page.locator('#view-switch').getByRole('button', { name: /^crew grid$/i }).click();
  await page.waitForTimeout(600);

  // Outputs bar under the grid.
  await scrollTo(page.locator('#download-csv'), 520);
  await shot('roster-outputs', { csv: page.locator('#download-csv'), pdf: page.locator('#download-pdf'), crewpdf: page.locator('#download-crew-pdf'), print: page.locator('#print-week') });
}

// ---------------------------------------------------------------- hours, reports, import
if (want('hours')) {
  await go('/hours');
  await shot('hours', { pilots: page.getByRole('button', { name: /^pilots$/i }), cabin: page.getByRole('button', { name: /^cabin crew$/i }) });
  await page.getByRole('button', { name: /^cabin crew$/i }).click();
  await page.waitForTimeout(700);
  await shot('hours-cabin', { cabin: page.getByRole('button', { name: /^cabin crew$/i }) });
}
if (want('reports')) {
  await go('/reports');
  await shot('reports', { coverage: page.getByRole('button', { name: /roster coverage by week/i }), csv: page.locator('#report-csv'), pdf: page.locator('#report-pdf'), run: page.getByRole('button', { name: /run report/i }) });
  await page.getByRole('button', { name: /crew document expiry/i }).click();
  await page.waitForTimeout(1200);
  await shot('reports-docs', { tab: page.getByRole('button', { name: /crew document expiry/i }), pdf: page.locator('#report-pdf') });
  await page.getByRole('button', { name: /audit log/i }).click();
  await page.waitForTimeout(1200);
  await shot('reports-audit', { tab: page.getByRole('button', { name: /audit log/i }) });
}
if (want('data')) {
  await go('/data');
  await shot('data', { kind: page.locator('select').first(), template: page.locator('#download-template'), preview: page.getByRole('button', { name: /^preview$/i }), merge: page.getByText('Merge', { exact: true }), backup: page.locator('#download-backup') });
}

// ---------------------------------------------------------------- admin
if (want('accounts')) {
  await go('/accounts');
  // Hide the temporary capture account so the shot shows the real account list.
  await page.evaluate(() => document.querySelectorAll('tbody tr').forEach(tr => { if (tr.textContent.includes('brag-video')) tr.remove(); }));
  await shot('accounts', { add: page.locator('#add-account'), airports: page.getByRole('link', { name: /^airports$/i }) });
  await page.click('#add-account');
  await page.waitForTimeout(500);
  await shot('accounts-dialog', { dialog: page.locator('#account-edit-dialog') });
  await page.keyboard.press('Escape');
}
if (want('dark')) {
  await go('/dashboard');
  await shot('theme-light', { toggle: page.locator('#theme-toggle'), account: page.locator('#my-account') });
  await page.click('#theme-toggle');
  await page.waitForTimeout(600);
  await shot('theme-dark', { toggle: page.locator('#theme-toggle') });
  await page.click('#my-account');
  await page.waitForTimeout(500);
  // Drop the temporary capture username from the subtitle; a real user sees their own.
  await page.evaluate(() => { const p = document.querySelector('#account-dialog .dialog-heading p'); p.textContent = p.textContent.replace(/ · brag-video$/, ''); });
  await shot('theme-account', { dialog: page.locator('#account-dialog') });
  await page.keyboard.press('Escape');
  await page.click('#theme-toggle'); // restore light for later runs
}

fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 1));
await browser.close();
