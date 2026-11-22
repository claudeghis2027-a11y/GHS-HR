'use strict';
/* UI strings come from the page (<script type="application/json" id="i18n">), already in the user's language. */
let I18N = {};
const T = (s, ...a) => { let t = I18N[s] || s; a.forEach(v => { t = t.replace('%s', v); }); return t; };

document.addEventListener('DOMContentLoaded', () => {
  try { I18N = JSON.parse(document.getElementById('i18n')?.textContent || '{}'); } catch (e) { I18N = {}; }
  // Confirmation for destructive forms
  document.querySelectorAll('form[data-confirm]').forEach(f => {
    f.addEventListener('submit', ev => { if (!confirm(f.dataset.confirm)) ev.preventDefault(); });
  });
  document.querySelectorAll('[data-check-all]').forEach(b => b.addEventListener('change', () => {
    b.form.querySelectorAll('input[type=checkbox][name="' + b.dataset.checkAll + '"]').forEach(c => { c.checked = b.checked; });
  }));
  // Eye button on every password field: shows / hides what was typed
  document.querySelectorAll('input[type=password]').forEach(inp => {
    const wrap = document.createElement('span'); wrap.className = 'pw-wrap';
    inp.parentNode.insertBefore(wrap, inp); wrap.appendChild(inp);
    const b = document.createElement('button'); b.type = 'button'; b.className = 'pw-eye'; b.textContent = '👁';
    b.setAttribute('aria-label', T('إظهار كلمة المرور')); b.title = T('إظهار كلمة المرور');
    b.addEventListener('click', () => {
      const show = inp.type === 'password'; inp.type = show ? 'text' : 'password';
      b.textContent = show ? '🙈' : '👁'; b.title = T(show ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'); b.setAttribute('aria-label', b.title);
      inp.focus();
    });
    wrap.appendChild(b);
  });
  // A form field that only applies for one value of another (e.g. the bank only for card pay)
  document.querySelectorAll('[data-show-if]').forEach(box => {
    const src = box.closest('form')?.querySelector('[name="' + box.dataset.showIf + '"]');
    if (!src) return;
    const sync = () => { box.style.display = src.value === box.dataset.showVal ? '' : 'none'; };
    src.addEventListener('change', sync); sync();
  });
  document.querySelectorAll('[data-autosubmit]').forEach(el => el.addEventListener('change', () => el.form.submit()));
  // Typing a from/to date means a custom range: let go of the picked payroll period
  document.querySelectorAll('form select[name=period]').forEach(sel => sel.form.querySelectorAll('input[name=from], input[name=to]')
    .forEach(inp => inp.addEventListener('change', () => { sel.value = ''; })));
  const nav = document.getElementById('side');
  document.querySelectorAll('[data-toggle-nav]').forEach(b => b.addEventListener('click', () => nav && nav.classList.toggle('open')));
  // Keep the side menu where it was after following a link (it scrolls on its own); else bring the open item into view
  if (nav) {
    let saved = null;
    try { saved = sessionStorage.getItem('hr.navScroll'); } catch (e) { /* storage blocked */ }
    if (saved !== null) nav.scrollTop = +saved;
    const on = nav.querySelector('a.on');
    if (on) { const n = nav.getBoundingClientRect(), r = on.getBoundingClientRect(); if (r.top < n.top || r.bottom > n.bottom) nav.scrollTop += r.top - n.top - n.height / 3; }
    window.addEventListener('pagehide', () => { try { sessionStorage.setItem('hr.navScroll', String(nav.scrollTop)); } catch (e) { /* storage blocked */ } });
  }

  const clock = document.querySelector('[data-clock]');
  if (clock) setInterval(() => { const d = new Date(); clock.textContent = d.toTimeString().slice(0, 5); }, 10000);

  const form = document.getElementById('checkin-form');
  if (form) initCheckin(form);
  document.querySelectorAll('form[data-face-capture]').forEach(initEnroll);
  document.querySelectorAll('[data-geo-fill]').forEach(initGeoFill);
  document.querySelectorAll('form[data-geo-gate]').forEach(initGeoGate);
  document.querySelectorAll('[data-late-helper]').forEach(initLateHelper);
  document.querySelectorAll('[data-print]').forEach(b => b.addEventListener('click', () => window.print()));
  // Printing (button or Ctrl+P): the letterhead lists the filters the page was opened with, and wide tables go landscape
  const chips = document.querySelector('.ph-wrap [data-print-chips]');
  window.addEventListener('beforeprint', () => {
    if (chips) {
      chips.textContent = '';
      document.querySelectorAll('main form[method=get] select, main form[method=get] input:not([type=hidden]):not([type=checkbox]):not([type=submit])').forEach(c => {
        const l = c.closest('label');
        const v = c.tagName === 'SELECT' ? (c.value !== '' && c.selectedOptions[0] ? c.selectedOptions[0].textContent.trim() : '') : c.value.trim();
        if (!v) return;
        const name = l ? Array.from(l.childNodes).filter(n => n.nodeType === 3).map(n => n.textContent.trim()).join(' ').trim() : '';
        const s = document.createElement('span'); s.textContent = name ? name + ': ' + v : v; chips.appendChild(s);
      });
    }
    const cols = Math.max(0, ...Array.from(document.querySelectorAll('main table')).map(t => (t.tHead && t.tHead.rows[0] ? t.tHead.rows[0].cells.length : 0)));
    document.body.classList.toggle('print-wide', cols > 9 && cols <= 16);
    document.body.classList.toggle('print-xwide', cols > 16);
  });
  window.addEventListener('afterprint', () => document.body.classList.remove('print-wide', 'print-xwide'));
  document.querySelectorAll('tr[data-href]').forEach(tr => tr.addEventListener('click', ev => {
    if (ev.target.closest('a, button, form, input, select, label') || String(window.getSelection())) return;
    window.location.href = tr.dataset.href;
  }));
  // WhatsApp: the user marks a message as sent after sending it from their own WhatsApp (the server sends nothing).
  // The message can be edited before sending: the wa.me link and the copy button take the edited text.
  document.querySelectorAll('[data-wa-text]').forEach(ta => {
    const card = ta.closest('.card'), link = card.querySelector('[data-wa-open]'), copy = card.querySelector('[data-wa-copy]');
    if (link) ta.addEventListener('input', () => { link.href = 'https://wa.me/' + link.dataset.phone + '?text=' + encodeURIComponent(ta.value); });
    if (copy) copy.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(ta.value); } catch (e) { ta.select(); document.execCommand('copy'); }
      copy.textContent = copy.dataset.done;
    });
  });
  document.querySelectorAll('[data-wa-log]').forEach(b => b.addEventListener('click', async () => {
    const body = new URLSearchParams({ emp: b.dataset.emp, type: b.dataset.type, period: b.dataset.period, _csrf: b.dataset.csrf });
    try {
      const r = await fetch(b.dataset.url, { method: 'POST', body, credentials: 'same-origin' });
      if (r.ok) { b.disabled = true; b.textContent = '✓'; }
    } catch (e) { /* stays enabled to retry */ }
  }));
});

/** Late waiver form: fetch the day's late minutes from the punches and fill what is not yet waived (max one day). */
function initLateHelper(box) {
  const form = box.closest('form'), out = box.querySelector('[data-late-out]');
  box.querySelector('[data-late-btn]').addEventListener('click', async () => {
    const emp = form.elements.employee_id?.value, d = form.elements.ex_date?.value;
    const url = new URL(box.dataset.url, location.href);
    url.searchParams.set('emp', emp || ''); url.searchParams.set('d', d || '');
    try {
      const r = await (await fetch(url, { credentials: 'same-origin' })).json();
      out.textContent = r.msg || '';
      const m = form.elements.minutes;
      if (r.ok && m && !m.value && r.remaining > 0) m.value = Math.min(1440, r.remaining);
    } catch (e) { out.textContent = '⚠'; }
  });
}

/**
 * Live camera capture with a head-turn prompt. Only getUserMedia is used (no file picker), frames are
 * kept in memory and posted once; the server checks real movement between them.
 */
const FACE_STEPS = [  // hints are translation keys; shown as a banner on top of the live video
  { at: 0,    hint: 'بص للكاميرا على طول' },
  { at: 1200, snap: true },
  { at: 1700, hint: 'لف راسك يمين' },
  { at: 2600, snap: true }, { at: 3000, snap: true },
  { at: 3500, hint: 'لف راسك شمال' },
  { at: 4400, snap: true }, { at: 4800, snap: true },
  { at: 5300, hint: 'بص قدام تاني' },
  { at: 6000, snap: true },
];

async function captureFace(box, status) {
  const video = box.querySelector('video');
  const hint = box.querySelector('[data-hint]');
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) throw new Error(T('المتصفح ده مش بيدعم الكاميرا. استخدم Chrome أو Safari حديث.'));
  let stream;
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }, audio: false });
  } catch (e) {
    throw new Error(T('مقدرناش نفتح الكاميرا. اسمح للمتصفح بالكاميرا وجرّب تاني.'));
  }
  video.srcObject = stream;
  box.hidden = false;
  video.hidden = false;
  box.scrollIntoView({ behavior: 'smooth', block: 'center' });  // the prompts must be on screen
  await video.play();
  const canvas = document.createElement('canvas');
  const frames = [];
  const snap = () => {
    const s = Math.min(1, 640 / Math.max(video.videoWidth, video.videoHeight));
    canvas.width = Math.round(video.videoWidth * s); canvas.height = Math.round(video.videoHeight * s);
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    frames.push(canvas.toDataURL('image/jpeg', 0.85));
  };
  const t0 = performance.now();
  try {
    for (const step of FACE_STEPS) {
      const wait = step.at - (performance.now() - t0);
      if (wait > 0) await new Promise(r => setTimeout(r, wait));
      if (step.hint) { hint.textContent = T(step.hint); status.textContent = T(step.hint); }
      if (step.snap) snap();
    }
  } finally {
    stream.getTracks().forEach(t => t.stop());
    video.srcObject = null;
    hint.textContent = '';
  }
  return frames;
}

function initEnroll(form) {
  const status = form.querySelector('[data-status]');
  const btn = form.querySelector('[data-capture]');
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    try {
      const frames = await captureFace(form.querySelector('.camera'), status);
      form.elements.frames.value = JSON.stringify(frames);
      status.textContent = T('بنفحص الصورة…');
      form.submit();
    } catch (e) {
      status.textContent = e.message;
      btn.disabled = false;
    }
  });
}

function initCheckin(form) {
  const status = form.querySelector('[data-status]');
  const needPhoto = form.dataset.requirePhoto === '1';
  const box = form.querySelector('.camera');
  const video = form.querySelector('video');
  const canvas = form.querySelector('canvas');
  const img = form.querySelector('img');
  let stream = null;

  async function openCamera() {
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: 640 }, audio: false });
      video.srcObject = stream;
      await video.play();
      box.hidden = false;
      return true;
    } catch (e) {
      status.textContent = T('مقدرناش نفتح الكاميرا. اسمح للمتصفح بالكاميرا وجرّب تاني.');
      return false;
    }
  }
  function snap() {
    const w = 480, h = Math.round((video.videoHeight / video.videoWidth) * w) || 360;
    canvas.width = w; canvas.height = h;
    canvas.getContext('2d').drawImage(video, 0, 0, w, h);
    const data = canvas.toDataURL('image/jpeg', 0.7);
    img.src = data; img.hidden = false; video.hidden = true;
    if (stream) stream.getTracks().forEach(t => t.stop());
    return data;
  }
  const camBtn = form.querySelector('[data-camera]');
  if (camBtn) camBtn.addEventListener('click', openCamera);

  // Old app: 30 seconds between check-in attempts (the server enforces it too)
  const punches = form.querySelectorAll('[data-punch]');
  let wait = +form.dataset.wait || 0;
  if (wait > 0) {
    punches.forEach(b => b.disabled = true);
    const tick = () => {
      if (wait <= 0) { punches.forEach(b => b.disabled = false); status.textContent = ''; return; }
      status.textContent = T('استنى %s ثانية قبل المحاولة الجاية.', wait);
      wait--; setTimeout(tick, 1000);
    };
    tick();
  }

  const needFace = form.dataset.face === '1';
  form.querySelectorAll('[data-punch]').forEach(btn => btn.addEventListener('click', async () => {
    if (!navigator.geolocation) { status.textContent = T('المتصفح ده مش بيدعم تحديد الموقع.'); return; }
    form.querySelectorAll('[data-punch]').forEach(b => b.disabled = true);
    if (needFace) {
      try {
        form.elements.frames.value = JSON.stringify(await captureFace(box, status));
      } catch (e) {
        status.textContent = e.message;
        form.querySelectorAll('[data-punch]').forEach(b => b.disabled = false);
        return;
      }
    } else if (needPhoto) {
      if (!stream && !(await openCamera())) { form.querySelectorAll('[data-punch]').forEach(b => b.disabled = false); return; }
      form.elements.photo.value = snap();
    }
    status.textContent = T('بنحدد موقعك…');
    navigator.geolocation.getCurrentPosition(pos => {
      form.elements.lat.value = pos.coords.latitude;
      form.elements.lng.value = pos.coords.longitude;
      form.elements.acc.value = pos.coords.accuracy;
      form.elements.action.value = btn.dataset.punch;
      status.textContent = T('بنسجل…');
      sendPunch(form, status);
    }, err => {
      status.textContent = T('مقدرناش نحدد موقعك (%s). فعّل الـ GPS واسمح بالموقع وجرّب تاني.', err.message);
      form.querySelectorAll('[data-punch]').forEach(b => b.disabled = false);
    }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
  }));
}

/**
 * Sends a check-in with fetch so a congested network gets retried (old app: auto retry) instead of a browser
 * error page. Every try carries the same attempt id; the server answers a repeat of an attempt it already
 * handled by just showing the result, so a lost reply never records twice.
 */
async function sendPunch(form, status) {
  form.elements.attempt.value = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random()).replace(/[^A-Za-z0-9-]/g, '').slice(0, 40);
  const body = new FormData(form);
  const delays = [0, 2000, 4000, 8000];
  for (let i = 0; i < delays.length; i++) {
    if (delays[i]) {
      status.textContent = T('الشبكة زحمة، بنحاول تاني (%s من %s)…', i, delays.length - 1);
      await new Promise(r => setTimeout(r, delays[i]));
    }
    let res;
    // getAttribute: the form has a field named "action", which hides form.action
    try { res = await fetch(form.getAttribute('action'), { method: 'POST', body, redirect: 'manual', credentials: 'same-origin' }); } catch (e) { continue; }
    if (res.type === 'opaqueredirect' || (res.status >= 300 && res.status < 400)) { window.location.href = form.dataset.done; return; }
    if ([502, 503, 504].includes(res.status)) continue;
    // an error page instead of the usual redirect: show its message, then reload the check-in page
    const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
    const msg = doc.querySelector('.flash.err, main p, main h1');
    status.textContent = msg ? msg.textContent.trim() : T('الشبكة مش متاحة دلوقتي. استنى شوية وجرّب تاني.');
    window.setTimeout(() => { window.location.href = form.dataset.done; }, 4000);
    return;
  }
  status.textContent = T('الشبكة مش متاحة دلوقتي. استنى شوية وجرّب تاني.');
  window.setTimeout(() => { window.location.href = form.dataset.done; }, 4000);
}

/** Location gate for an employee's own data: read the GPS and send it; on failure offer a retry. */
function initGeoGate(form) {
  const status = form.querySelector('[data-status]');
  const retry = form.querySelector('[data-geo-retry]');
  const go = () => {
    if (!navigator.geolocation) { status.textContent = T('المتصفح ده مش بيدعم تحديد الموقع.'); return; }
    if (retry) retry.disabled = true;
    status.textContent = T('بنحدد موقعك…');
    navigator.geolocation.getCurrentPosition(pos => {
      form.elements.lat.value = pos.coords.latitude;
      form.elements.lng.value = pos.coords.longitude;
      form.elements.acc.value = pos.coords.accuracy;
      form.submit();
    }, err => {
      status.textContent = T('مقدرناش نحدد موقعك (%s). فعّل الـ GPS واسمح بالموقع وجرّب تاني.', err.message);
      if (retry) { retry.hidden = false; retry.disabled = false; }
    }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
  };
  if (retry) retry.addEventListener('click', go);
  if (!('manual' in form.dataset)) go();
}

/**
 * Attendance-point form: fill latitude/longitude from the device GPS. Watches the position for up to
 * 10 s and keeps the most accurate fix, then offers a link to check the spot on a map.
 */
function initGeoFill(box) {
  const form = box.closest('form');
  const btn = box.querySelector('[data-geo-btn]');
  const status = box.querySelector('[data-geo-status]');
  const map = box.querySelector('[data-geo-map]');
  const lat = form.elements.lat, lng = form.elements.lng, radius = form.elements.radius_m;
  const showMap = () => {
    if (lat.value && lng.value) { map.href = 'https://www.google.com/maps?q=' + encodeURIComponent(lat.value + ',' + lng.value); map.hidden = false; }
  };
  showMap();
  [lat, lng].forEach(i => i.addEventListener('change', showMap));
  btn.addEventListener('click', () => {
    if (!navigator.geolocation) { status.textContent = T('المتصفح ده مش بيدعم تحديد الموقع.'); return; }
    btn.disabled = true;
    let best = null;
    const finish = () => {
      navigator.geolocation.clearWatch(watch);
      btn.disabled = false;
      if (!best) return;
      const acc = Math.round(best.coords.accuracy);
      lat.value = best.coords.latitude.toFixed(7);
      lng.value = best.coords.longitude.toFixed(7);
      if (radius && (+radius.value || 0) < acc + 20) radius.value = Math.min(5000, Math.max(+radius.value || 0, acc + 20));
      status.textContent = acc > 60 ? T('الدقة ضعيفة (±%s متر). اطلع مكان مفتوح أو استنى شوية وجرّب تاني، أو كبّر نصف القطر.', acc)
                                    : T('تم: الدقة ±%s متر. راجع المكان على الخريطة وبعدين احفظ.', acc);
      showMap();
    };
    const watch = navigator.geolocation.watchPosition(pos => {
      if (!best || pos.coords.accuracy < best.coords.accuracy) best = pos;
      status.textContent = T('بنحدد موقعك… الدقة الحالية ±%s متر', Math.round(best.coords.accuracy));
      if (best.coords.accuracy <= 10) finish();
    }, err => {
      navigator.geolocation.clearWatch(watch);
      btn.disabled = false;
      status.textContent = T('مقدرناش نحدد موقعك (%s). فعّل الـ GPS واسمح بالموقع وجرّب تاني.', err.message);
    }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
    setTimeout(() => { if (btn.disabled) finish(); }, 10000);
  });
}
