# أوامر محتاجة root — GHIS HR على greenheights.edu.eg/hr

نفّذ الخطوات بالترتيب كـ root. كل خطوة مكتوب معاها هي بتعمل إيه وإزاي ترجع فيها.
ملفات الإعداد اللي الأوامر بتنسخها موجودة في `hr-system/deploy/` (رفعتها أنا كـ claudebuild، وتقدر تراجعها قبل التنفيذ).

```bash
H=/home/tarek/web/greenheights.edu.eg/hr-system
D=greenheights.edu.eg
```

---

## 0) فحص قبل البدء (قراءة بس، مابيغيرش حاجة)
```bash
stat -c '%U:%G %a' "$H"                 # سجّل المالك الحالي عشان الرجوع
ps -o user= -C nginx | sort -u          # لازم يطلع www-data (يوزر nginx)؛ لو غير كده بلّغني
php-fpm8.3 -m | grep -Ei '^(zip|pdo_mysql|mbstring|fileinfo|SimpleXML|curl|sodium)$'   # لازم السبعة يظهروا
python3 --version                        # لازم 3.11 (Debian 12)
ls /home/tarek/conf/web/$D/             # نتأكد إن مفيش ملفات *_hr قديمة
```

## 1) صلاحيات الملفات (ACL) — اتعملت بالفعل، محتاج تتحقق بس
claudebuild هو مالك `hr-system`، فطبّقت الأوامر دي بنفسي من غير root. نفّذ سطري التحقق اللي تحت بس. الأوامر الباقية مكتوبة للرجوع ليها لو احتجت تعيدها.
**بيعمل إيه:** claudebuild يبقى المالك وليه كتابة على كل حاجة؛ tarek (يوزر PHP) يقرا الكود بس ويكتب في `storage/` بس؛ nginx (www-data) يقرا الملفات الثابتة في `public/hr/` بس؛ وأي حد تاني ملوش أي صلاحية. الصلاحيات الافتراضية (`d:`) بتتطبق على أي ملف جديد يتعمل بعد كده.
```bash
chown -R claudebuild:claudebuild "$H"
chmod -R u=rwX,g=,o= "$H"
setfacl -R -m u:claudebuild:rwX -m d:u:claudebuild:rwX "$H"
setfacl -R -m u:tarek:rX       -m d:u:tarek:rX       "$H"
setfacl -R -m u:tarek:rwX      -m d:u:tarek:rwX      "$H/storage"
setfacl    -m u:www-data:x "$H" "$H/public"
setfacl -R -m u:www-data:rX    -m d:u:www-data:rX    "$H/public/hr"
setfacl -R -m d:o::--- -m g::--- -m d:g::--- "$H"
# تحقق:
getfacl -p "$H/config/.env" "$H/storage" "$H/public/hr/assets/app.css"
su -s /bin/sh www-data -c "cat $H/config/.env"   # لازم يطلع Permission denied
```
**الرجوع:** `setfacl -R -b "$H" && chown -R <المالك-القديم من خطوة 0> "$H"`

## 2) PHP-FPM pool منفصل `gh-hr`
**بيعمل إيه:** pool خاص بالنظام بيوزر tarek، و`open_basedir` على `hr-system` و`/home/tarek/tmp` بس. الـ sessions والملفات المؤقتة والملفات المرفوعة بتتحط في `hr-system/storage/` (`sessions/` و`tmp/`)، مش في `/home/tarek/tmp`، عشان ماتتلخبطش مع الموقع التاني. مقفول فيه تنفيذ أوامر النظام (`exec`/`system`/…) و`mail` والاتصال بروابط خارجية من PHP (`allow_url_fopen=off`). رفع الملفات مسموح لملف واحد بس، وأقصى حجم 5 ميجا، عشان رفع شيت Excel. اسم الملف مش اسم الدومين، فـ Hestia مش هيمسحه لما يعيد بناء الدومين.
```bash
install -o root -g root -m 644 "$H/deploy/gh-hr.fpm.conf" /etc/php/8.3/fpm/pool.d/gh-hr.conf
php-fpm8.3 -t && systemctl reload php8.3-fpm
ls -l /run/php/php8.3-fpm-gh-hr.sock     # srw-rw---- www-data www-data
```
**الرجوع:** `rm /etc/php/8.3/fpm/pool.d/gh-hr.conf && systemctl reload php8.3-fpm`

## 3) قاعدة nginx لـ `/hr`
**بيعمل إيه:** `location ^~ /hr/` (مش regex، فقاعدة Hestia للملفات الثابتة مش هتسبقها). الـ root هو `hr-system/public` واللي جواه `hr/assets` بس، فمستحيل أي ملف PHP أو إعدادات يتقري كملف. الملفات الثابتة بـ `try_files $uri @gh_hr`، وأي حاجة تانية (أو ملف مش موجود) بتروح للـ named location `@gh_hr` اللي بيبعتها لـ `index.php` على الـ pool الجديد. HTTP بيتحول لـ HTTPS من Force SSL بتاع Hestia. باقي الموقع (`/` و`new-site`) مش بيتأثر.
```bash
install -o root -g root -m 644 "$H/deploy/nginx.ssl.conf_hr" /home/tarek/conf/web/$D/nginx.ssl.conf_hr
nginx -t && systemctl reload nginx
curl -sI https://$D/hr/ | head -5        # المتوقع: 303 لـ /hr/index.php?r=login
curl -sI https://$D/ | head -1           # الموقع الرئيسي زي ما هو
```
**الرجوع:** `rm /home/tarek/conf/web/$D/nginx.ssl.conf_hr && nginx -t && systemctl reload nginx`

---

**النسخ الاحتياطي:** قاعدة `tarek_hr` بتدخل في نسخ Hestia العادية لليوزر tarek. خلي بالك إن النسخ دي فيها بيانات مرتبات، فلازم تتحفظ في مكان آمن.

---

## 4) خدمة بصمة الوجه `gh-face` (Python)
**بيعمل إيه:** خدمة Python صغيرة بتعمل كشف الوش (YuNet) والتأكد من حركة الراس والمطابقة (SFace) على الـ CPU. بتشتغل بيوزر نظام خاص بيها اسمه `gh-face`، من غير shell ومن غير home. بتسمع على unix socket بس (`/run/gh-face/face.sock`)، وsystemd هو اللي بيعمل الـ socket بصلاحية 660 لـ `gh-face` وجروب `tarek`. فيها **`PrivateNetwork=yes`**، يعني مالهاش أي اتصال بالشبكة خالص، ومستحيل تكلم أي خدمة خارجية. الـ system مقفول عليها read-only، ومحدودة بـ 700 ميجا رام ومعالجين.
مش بتخزن أي حاجة على الديسك: اللقطات بتوصلها في الطلب وبتتمسح من الذاكرة بعد المقارنة. التوكن اللي بتستخدمه هو نفس اللي في `config/.env` بتاع السيستم.

الملفات اترفعت في `/home/claudebuild/gh-face-dist/`: كود الخدمة، والموديلات ورخصها، وملفات Python الجاهزة (wheels) عشان التسطيب يتم **من غير إنترنت**، وملفات systemd.
```bash
S=/home/claudebuild/gh-face-dist
apt-get install -y python3-venv                       # مش متسطب حاليًا (ensurepip ناقص). ده التسطيب الوحيد اللي بيحتاج إنترنت، من مرايا Debian
useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin gh-face
install -d -o root -g root -m 755 /opt/gh-face /opt/gh-face/models
install -o root -g root -m 644 "$S/face_service.py" /opt/gh-face/
install -o root -g root -m 644 "$S"/models/* /opt/gh-face/models/
python3 -m venv /opt/gh-face/venv
/opt/gh-face/venv/bin/pip install --no-index --find-links="$S/wheels" --require-hashes -r "$S/requirements.txt"
install -d -o root -g root -m 700 /etc/gh-face
( umask 077; sed -n 's/^FACE_TOKEN=/GH_FACE_TOKEN=/p' "$H/config/.env" > /etc/gh-face/env )   # نفس التوكن، من غير ما يتطبع
install -o root -g root -m 644 "$S/gh-face.socket" "$S/gh-face.service" /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now gh-face.socket
# تحقق (بيشغّل الخدمة أول طلب):
curl -s --unix-socket /run/gh-face/face.sock -H "X-Face-Token: $(sed -n 's/^GH_FACE_TOKEN=//p' /etc/gh-face/env)" http://localhost/health   # {"ok": true}
systemctl status gh-face.service --no-pager | head -5
```
**الرجوع:**
```bash
systemctl disable --now gh-face.socket gh-face.service
rm -f /etc/systemd/system/gh-face.socket /etc/systemd/system/gh-face.service && systemctl daemon-reload
rm -rf /opt/gh-face /etc/gh-face && userdel gh-face
```
لو الخدمة وقفت، السيستم مش بيقبل أي بصمة وبيظهر للموظف "خدمة التحقق من الوجه مش متاحة". لو عايز تقفل بصمة الوجه مؤقتًا، خليها `0` من الإعدادات.

## 5) Cron يومي لمسح بيانات الوجه بعد انتهاء الخدمة
**بيعمل إيه:** كل يوم الساعة 3:15 الصبح، بيمسح الصورة المرجعية والبصمة الرقمية لأي موظف تاريخ نهاية خدمته عدّى. ده غير المسح الفوري اللي بيحصل لما حد يسجل تاريخ نهاية الخدمة من الشاشة أو من ملف Excel. بيشتغل بيوزر tarek ومن غير أي إخراج.
```bash
v-add-cron-job tarek 15 3 '*' '*' '*' "php $H/bin/face_cleanup.php > /dev/null 2>&1"
v-list-cron-jobs tarek | grep face_cleanup
```
**الرجوع:** `v-delete-cron-job tarek <رقم الجوب من v-list-cron-jobs>`

> **ملحوظة على خطوة 3:** ملف nginx اتحدث، وفيه `client_body_buffer_size 8m` عشان لقطات الكاميرا تفضل في الذاكرة وnginx مايكتبهاش في ملف مؤقت. لو كنت نفذت خطوة 3 قبل كده، أعد سطر الـ `install` و`nginx -t && systemctl reload nginx`.
>
> **النسخ الاحتياطي:** صور الوجه والبصمات متشفّرة بمفتاح `FACE_KEY` اللي في `config/.env`. نسخة Hestia لـ tarek فيها الاتنين، فالأفضل تستثني `hr-system/config` من نسخ Hestia وتحفظ المفتاح في مكان آمن لوحده.

## 6) استثناء `hr-system/config` من نسخ Hestia الاحتياطية لـ tarek
**بيعمل إيه:** نسخ Hestia بتاخد فولدر الدومين كله، ومنه `hr-system`. لو `config/.env`، وفيه `FACE_KEY` وباسورد الداتابيز، دخل في نفس النسخة مع الصور المشفّرة والداتابيز، يبقى أي حد معاه النسخة يقدر يفك كل حاجة. الاستثناء ده بيخلي النسخة فيها البيانات المشفّرة من غير المفتاح، والمفتاح بيتحفظ لوحده (القسم اللي بعده).

**أ) شوف الاستثناءات الحالية الأول، عشان ماتمسحهاش:**
```bash
v-list-user-backup-exclusions tarek
```
**ب) اكتب ملف الاستثناءات.** لو فيه استثناءات موجودة، انقلها في نفس الملف. مسارات WEB بتبقى نسبةً لفولدر الدومين `/home/tarek/web/$D/`، والمسارات المختلفة لنفس الدومين بتتفصل بـ `:`، والدومينات المختلفة بـ `,`:
```bash
cat > /root/tarek-backup-exclusions.conf <<'EOF'
WEB='greenheights.edu.eg:hr-system/config'
MAIL=''
DB=''
CRON=''
USER=''
EOF
# مثال لو كان فيه استثناء قديم للدومين: WEB='greenheights.edu.eg:public_html/cache:hr-system/config'
v-update-user-backup-exclusions tarek /root/tarek-backup-exclusions.conf
v-list-user-backup-exclusions tarek          # لازم تشوف hr-system/config تحت WEB
```
**ج) اتأكد إنه اشتغل فعلًا:** اعمل نسخة وافتحها. ده بيعمل نسخة كاملة لـ tarek، فشغّله في وقت هادي واتأكد إن فيه مساحة في `/backup`:
```bash
v-backup-user tarek
B=$(ls -t /backup/tarek.*.tar | head -1); echo "$B"
F=$(tar -tf "$B" | grep "web/$D/domain_data.tar" | head -1); echo "$F"     # .tar.zst أو .tar.gz حسب نسخة Hestia
case "$F" in *.zst) X="zstd -dc" ;; *) X="gzip -dc" ;; esac
tar -xOf "$B" "$F" | $X | tar -t > /root/hr-backup-list.txt
grep -c 'hr-system/storage' /root/hr-backup-list.txt     # لازم رقم أكبر من 0 (يعني hr-system نفسه داخل في النسخة)
grep 'hr-system/config' /root/hr-backup-list.txt && echo "FAIL: config لسه في النسخة" || echo "OK: config مستثنى"
rm -f /root/hr-backup-list.txt
```
لو طلع `FAIL`، يبقى المسار مكتوب غلط بالنسبة لنسخة Hestia عندك. ابعتلي ناتج `v-list-user-backup-exclusions tarek` والسطرين اللي فيهم `hr-system/config` من الملف.
**الرجوع:** شيل `:hr-system/config` من سطر WEB في الملف، وأعد `v-update-user-backup-exclusions tarek /root/tarek-backup-exclusions.conf`.

---

## نسخة من `.env` بره السيرفر (مش محتاجة root)
`.env` فيه 3 حاجات: باسورد الداتابيز، و`FACE_KEY`، و`FACE_TOKEN`.
- **`FACE_KEY` هو أهمهم:** لو ضاع، كل الصور المرجعية والبصمات الرقمية مش هتتقري تاني، وكل الموظفين هيرفعوا صورهم من الأول.
- باسورد الداتابيز تقدر تغيّره من Hestia.
- `FACE_TOKEN` تقدر تعمل واحد جديد.

**1) انسخه لجهازك** (من PowerShell على جهازك، بنفس مفتاح claudebuild):
```powershell
scp -i "C:\Users\Sayed Bakr\.ssh\claudebuild_key" claudebuild@199.192.20.239:/home/tarek/web/greenheights.edu.eg/hr-system/config/.env "$env:USERPROFILE\ghis-hr.env"
```
**2) احفظه في مكان آمن**، واختار طريقة واحدة:
- **الأفضل:** افتح الملف، وانسخ محتواه في **Secure Note** في مدير باسوردات (Bitwarden أو 1Password أو KeePass)، باسم "GHIS HR .env — تاريخ النهارده".
- أو اضغطه بـ 7-Zip بتشفير **AES-256** وباسورد قوي، وفعّل "Encrypt file names"، واحفظ الملف المضغوط على فلاشة أو تخزين سحابي. الباسورد نفسه يتحفظ في مدير الباسوردات، مش جنب الملف.

**3) امسح النسخة اللي على الجهاز:**
```powershell
Remove-Item "$env:USERPROFILE\ghis-hr.env"
```
**4) اعمل نسخة جديدة** كل ما `.env` يتغير (مثلًا لو غيرت باسورد الداتابيز أو المفتاح).

**الاسترجاع:** اعمل الملف تاني بنفس المحتوى في `hr-system/config/.env` (كـ claudebuild)، وبعدين `setfacl -m u:tarek:r--,o::--- .env` (مهم: ماتستخدمش `chmod` عليه، لأنه بيلغي صلاحية tarek من الـ ACL). لو كان `FACE_TOKEN` اتغير، حدّث `/etc/gh-face/env` بالأمر اللي في خطوة 4 واعمل `systemctl restart gh-face.service`.
