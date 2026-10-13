// Render driver. `node render.mjs stills 1.5,7,20` writes stills/<t>.png; `node render.mjs video` pipes every
// frame (30 fps) into ffmpeg as JPEG and writes video-silent.mp4.
import { chromium } from 'playwright-core';
import fs from 'node:fs';
import { spawn } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import ffmpeg from 'ffmpeg-static';

const exe = process.env.LOCALAPPDATA + '/ms-playwright/chromium-1243/chrome-win64/chrome.exe';
fs.writeFileSync('composition/shots.js', 'window.SHOTS = ' + fs.readFileSync('shots/shots.json', 'utf8') + ';\n');
const [mode = 'stills', arg] = process.argv.slice(2);

const browser = await chromium.launch({ executablePath: exe, args: ['--allow-file-access-from-files'] });
const page = await browser.newPage({ viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 1 });
page.on('pageerror', e => console.error('page error', e.message));
await page.goto(pathToFileURL('composition/index.html').href);
await page.evaluate(() => window.ready);
const cues = await page.evaluate(() => window.cues);
fs.writeFileSync('cues.json', JSON.stringify(cues));

if (mode === 'stills') {
  fs.mkdirSync('stills', { recursive: true });
  for (const t of arg.split(',').map(Number)) {
    await page.evaluate(t => window.render(t), t);
    await page.screenshot({ path: `stills/${String(t.toFixed(2)).padStart(7, '0')}.png` });
  }
} else {
  const fps = 30, frames = Math.round(cues.total * fps);
  const ff = spawn(ffmpeg, ['-y', '-f', 'image2pipe', '-framerate', String(fps), '-c:v', 'mjpeg', '-i', '-',
    '-c:v', 'libx264', '-preset', 'slow', '-crf', '17', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', 'video-silent.mp4'],
    { stdio: ['pipe', 'ignore', 'inherit'] });
  const started = Date.now();
  for (let f = 0; f < frames; f++) {
    await page.evaluate(t => window.render(t), f / fps);
    const buf = await page.screenshot({ type: 'jpeg', quality: 95 });
    if (!ff.stdin.write(buf)) await new Promise(r => ff.stdin.once('drain', r));
    if (f % 300 === 0) console.log(`frame ${f}/${frames} ${((Date.now() - started) / 1000).toFixed(0)}s`);
  }
  ff.stdin.end();
  await new Promise(r => ff.on('close', r));
}
await browser.close();
