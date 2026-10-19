-- Base data: roles, permission matrix, settings. Safe to re-run.
SET NAMES utf8mb4;

INSERT INTO roles (slug, name, is_super, is_staff, is_system) VALUES
 ('super_admin', 'سوبر أدمن', 1, 1, 1),
 ('admin',       'أدمن',      0, 1, 1),
 ('hr',          'HR',        0, 1, 0),
 ('legal',       'شئون قانونيه و عاملين', 0, 1, 0),
 ('employee',    'موظف / مدرس', 0, 0, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- admin: everything except users/roles/settings (super admin only)
INSERT IGNORE INTO role_permissions (role_id, module, can_view, can_create, can_update, can_delete, can_export)
SELECT r.id, m.module, 1, 1, 1, 1, 1 FROM roles r
JOIN (SELECT 'employees' module UNION ALL SELECT 'attendance' UNION ALL SELECT 'rules' UNION ALL
      SELECT 'salary' UNION ALL SELECT 'documents' UNION ALL SELECT 'investigations' UNION ALL
      SELECT 'notifications' UNION ALL SELECT 'reports' UNION ALL SELECT 'audit') m
WHERE r.slug = 'admin';

INSERT IGNORE INTO role_permissions (role_id, module, can_view, can_create, can_update, can_delete, can_export)
SELECT r.id, m.module, 1, m.c, m.c, 0, 1 FROM roles r
JOIN (SELECT 'employees' module, 1 c UNION ALL SELECT 'attendance', 1 UNION ALL SELECT 'rules', 0 UNION ALL
      SELECT 'documents', 1 UNION ALL SELECT 'notifications', 1 UNION ALL SELECT 'reports', 0) m
WHERE r.slug = 'hr';

INSERT IGNORE INTO role_permissions (role_id, module, can_view, can_create, can_update, can_delete, can_export)
SELECT r.id, m.module, 1, m.c, m.c, 0, 1 FROM roles r
JOIN (SELECT 'employees' module, 0 c UNION ALL SELECT 'investigations', 1 UNION ALL SELECT 'documents', 1) m
WHERE r.slug = 'legal';

INSERT INTO settings (k, v, label, note) VALUES
 ('auto_employee_code', '1', 'توليد كود الموظف تلقائيًا', 'لو مفعّل، الكود الجديد = أكبر كود + 1'),
 ('doc_expiry_alert_days', '30', 'عدد أيام "قريب الانتهاء" للمستندات', NULL),
 ('weekend_days', '5,6', 'أيام العطلة الأسبوعية', 'أرقام ISO مفصولة بفاصلة: 1=الاثنين … 5=الجمعة، 6=السبت، 7=الأحد'),
 ('geofence_enforce', '1', 'منع البصمة خارج نقاط الحضور', '1 = يرفض البصمة لو الموظف بعيد عن كل النقاط، 0 = يسجلها مع المسافة'),
 ('checkin_require_photo', '0', 'إلزام صورة سيلفي مع البصمة', '1 = إلزامي'),
 ('late_rounding', 'ceil', 'تقريب وحدات التأخير', 'ceil = أي جزء من الوحدة يتحسب وحدة، floor = الوحدات الكاملة بس'),
 ('day_divisor', '30', 'قسمة الراتب لحساب قيمة اليوم', NULL)
ON DUPLICATE KEY UPDATE label = VALUES(label);

INSERT INTO employee_field_permissions (field, label, visible, editable, required, sort_order) VALUES
 ('name_ar','الاسم بالعربي',1,1,1,1), ('name_en','الاسم بالإنجليزي',1,1,0,2), ('phone','رقم الهاتف',1,1,1,3),
 ('nationality','الجنسية',1,1,0,4), ('department_id','القسم',1,0,0,6), ('job_title_id','المسمى الوظيفي',1,0,0,7),
 ('hire_date','تاريخ التعيين',1,0,0,8)
ON DUPLICATE KEY UPDATE label = VALUES(label);

INSERT INTO settings (k, v, label, note) VALUES
 ('face_enabled', '1', 'تفعيل بصمة الوجه في الحضور', '1 = لازم صورة مرجعية وبصمة وجه لكل حضور وانصراف'),
 ('face_match_threshold', '0.40', 'حد قبول مطابقة الوجه (0 إلى 1)', 'تشابه SFace (cosine). أعلى = أصرم. القيمة المرجعية من OpenCV هي 0.363'),
 ('face_max_failures', '3', 'عدد مرات فشل المطابقة المتتالية قبل التحويل لمراجعة HR', NULL),
 ('face_min_yaw_range', '0.25', 'أقل حركة راس مطلوبة (لف يمين وشمال)', 'أعلى = لازم يلف راسه أكتر. الصورة المطبوعة أو على الموبايل بتطلع تقريبًا 0.05'),
 ('face_consent_version', '1', 'رقم إصدار نص الموافقة', 'زوّد الرقم لما تغيّر النص عشان كل الموظفين يوافقوا تاني'),
 ('face_consent_text', 'أوافق صراحةً على قيام المدرسة بمعالجة صورة وجهي واستخراج بصمة رقمية منها لغرض واحد فقط هو التحقق من شخصيتي عند تسجيل الحضور والانصراف.\nأعلم أن:\n- الصورة المرجعية والبصمة الرقمية بيتحفظوا على سيرفر المدرسة فقط، ومش بيتبعتوا لأي جهة خارجية.\n- اللقطات اللي بتتاخد وقت البصمة بتتمسح فورًا بعد المقارنة.\n- الصورة المرجعية بيشوفها مدير النظام فقط، وبتتمسح هي والبصمة الرقمية تلقائيًا عند انتهاء خدمتي.\n- أقدر أطلب معلومات عن بياناتي أو أسحب موافقتي بالتواصل مع إدارة الموارد البشرية، مع العلم إن ده هيستلزم ترتيب بديل لتسجيل الحضور.', 'نص الموافقة على معالجة بصمة الوجه', 'مسودة؛ يُفضّل مراجعتها قانونيًا')
ON DUPLICATE KEY UPDATE label = VALUES(label);

INSERT INTO settings (k, v, label, note) VALUES
 ('face_consent_text_en', 'I explicitly consent to the school processing an image of my face and deriving a digital face template from it for one purpose only: verifying my identity when I record attendance.
I understand that:
- The reference photo and face template are stored only on the school''s own server and are never sent to any third party.
- The frames captured at each check-in are deleted immediately after the comparison.
- Only the system administrator can view the reference photo, and the photo and template are deleted automatically when my employment ends.
- I may request information about my data or withdraw my consent by contacting Human Resources; an alternative attendance arrangement will then be needed.', 'نص الموافقة على معالجة بصمة الوجه (إنجليزي)', 'بيظهر للي مختار اللغة الإنجليزية. أي تعديل فيه بيرفع رقم الإصدار')
ON DUPLICATE KEY UPDATE label = VALUES(label);
