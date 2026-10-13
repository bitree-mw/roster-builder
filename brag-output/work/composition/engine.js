// Render engine: compiles window.TL + window.SHOTS into absolute-time tracks, then window.render(t) sets every
// element's state as a pure function of t (seconds) so frames can be captured in any order.
(function () {
  const TL = window.TL, SHOTS = window.SHOTS;
  const K = 1328 / 1600, VW = 1328, VH = 747, WIN_X = 548, WIN_Y = 118 + 38;
  const clamp = (v, a = 0, b = 1) => Math.max(a, Math.min(b, v));
  const lerp = (a, b, p) => a + (b - a) * p;
  const easeOut = p => 1 - Math.pow(1 - clamp(p), 3);
  const easeInOut = p => { p = clamp(p); return p < .5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2; };
  const $ = id => document.getElementById(id);

  // ------------------------------------------------------------ compile tracks
  const howto = TL.chapters.filter(c => c.ev);
  const shots = [], cursor = [], hl = [], typing = [];
  for (const c of howto) {
    c.num = howto.indexOf(c) + 1;
    c.end = c.start + c.dur;
    c.stepTimes = []; c.cams = [];
    let shot = null;
    const box = key => {
      if (key == null) return null;
      if (typeof key === 'object') return key;
      const b = SHOTS[shot] && SHOTS[shot].boxes[key];
      if (!b) throw new Error(`no box ${key} in ${shot}`);
      return b;
    };
    for (const e of c.ev) {
      const t = c.start + e.at;
      if (e.shot) { shot = e.shot; shots.push({ t, name: shot, first: e.at === 0, chapter: c }); }
      if (e.step != null) c.stepTimes[e.step] = t;
      if (e.cam) c.cams.push({ t, ...camFor(e.cam.f ? box(e.cam.f) : null, e.cam.z) });
      if (e.cur) cursor.push({ t, r: box(e.cur), click: !!e.click });
      if ('hl' in e) hl.push({ t, r: box(e.hl) });
      if (e.type) typing.push({ t, r: box(e.type.box), text: e.type.text, dur: e.type.dur });
    }
    hl.push({ t: c.end - 0.3, r: null });
  }
  for (const ty of typing) ty.until = (shots.find(s => s.t > ty.t) || { t: Infinity }).t;
  const intro = TL.chapters[0], outro = TL.chapters[TL.chapters.length - 1];

  /** Camera for a focus rect and zoom: translate so the rect centre sits mid-view, clamped to the page edges. */
  function camFor(r, z) {
    const s = K * z;
    const fx = r ? r.x + r.w / 2 : 800, fy = r ? r.y + r.h / 2 : 450;
    return { s, tx: clamp(VW / 2 - fx * s, VW - 1600 * s, 0), ty: clamp(VH / 2 - fy * s, VH - 900 * s, 0) };
  }
  const chapterAt = t => howto.find(c => t >= c.start && t < c.end) || null;

  /** Camera at t: the latest keyframe of the chapter, eased in from the previous one over 0.9 s. */
  function camAt(t) {
    const c = chapterAt(t) || (t < howto[0].start ? howto[0] : howto[howto.length - 1]);
    const ks = c.cams;
    let i = 0; while (i + 1 < ks.length && ks[i + 1].t <= t) i++;
    const k = ks[i];
    if (i === 0 || t - k.t >= 0.9) return k;
    const p = easeInOut((t - k.t) / 0.9), a = ks[i - 1];
    return { s: lerp(a.s, k.s, p), tx: lerp(a.tx, k.tx, p), ty: lerp(a.ty, k.ty, p) };
  }
  /** App px (1600x900 viewport) to stage px under the camera. */
  const toStage = (cam, x, y) => [WIN_X + cam.tx + x * cam.s, WIN_Y + cam.ty + y * cam.s];
  const centre = r => [r.x + r.w / 2, r.y + r.h / 2];

  // ------------------------------------------------------------ static DOM
  $('intro-h').replaceChildren(...'Crew rosters, flight patterns and fleet readiness in one console.'.split(' ').map(w => Object.assign(document.createElement('span'), { textContent: w })));
  $('outro-h').replaceChildren(...'The generator fills every seat with legal crew, balancing each person’s working hours.'.split(' ').map(w => Object.assign(document.createElement('span'), { textContent: w })));
  const secs = TL.sections.map(s => {
    const cs = s.ids.map(id => howto.find(c => c.id === id));
    return { label: s.label, start: cs[0].start, end: cs[cs.length - 1].end };
  });
  $('progress').style.gridTemplateColumns = secs.map(s => `${s.end - s.start}fr`).join(' ');
  $('progress').replaceChildren(...secs.map(s => {
    const seg = document.createElement('div'); seg.className = 'seg';
    seg.innerHTML = '<div class="bar"><div class="fill"></div></div><div class="lab"></div>';
    seg.querySelector('.lab').textContent = s.label; s.el = seg; return seg;
  }));

  let railFor = null;
  function buildRail(c) {
    railFor = c;
    $('chap-num').textContent = `${String(c.num).padStart(2, '0')} / ${howto.length}`;
    $('chap-sec').textContent = c.eyebrow;
    $('title').textContent = c.title;
    $('steps').replaceChildren(...c.steps.map((s, i) => {
      const li = document.createElement('li');
      li.innerHTML = '<span class="n"></span><span class="t"></span>';
      li.querySelector('.n').textContent = i + 1; li.querySelector('.t').textContent = s;
      return li;
    }));
    const path = c.path;
    $('url').innerHTML = '<b></b><span></span>';
    $('url').querySelector('b').textContent = 'roster-builder.test';
    $('url').querySelector('span').textContent = path;
  }

  // Preload every screenshot so frames never wait on decoding.
  const imgs = {};
  window.ready = Promise.all([...new Set(shots.map(s => s.name))].map(n => {
    const im = new Image(); im.src = `../shots/${SHOTS[n].file}`; imgs[n] = im.src; return im.decode();
  }).concat([document.fonts.ready]));

  const set = (el, o, tr) => { el.style.opacity = o; if (tr !== undefined) el.style.transform = tr; };

  // ------------------------------------------------------------ render(t)
  window.render = function (t) {
    // Intro.
    const it = t - intro.start, introOut = 1 - easeOut((it - (intro.dur - 0.5)) / 0.5);
    $('intro').style.display = t < intro.dur + 0.05 ? 'flex' : 'none';
    if (t < intro.dur + 0.05) {
      set($('intro'), introOut, `translateY(${-30 * (1 - introOut)}px)`);
      const lp = easeOut((it - 0.1) / 0.6);
      set(document.querySelector('#intro .logo-chip'), lp, `scale(${0.9 + 0.1 * lp})`);
      const kp = easeOut((it - 0.35) / 0.5); set(document.querySelector('#intro .kicker'), kp, `translateX(${-20 * (1 - kp)}px)`);
      [...$('intro-h').children].forEach((w, i) => { const p = easeOut((it - 0.6 - i * 0.055) / 0.45); set(w, p, `translateY(${26 * (1 - p)}px)`); });
      const sp = easeOut((it - 1.5) / 0.5); set($('intro-sub'), sp, `translateY(${14 * (1 - sp)}px)`);
      const stp = easeOut((it - 0.3) / 0.6); set($('intro-strip'), stp, `translateY(${30 * (1 - stp)}px)`);
      $('plane').style.left = `calc(${(easeInOut((it - 0.6) / 5.0) * 100).toFixed(3)}% - 20px)`;
    }

    // Outro.
    const ot = t - outro.start;
    $('outro').style.display = ot >= 0 ? 'flex' : 'none';
    if (ot >= 0) {
      const lp = easeOut((ot - 0.15) / 0.6);
      set(document.querySelector('#outro .logo-chip'), lp, `scale(${0.9 + 0.1 * lp})`);
      const kp = easeOut((ot - 0.35) / 0.5); set(document.querySelector('#outro .kicker'), kp, `translateX(${-20 * (1 - kp)}px)`);
      [...$('outro-h').children].forEach((w, i) => { const p = easeOut((ot - 0.55 - i * 0.05) / 0.45); set(w, p, `translateY(${24 * (1 - p)}px)`); });
      const fp = easeOut((ot - 1.7) / 0.6); set(document.querySelector('#outro .foot'), fp);
    }

    // Workspace (window, rail, progress) visibility between intro and outro.
    const wsIn = easeOut((t - intro.dur) / 0.6), wsOut = 1 - easeOut((t - (outro.start - 0.45)) / 0.45);
    const ws = Math.min(wsIn, wsOut);
    set($('win'), ws, `scale(${0.96 + 0.04 * ws})`);
    set($('progress'), ws); set($('counter'), ws);
    const c = chapterAt(t);
    $('rail').style.display = c ? 'block' : 'none';
    if (!c) { for (const id of ['hl', 'ripple', 'cursor']) $(id).style.opacity = 0; return; }

    // Step rail.
    if (railFor !== c) buildRail(c);
    const ct = t - c.start;
    const railOut = 1 - easeOut((t - (c.end - 0.35)) / 0.35);
    set($('rail'), railOut, `translateX(${-24 * (1 - railOut)}px)`);
    const ep = easeOut((ct - 0.05) / 0.45); set($('eyebrow'), ep, `translateY(${12 * (1 - ep)}px)`);
    const tp = easeOut((ct - 0.12) / 0.5); set($('title'), tp, `translateY(${18 * (1 - tp)}px)`);
    let active = -1; c.stepTimes.forEach((st, i) => { if (t >= st) active = i; });
    [...$('steps').children].forEach((li, i) => {
      const st = c.stepTimes[i];
      const p = st === undefined ? 0 : easeOut((t - Math.max(st, c.start + 0.2)) / 0.45);
      set(li, p, `translateY(${14 * (1 - p)}px)`);
      li.className = i === active ? 'active' : i < active ? 'done' : '';
      li.querySelector('.n').textContent = i < active ? '✓' : i + 1;
    });
    $('counter').innerHTML = `Chapter <b>${String(c.num).padStart(2, '0')}</b> of ${howto.length}`;
    for (const s of secs) {
      s.el.querySelector('.fill').style.width = `${(clamp((t - s.start) / (s.end - s.start)) * 100).toFixed(3)}%`;
      s.el.classList.toggle('on', t >= s.start && t < s.end);
    }

    // Screens: current shot with a short crossfade from the previous one; chapters dip through the canvas.
    let si = 0; while (si + 1 < shots.length && shots[si + 1].t <= t) si++;
    const cur = shots[si], prev = shots[si - 1];
    const cam = camAt(t);
    $('cam').style.transform = `translate(${cam.tx}px, ${cam.ty}px) scale(${cam.s})`;
    if ($('imgB').dataset.n !== cur.name) { $('imgB').src = imgs[cur.name]; $('imgB').dataset.n = cur.name; }
    const fade = cur.first ? 1 : easeInOut((t - cur.t) / 0.2);
    if (prev && !cur.first && fade < 1) {
      if ($('imgA').dataset.n !== prev.name) { $('imgA').src = imgs[prev.name]; $('imgA').dataset.n = prev.name; }
      $('imgA').style.opacity = 1;
    } else $('imgA').style.opacity = 0;
    $('imgB').style.opacity = fade;
    const dipIn = easeOut((t - c.start) / 0.3), dipOut = 1 - easeInOut((t - (c.end - 0.25)) / 0.25);
    $('view').style.opacity = c.num === 1 ? dipOut : Math.min(dipIn, dipOut);
    $('view').style.opacity = c.num === howto.length ? Math.min(dipIn, 1) : $('view').style.opacity;

    // Typing overlay (inside the camera, in app px).
    const ty = typing.find(x => t >= x.t && t < x.until);
    $('typer').style.display = ty ? 'flex' : 'none';
    if (ty) {
      Object.assign($('typer').style, { left: `${ty.r.x + 38}px`, top: `${ty.r.y + 3}px`, width: `${ty.r.w - 41}px`, height: `${ty.r.h - 6}px`, paddingLeft: '6px' });
      const n = Math.floor(clamp((t - ty.t) / ty.dur) * ty.text.length);
      $('typer').textContent = ty.text.slice(0, n);
      const caret = document.createElement('span'); caret.className = 'caret';
      caret.style.opacity = Math.floor((t - ty.t) * 2.6) % 2 === 0 ? 1 : 0;
      $('typer').append(caret);
    }

    // Highlight ring.
    let hi = -1; for (let i = 0; i < hl.length; i++) if (hl[i].t <= t) hi = i;
    const h = hl[hi], hn = hl[hi + 1];
    if (h && h.r && chapterAt(h.t) === c) {
      let o = easeOut((t - h.t) / 0.25);
      if (hn && hn.t - t < 0.15) o *= clamp((hn.t - t) / 0.15);
      // Keep the ring inside the window even when the camera crops the target.
      const pad = 6;
      let [x1, y1] = toStage(cam, h.r.x, h.r.y), [x2, y2] = toStage(cam, h.r.x + h.r.w, h.r.y + h.r.h);
      x1 = Math.max(x1, WIN_X + pad + 4); y1 = Math.max(y1, WIN_Y + pad + 4); x2 = Math.min(x2, WIN_X + VW - pad - 4); y2 = Math.min(y2, WIN_Y + VH - pad - 4);
      Object.assign($('hl').style, { left: `${x1 - pad}px`, top: `${y1 - pad}px`, width: `${x2 - x1 + 2 * pad}px`, height: `${y2 - y1 + 2 * pad}px` });
      $('hl').style.opacity = o * ws;
    } else $('hl').style.opacity = 0;

    // Cursor: glides to each target, arriving at the event time; clicks press and ripple.
    let ci = 0; while (ci + 1 < cursor.length && cursor[ci + 1].t - 0.7 <= t) ci++;
    const b = cursor[ci], a = cursor[ci - 1];
    const travel = a ? Math.min(0.7, b.t - a.t - 0.05) : 0.7;
    const mp = easeInOut((t - (b.t - travel)) / travel);
    const [bx, by] = toStage(cam, ...centre(b.r));
    const [ax, ay] = a ? toStage(cam, ...centre(a.r)) : [WIN_X + VW / 2, WIN_Y + VH * 0.75];
    // The cursor never leaves the window, even when a zoom pushes its last target out of view.
    const cx = clamp(lerp(ax, bx, mp), WIN_X + 12, WIN_X + VW - 40), cy = clamp(lerp(ay, by, mp), WIN_Y + 12, WIN_Y + VH - 46);
    let press = 1;
    for (const e of cursor) if (e.click && t >= e.t - 0.06 && t <= e.t + 0.14) press = 0.82;
    Object.assign($('cursor').style, { left: `${cx - 3}px`, top: `${cy - 3}px`, transform: `scale(${press})` });
    $('cursor').style.opacity = Math.min(ws, easeOut((t - (cursor[0].t - 1.2)) / 0.4));
    const click = [...cursor].reverse().find(e => e.click && t >= e.t && t < e.t + 0.5);
    if (click) {
      const [rx, ry] = toStage(cam, ...centre(click.r)), p = (t - click.t) / 0.5, size = 14 + 60 * easeOut(p);
      Object.assign($('ripple').style, { left: `${rx - size / 2}px`, top: `${ry - size / 2}px`, width: `${size}px`, height: `${size}px` });
      $('ripple').style.opacity = 0.9 * (1 - p);
    } else $('ripple').style.opacity = 0;
  };

  // Times of clicks and chapter starts, used by the audio script and the preview tooling.
  window.cues = { clicks: cursor.filter(e => e.click).map(e => e.t), chapters: howto.map(c => c.start), total: TL.total };
})();
