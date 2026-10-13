// Contact sheets: 3x2 grids of captured shots for quick review.
import { chromium } from 'playwright-core';
import fs from 'node:fs';
import { pathToFileURL } from 'node:url';

const exe = process.env.LOCALAPPDATA + '/ms-playwright/chromium-1243/chrome-win64/chrome.exe';
const dir = process.argv[2] || 'shots';
const files = fs.readdirSync(dir).filter(f => /\.(png|jpg)$/.test(f)).sort();
fs.mkdirSync('sheets', { recursive: true });
const b = await chromium.launch({ executablePath: exe });
const p = await b.newPage({ viewport: { width: 2400, height: 940 } });
for (let i = 0; i < files.length; i += 6) {
  const set = files.slice(i, i + 6);
  const cells = set.map(f => `<div><img src="${pathToFileURL(dir + '/' + f).href}" style="width:800px;height:450px;display:block"><div style="height:20px">${f}</div></div>`).join('');
  fs.writeFileSync('sheets/s.html', `<body style="margin:0;display:grid;grid-template-columns:repeat(3,800px);background:#000;color:#fff;font:16px sans-serif">${cells}</body>`);
  await p.goto(pathToFileURL('sheets/s.html').href);
  await p.waitForTimeout(300);
  await p.screenshot({ path: `sheets/${dir.replace(/\W/g, '')}-${String(i / 6).padStart(2, '0')}.png` });
}
await b.close();
