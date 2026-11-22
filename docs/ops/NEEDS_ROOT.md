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
v-list-web-domain tarek $D | grep -Ei 'SSL|FORCE'   # يفضل SSL=yes و FORCE_SSL=yes
ls /home/tarek/conf/web/$D/             # نتأكد إن مفيش ملفات *_hr قديمة
```

## 1) صلاحيات الملفات (ACL)
**بيعمل إيه:** claudebuild يبقى المالك وليه كتابة على كل حاجة؛ tarek (يوزر PHP) يقرا الكود بس ويكتب في `storage/` بس؛ nginx (www-data) يقرا الملفات الثابتة في `public/hr/` بس؛ وأي حد تاني ملوش أي صلاحية. الصلاحيات الافتراضية (`d:`) بتتطبق على أي ملف جديد يتعمل بعد كده.
```bash
chown -R claudebuild:claudebuild "$H"
chmod -R u=rwX,g=,o= "$H"
setfacl -R -m u:claudebuild:rwX -m d:u:claudebuild:rwX "$H"
setfacl -R -m u:tarek:rX       -m d:u:tarek:rX       "$H"
setfacl -R -m u:tarek:rwX      -m d:u:tarek:rwX      "$H/storage"
setfacl    -m u:www-data:x "$H" "$H/public"
setfacl -R -m u:www-data:rX    -m d:u:www-data:rX    "$H/public/hr"
setfacl -R -m d:o::--- "$H"
# تحقق:
getfacl -p "$H/config/.env" "$H/storage" "$H/public/hr/assets/app.css"
sudo -u www-data cat "$H/config/.env"   # لازم يطلع Permission denied
```
**الرجوع:** `setfacl -R -b "$H" && chown -R <المالك-القديم من خطوة 0> "$H"`

## 2) PHP-FPM pool منفصل `gh-hr`
**بيعمل إيه:** pool خاص بالنظام بيوزر tarek، و`open_basedir` على `hr-system` و`/home/tarek/tmp` بس، ومقفول فيه تنفيذ أوامر النظام (`exec`/`system`/…) و`mail` والاتصال بروابط خارجية من PHP (`allow_url_fopen=off`)، ورفع الملفات مقفول. اسم الملف مش اسم الدومين، فـ Hestia مش هيمسحه لما يعيد بناء الدومين.
```bash
install -o root -g root -m 644 "$H/deploy/gh-hr.fpm.conf" /etc/php/8.3/fpm/pool.d/gh-hr.conf
mkdir -p /home/tarek/tmp && chown tarek:tarek /home/tarek/tmp && chmod 700 /home/tarek/tmp
php-fpm8.3 -t && systemctl reload php8.3-fpm
ls -l /run/php/php8.3-fpm-gh-hr.sock     # srw-rw---- www-data www-data
```
**الرجوع:** `rm /etc/php/8.3/fpm/pool.d/gh-hr.conf && systemctl reload php8.3-fpm`

## 3) قاعدة nginx لـ `/hr`
**بيعمل إيه:** `location ^~ /hr/` (مش regex، فقاعدة Hestia للملفات الثابتة مش هتسبقها). الـ root هو `hr-system/public` واللي جواه `hr/assets` بس، فمستحيل أي ملف PHP أو إعدادات يتقري كملف. الملفات الثابتة بـ `try_files $uri @gh_hr`، وأي حاجة تانية (أو ملف مش موجود) بتروح للـ named location `@gh_hr` اللي بيبعتها لـ `index.php` على الـ pool الجديد. ملف HTTP بيحوّل `/hr` لـ HTTPS. باقي الموقع (`/` و`new-site`) مش بيتأثر.
```bash
install -o root -g root -m 644 "$H/deploy/nginx.ssl.conf_hr" /home/tarek/conf/web/$D/nginx.ssl.conf_hr
install -o root -g root -m 644 "$H/deploy/nginx.conf_hr"     /home/tarek/conf/web/$D/nginx.conf_hr
nginx -t && systemctl reload nginx
curl -sI https://$D/hr/ | head -5        # المتوقع: 303 لـ /hr/index.php?r=login
curl -sI https://$D/ | head -1           # الموقع الرئيسي زي ما هو
```
**الرجوع:** `rm /home/tarek/conf/web/$D/nginx.ssl.conf_hr /home/tarek/conf/web/$D/nginx.conf_hr && nginx -t && systemctl reload nginx`

---

**Cron:** مش محتاجين. تنظيف الجلسات ومحاولات الدخول القديمة بيحصل جوه النظام نفسه.
**النسخ الاحتياطي:** قاعدة `tarek_hr` بتدخل في نسخ Hestia العادية لليوزر tarek. خلي بالك إن النسخ دي فيها بيانات مرتبات، فلازم تتحفظ في مكان آمن.
