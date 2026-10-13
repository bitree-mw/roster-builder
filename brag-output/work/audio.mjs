// Soundtrack: an original synthesized bed (D major, 92 BPM) plus clicks, typing ticks and chapter swells/chimes
// in the same key, through one shared reverb. Reads cues.json (written by render.mjs); writes audio.wav.
import fs from 'node:fs';

const SR = 48000;
const cues = JSON.parse(fs.readFileSync('cues.json', 'utf8'));
const TOTAL = cues.total, N = Math.ceil(TOTAL * SR);
const L = new Float32Array(N), R = new Float32Array(N);       // dry bus
const sendL = new Float32Array(N), sendR = new Float32Array(N); // reverb send
const BEAT = 60 / 92, BAR = BEAT * 4;
const mtof = m => 440 * Math.pow(2, (m - 69) / 12);
const INTRO_END = 6, OUTRO = TOTAL - 6;

/** Add a mono sample to the buses with constant-power pan (-1..1) and a reverb send amount. */
function put(i, v, pan = 0, send = 0) {
  if (i < 0 || i >= N) return;
  const a = (pan + 1) * Math.PI / 4, l = Math.cos(a) * v, r = Math.sin(a) * v;
  L[i] += l; R[i] += r; sendL[i] += l * send; sendR[i] += r * send;
}
// Section level for the rhythm parts: in after the intro, out for the outro, a short breath at the Rosters section.
function groove(t) {
  if (t < INTRO_END - 0.5 || t > OUTRO + 0.2) return 0;
  return Math.min(1, (t - (INTRO_END - 0.5)) / 1.5) * Math.min(1, (OUTRO + 0.2 - t) / 0.8);
}

// ---------------------------------------------------------------- harmony: two bars per chord
const chords = [
  { bass: 38, pad: [50, 57, 61, 66], arp: [62, 66, 69, 73] }, // Dmaj7
  { bass: 35, pad: [47, 54, 57, 62], arp: [59, 62, 66, 69] }, // Bm7
  { bass: 43, pad: [55, 59, 62, 66], arp: [62, 66, 67, 71] }, // Gmaj7
  { bass: 45, pad: [57, 62, 64, 66], arp: [62, 64, 66, 69] }, // A6sus
];
const segLen = BAR * 2;
const segments = [];
for (let s = 0, k = 0; s < TOTAL; s += segLen, k++) segments.push({ start: s, end: Math.min(TOTAL, s + segLen), chord: chords[k % 4] });
// Resolve the final segment onto D so the outro lands home.
segments[segments.length - 1].chord = chords[0];

// ---------------------------------------------------------------- pad: detuned polyBLEP saws, two-pole low-pass
const blep = (t, dt) => t < dt ? (t /= dt, t + t - t * t - 1) : t > 1 - dt ? (t = (t - 1) / dt, t * t + t + t + 1) : 0;
segments.forEach((seg, si) => {
  const last = si === segments.length - 1;
  const s0 = Math.floor(seg.start * SR), s1 = Math.min(N, Math.floor((seg.end + (last ? 0 : 1.6)) * SR));
  seg.chord.pad.forEach((note, vi) => {
    for (const det of [-0.08, 0, 0.08]) {
      const f = mtof(note + det), dt = f / SR;
      let ph = Math.random(), z1 = 0, z2 = 0;
      const pan = (vi - 1.5) / 2.2 + det * 2;
      for (let i = s0; i < s1; i++) {
        const t = i / SR, lt = t - seg.start;
        ph += dt; if (ph >= 1) ph -= 1;
        const saw = 2 * ph - 1 - blep(ph, dt);
        // Brighter once the tutorial is under way; gentle slow movement.
        const cut = (t < INTRO_END ? 700 + 120 * t : 1500) + 250 * Math.sin(t * 0.35 + vi);
        const a = 1 - Math.exp(-2 * Math.PI * cut / SR);
        z1 += a * (saw - z1); z2 += a * (z1 - z2);
        const att = Math.min(1, lt / 1.2), rel = lt > segLen ? Math.max(0, 1 - (lt - segLen) / 1.6) : 1;
        const fadeEnd = last ? Math.max(0, Math.min(1, (TOTAL - t) / 3)) : 1;
        put(i, z2 * 0.022 * att * rel * fadeEnd, pan, 0.55);
      }
    }
  });
});

// ---------------------------------------------------------------- bass: soft sine on half notes
for (const seg of segments) {
  for (let b = 0; b < 4; b++) {
    const t0 = seg.start + b * BEAT * 2;
    if (groove(t0) === 0) continue;
    const f = mtof(seg.chord.bass), len = BEAT * 2 - 0.05;
    for (let i = Math.floor(t0 * SR), e = Math.floor((t0 + len) * SR); i < e; i++) {
      const lt = i / SR - t0;
      const env = Math.min(1, lt / 0.015) * (0.75 + 0.25 * Math.exp(-lt * 4)) * Math.min(1, (len - lt) / 0.08);
      const v = Math.sin(2 * Math.PI * f * lt) + 0.18 * Math.sin(4 * Math.PI * f * lt);
      put(i, v * 0.11 * env * groove(t0), 0, 0.05);
    }
  }
}

// ---------------------------------------------------------------- pluck arpeggio: eighths in the groove, quarters outside it
const arpOrder = [0, 1, 2, 3, 2, 1, 2, 3];
for (const seg of segments) {
  for (let n = 0; n < 16; n++) {
    const t0 = seg.start + n * BEAT / 2;
    if (t0 >= TOTAL - 0.5) break;
    const g = groove(t0);
    if (g === 0 && n % 2) continue; // sparse outside the groove
    const note = seg.chord.arp[arpOrder[n % 8]] + 12, f = mtof(note);
    const vel = (n % 4 === 0 ? 1 : 0.7) * (0.55 + 0.45 * g) * Math.min(1, (TOTAL - t0) / 3);
    for (let i = Math.floor(t0 * SR), e = Math.min(N, Math.floor((t0 + 0.9) * SR)); i < e; i++) {
      const lt = i / SR - t0;
      const env = Math.min(1, lt / 0.003) * Math.exp(-lt * 7);
      const v = Math.sin(2 * Math.PI * f * lt) + 0.25 * Math.sin(4 * Math.PI * f * lt) * Math.exp(-lt * 14);
      put(i, v * 0.032 * vel * env, n % 2 ? 0.35 : -0.35, 0.45);
    }
  }
}

// ---------------------------------------------------------------- drums: soft kick, closed hat, light snare
let seed = 1; const noise = () => ((seed = (seed * 1664525 + 1013904223) >>> 0) / 4294967296) * 2 - 1;
for (let bt = 0; bt < TOTAL; bt += BEAT / 2) {
  const g = groove(bt), beatIdx = Math.round(bt / (BEAT / 2)) % 8;
  if (g === 0) continue;
  // A two-bar breath when the Rosters section starts (no kick or snare).
  const breath = Math.abs(bt - (cues.chapters[8] - BAR)) < BAR;
  if ((beatIdx === 0 || beatIdx === 4) && !breath) {
    for (let i = Math.floor(bt * SR), e = Math.floor((bt + 0.35) * SR); i < e; i++) {
      const lt = i / SR - bt, f = 48 + 70 * Math.exp(-lt * 28);
      put(i, Math.sin(2 * Math.PI * f * lt) * Math.exp(-lt * 9) * 0.16 * g, 0, 0);
    }
  }
  if ((beatIdx === 2 || beatIdx === 6) && !breath) {
    let z = 0;
    for (let i = Math.floor(bt * SR), e = Math.floor((bt + 0.18) * SR); i < e; i++) {
      const lt = i / SR - bt, x = noise(); z += 0.35 * (x - z);
      put(i, (x - z) * Math.exp(-lt * 26) * 0.035 * g + Math.sin(2 * Math.PI * 190 * lt) * Math.exp(-lt * 30) * 0.03 * g, 0.05, 0.25);
    }
  }
  if (beatIdx % 2 === 1) {
    let z = 0;
    for (let i = Math.floor(bt * SR), e = Math.floor((bt + 0.05) * SR); i < e; i++) {
      const lt = i / SR - bt, x = noise(); z += 0.6 * (x - z);
      put(i, (x - z) * Math.exp(-lt * 90) * 0.022 * g, 0.25, 0.1);
    }
  }
}

// ---------------------------------------------------------------- sfx: clicks (A5 tick), typing ticks, chapter swell + chime
function tick(t0, gain, pitch = 880) {
  let z = 0;
  for (let i = Math.floor(t0 * SR), e = Math.floor((t0 + 0.06) * SR); i < e; i++) {
    const lt = i / SR - t0, x = noise(); z += 0.5 * (x - z);
    put(i, ((x - z) * Math.exp(-lt * 160) * 0.5 + Math.sin(2 * Math.PI * pitch * lt) * Math.exp(-lt * 60)) * gain, 0.1, 0.2);
  }
}
for (const t of cues.clicks) tick(t, 0.05);
// Typing on the sign-in screen: nine soft keys over one second.
for (let k = 0; k < 9; k++) tick(cues.chapters[0] + 1.3 + k * 0.111 + (k % 3) * 0.012, 0.018, 1320);

const chapterStarts = [...cues.chapters, TOTAL - 6];
for (const c of chapterStarts) {
  // Airy swell rising into the change.
  let z = 0;
  for (let i = Math.floor((c - 0.45) * SR), e = Math.floor((c + 0.35) * SR); i < e; i++) {
    const t = i / SR, p = (t - (c - 0.45)) / 0.8, x = noise();
    const a = 1 - Math.exp(-2 * Math.PI * (300 + 2600 * Math.sin(Math.PI * Math.min(p, 1) * 0.5)) / SR);
    z += a * (x - z);
    const env = Math.sin(Math.PI * Math.min(1, p)) ** 2;
    put(i, z * env * 0.03, Math.sin(p * 3) * 0.4, 0.6);
  }
  // Two-note bell in key (D6 then A6).
  [[1174.66, 0], [1760, 0.09]].forEach(([f, d]) => {
    const t0 = c + d;
    for (let i = Math.floor(t0 * SR), e = Math.min(N, Math.floor((t0 + 1.6) * SR)); i < e; i++) {
      const lt = i / SR - t0;
      const v = Math.sin(2 * Math.PI * f * lt) * Math.exp(-lt * 3.2) + 0.3 * Math.sin(2 * Math.PI * f * 2.76 * lt) * Math.exp(-lt * 9);
      put(i, v * 0.02 * Math.min(1, lt / 0.004), d ? 0.3 : -0.3, 0.7);
    }
  });
}

// ---------------------------------------------------------------- reverb (Schroeder: 4 combs + 2 allpasses per side)
function reverb(input, combs, aps) {
  const out = new Float32Array(N);
  for (const d of combs) {
    const buf = new Float32Array(d); let idx = 0, lp = 0;
    for (let i = 0; i < N; i++) {
      const y = buf[idx]; lp = y * 0.6 + lp * 0.4;
      buf[idx] = input[i] + lp * 0.8; idx = (idx + 1) % d; out[i] += y * 0.25;
    }
  }
  for (const d of aps) {
    const buf = new Float32Array(d); let idx = 0;
    for (let i = 0; i < N; i++) {
      const b = buf[idx], x = out[i]; const y = -x + b; buf[idx] = x + b * 0.5; idx = (idx + 1) % d; out[i] = y;
    }
  }
  return out;
}
const wetL = reverb(sendL, [1557, 1617, 1491, 1422].map(d => d * 2), [225, 556].map(d => d * 2));
const wetR = reverb(sendR, [1580, 1640, 1514, 1445].map(d => d * 2), [248, 579].map(d => d * 2));

// ---------------------------------------------------------------- master: sum, gentle high-pass, soft clip, normalize, fades
let peak = 0, hpL = 0, hpR = 0, pxL = 0, pxR = 0;
const hpA = Math.exp(-2 * Math.PI * 30 / SR);
for (let i = 0; i < N; i++) {
  let l = L[i] + wetL[i] * 0.35, r = R[i] + wetR[i] * 0.35;
  hpL = hpA * (hpL + l - pxL); pxL = l; hpR = hpA * (hpR + r - pxR); pxR = r;
  const t = i / SR, fade = Math.min(1, t / 0.8) * Math.min(1, (TOTAL - t) / 2.5);
  L[i] = Math.tanh(hpL * 1.6) * fade; R[i] = Math.tanh(hpR * 1.6) * fade;
  peak = Math.max(peak, Math.abs(L[i]), Math.abs(R[i]));
}
const gain = 0.89 / peak;
const wav = Buffer.alloc(44 + N * 4);
wav.write('RIFF', 0); wav.writeUInt32LE(36 + N * 4, 4); wav.write('WAVE', 8); wav.write('fmt ', 12);
wav.writeUInt32LE(16, 16); wav.writeUInt16LE(1, 20); wav.writeUInt16LE(2, 22); wav.writeUInt32LE(SR, 24);
wav.writeUInt32LE(SR * 4, 28); wav.writeUInt16LE(4, 32); wav.writeUInt16LE(16, 34); wav.write('data', 36); wav.writeUInt32LE(N * 4, 40);
for (let i = 0; i < N; i++) {
  wav.writeInt16LE(Math.round(Math.max(-1, Math.min(1, L[i] * gain)) * 32767), 44 + i * 4);
  wav.writeInt16LE(Math.round(Math.max(-1, Math.min(1, R[i] * gain)) * 32767), 46 + i * 4);
}
fs.writeFileSync('audio.wav', wav);
console.log('audio.wav', TOTAL, 's, peak before normalize', peak.toFixed(3));
