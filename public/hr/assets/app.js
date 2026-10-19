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
  document.querySelectorAll('[data-autosubmit]').forEach(el => el.addEventListener('change', () => el.form.submit()));
  const nav = document.getElementById('side');
  document.querySelectorAll('[data-toggle-nav]').forEach(b => b.addEventListener('click', () => nav && nav.classList.toggle('open')));

  const clock = document.querySelector('[data-clock]');
  if (clock) setInterval(() => { const d = new Date(); clock.textContent = d.toTimeString().slice(0, 5); }, 10000);

  const form = document.getElementById('checkin-form');
  if (form) initCheckin(form);
  document.querySelectorAll('form[data-face-capture]').forEach(initEnroll);
  document.querySelectorAll('[data-geo-fill]').forEach(initGeoFill);
});

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
      form.submit();
    }, err => {
      status.textContent = T('مقدرناش نحدد موقعك (%s). فعّل الـ GPS واسمح بالموقع وجرّب تاني.', err.message);
      form.querySelectorAll('[data-punch]').forEach(b => b.disabled = false);
    }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
  }));
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
