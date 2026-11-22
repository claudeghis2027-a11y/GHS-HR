# GHIS HR

نظام شؤون الموظفين (PHP + MySQL) اللي شغال على السيرفير في `hr-system/`.
المستودع ده للتجربة والتعديل قبل رفع النسخة النهائية على السيرفير.

## الهيكل
- `app/` الكود الأساسي (الصفحات، المكتبات، الواجهات، الترجمة)
- `public/` نقطة الدخول `index.php` والملفات الثابتة
- `database/` `schema.sql` و `seed.sql`
- `bin/` أدوات التثبيت والاستيراد والاختبار
- `face-service/` خدمة التعرف على الوجه (Python + نماذج ONNX)
- `deploy/` إعدادات nginx و PHP-FPM
- `docs/` التوثيق، و `docs/ops/` أوامر السيرفير (root)
- `legacy/` النسخة القديمة على Google Apps Script

## التشغيل
1. انسخ `config/config.sample.php` إلى `config/config.php`
2. اعمل `config/.env` فيه: `DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, FACE_KEY, FACE_TOKEN, FACE_SOCKET`
3. `php bin/install.php`

> ملفات `config/.env` و `config/config.php` و `storage/` وملفات Excel مش مرفوعة عمدًا لأنها فيها كلمات سر وبيانات موظفين.
