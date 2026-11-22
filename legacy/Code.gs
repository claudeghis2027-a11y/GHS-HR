/**
 * GHIS V2 — ghis_Code.gs — PHASE 4: + التقارير · مركز التقارير · الرواتب · خصم الصيف · واتساب · الإشعارات · النسخ الاحتياطي — OLD GHIS baseline
 * ------------------------------------------------------------------------------------
 * Functional contract : OLD GHIS (Sheet + ghis_Code.gs + index.html) — terminology, workflows,
 *                       business rules, permission keys. Nothing invented.
 * Technical contract  : V2 architecture — one api(request) entry, {ok,data,meta} envelope,
 *                       DataAccess (only SpreadsheetApp), FileStore (only DriveApp), services,
 *                       session = sheet + cache, no global locks, no N+1.
 *
 * Layers: Web entry (api/doGet/doPost) → dispatch (auth → permission → service) → Services → DA/FileStore
 *
 * FILES IN THE APPS SCRIPT PROJECT: ghis_Code.gs · index.html · login_bg.html (frozen login asset)
 * DATABASE BINDING (explicit, never implicit):
 *   GHIS_V2_SPREADSHEET_ID (Script Property) is the ONE binding to the database Spreadsheet.
 *   initializeSystem() NEVER creates a Spreadsheet. It only ensures missing sheets inside the bound one.
 *   Provisioning a brand-new database is a separate, deliberate call: createV2DatabaseExplicitly().
 *   Diagnosis (read-only): diagnoseV2DatabaseBinding() · getV2DatabaseInfo() · bindV2Database(id) to re-bind.
 *   The Web App never uses getActiveSpreadsheet(); it always opens the bound id.
 * SCRIPT PROPERTIES (optional): ATTENDANCE_SPREADSHEET_ID (fingerprint file, for sync/reset/bridge),
 *            EMPLOYEE_BRIDGE_SECRET (shared secret for employeeBridge).
 * ------------------------------------------------------------------------------------
 */
'use strict';

/* ===================================================================== */
/* 0. CONFIG + ERRORS                                                    */
/* ===================================================================== */

const CFG = {
  VERSION: '2.5.0-final',
  PROP_SSID: 'GHIS_V2_SPREADSHEET_ID',
  PROP_ATT_SSID: 'ATTENDANCE_SPREADSHEET_ID',
  PROP_BRIDGE_SECRET: 'EMPLOYEE_BRIDGE_SECRET',
  SS_NAME: 'GHIS V2 — نظام شئون العاملين',
  TZ: 'Africa/Cairo',
  SESSION_TTL_HOURS: 8,
  CACHE_SESSION_SEC: 21600,
  CACHE_SHARED_SEC: 600,
  ATT_EMPLOYEES_SHEET: 'بيانات الموظفين',   // employees sheet INSIDE the attendance file (code + Arabic name)
  ATT_PASSWORDS_SHEET: 'كلمات المرور',      // attendance app passwords sheet (reset to default)
  ATT_DEFAULT_PASSWORD: '12345678',
  ATT_PUNCH_COL: { CODE: 1, DATE: 3, CHECKIN_TIME: 4, CHECKIN_LOCATION: 5, CHECKIN_DISTANCE: 6, CHECKOUT_TIME: 8, CHECKOUT_LOCATION: 9, CHECKOUT_DISTANCE: 10 },   // OLD PUNCH_COL — monthly sheets YYYY-MM inside the attendance file
  BULK_MAX_ROWS: 200,
  MAX_FILE_BYTES: 4 * 1024 * 1024
};

const ERR = { AUTH_ERROR: 'AUTH_ERROR', SESSION_EXPIRED: 'SESSION_EXPIRED', PERMISSION_DENIED: 'PERMISSION_DENIED', VALIDATION_ERROR: 'VALIDATION_ERROR', NOT_FOUND: 'NOT_FOUND', TIMEOUT: 'TIMEOUT', NETWORK_ERROR: 'NETWORK_ERROR', SERVER_ERROR: 'SERVER_ERROR' };

class AppError extends Error { constructor(code, message, details) { super(message || code); this.code = code; this.details = details || null; } }
function fail(code, message, details) { throw new AppError(code, message, details); }
function assert(cond, message, details) { if (!cond) fail(ERR.VALIDATION_ERROR, message, details); }

/* ===================================================================== */
/* 1. PERMISSION MODEL — OLD GHIS keys, labels, coarse→fine, module/action registry (verbatim values) */
/* ===================================================================== */

const SECTION_LABELS_ = {
  reports: 'التقارير', employees: 'بيانات الموظفين', rules: 'القواعد', salary: 'حركات المرتب', documents: 'المستندات',
  backup: 'نسخ احتياطي', users: 'المستخدمين والصلاحيات', settings: 'الإعدادات',
  docs_view: 'عرض المستندات', docs_add: 'إضافة مستندات', docs_edit: 'تعديل مستندات', docs_delete: 'حذف مستندات',
  docs_types_manage: 'إدارة أنواع المستندات', docs_reports: 'تقارير المستندات',
  backup_create: 'إنشاء نسخة احتياطية', backup_restore: 'استرجاع نسخة', backup_upload: 'رفع نسخة', backup_view: 'عرض/تنزيل النسخ',
  reports_export: 'تصدير التقارير (Excel/PDF)', permissions_view: 'عرض الأذونات', permissions_add: 'تسجيل إذن',
  end_of_service_reports: 'تقارير نهاية الخدمة', end_of_service_reports_export: 'تصدير تقارير نهاية الخدمة',
  payroll: 'الرواتب (Payroll)', whatsapp_send: 'رسائل واتساب', employees_export: 'تصدير بيانات الموظفين',
  attendance_export: 'تصدير تقارير الحضور', documents_export: 'تصدير تقارير المستندات',
  notifications_manage: 'إدارة التنبيهات والإشعارات', legal: 'الشئون القانونية (التحقيقات)',
  period_close: 'إنشاء وإقفال فترات المرتبات', period_reopen: 'إعادة فتح فترة مقفولة', attendance_adjust: 'إعفاءات التأخير وتعديلات الحضور'
};
const SECTION_KEYS_ = Object.keys(SECTION_LABELS_);
const COARSE_IMPLIES_ = {
  documents: ['docs_view', 'docs_add', 'docs_edit', 'docs_delete', 'docs_types_manage', 'docs_reports'],
  backup: ['backup_create', 'backup_restore', 'backup_upload', 'backup_view'],
  reports_export: ['employees_export', 'attendance_export', 'documents_export']
};
const MODULE_ACTION_REGISTRY_ = [
  { module: 'employees', label: 'بيانات الموظفين', actions: { view: 'employees', create: 'employees', update: 'employees', delete: null, export: 'employees_export' }, sharedGroups: [{ key: 'employees', actions: ['view', 'create', 'update'] }] },
  { module: 'documents', label: 'المستندات', actions: { view: 'docs_view', create: 'docs_add', update: null, delete: 'docs_delete', export: 'documents_export' }, sharedGroups: [] },
  { module: 'docs_types', label: 'إدارة أنواع المستندات', actions: { view: null, create: null, update: 'docs_types_manage', delete: null, export: null }, sharedGroups: [] },
  { module: 'docs_reports', label: 'تقارير المستندات', actions: { view: 'docs_reports', create: null, update: null, delete: null, export: 'documents_export' }, sharedGroups: [] },
  { module: 'rules', label: 'القواعد', actions: { view: 'rules', create: null, update: 'rules', delete: null, export: null }, sharedGroups: [{ key: 'rules', actions: ['view', 'update'] }] },
  { module: 'salary', label: 'حركات المرتب', actions: { view: 'salary', create: 'salary', update: 'salary', delete: null, export: null }, sharedGroups: [{ key: 'salary', actions: ['view', 'create', 'update'] }] },
  { module: 'reports', label: 'التقارير (الحضور)', actions: { view: 'reports', create: null, update: null, delete: null, export: 'attendance_export' }, sharedGroups: [] },
  { module: 'end_of_service', label: 'نهاية الخدمة', actions: { view: 'end_of_service_reports', create: null, update: null, delete: null, export: 'end_of_service_reports_export' }, sharedGroups: [] },
  { module: 'users', label: 'المستخدمين والصلاحيات', actions: { view: 'users', create: 'users', update: 'users', delete: null, export: null }, sharedGroups: [{ key: 'users', actions: ['view', 'create', 'update'] }] },
  { module: 'backup', label: 'نسخ احتياطي', actions: { view: 'backup_view', create: 'backup_create', update: null, delete: null, export: null }, special: { upload: 'backup_upload', restore: 'backup_restore' }, sharedGroups: [] }
];

/* ===================================================================== */
/* 2. SCHEMA — sheet names + column headers are the OLD GHIS Arabic names; keys are internal */
/* ===================================================================== */

const YES = 'نعم', NO = 'لا';
const SCHEMA = {
  Users: { sheet: 'مستخدمو النظام', owner: 'UserService',
    fields: [['username', 'اسم المستخدم'], ['passwordHash', 'كلمة المرور (مشفّرة)'], ['salt', 'Salt'], ['role', 'الصلاحية'], ['active', 'نشط؟'], ['createdAt', 'تاريخ الإنشاء'], ['lastLoginAt', 'آخر دخول']] },
  Roles: { sheet: 'مستويات الصلاحيات', owner: 'RoleService',
    fields: [['name', 'اسم الصلاحية'], ['sections', 'الأقسام المسموحة (مفصولة بفاصلة)'], ['isAdmin', 'أدمن؟ (نعم/لا)'], ['active', 'نشط؟']],
    seed: [['أدمن', 'reports,employees,rules,salary,documents,backup,users', YES, YES], ['HR', '', NO, YES], ['شئون قانونيه و عاملين', '', NO, YES]] },
  PermissionsMatrix: { sheet: 'مصفوفة الصلاحيات', owner: 'RoleService',
    fields: [['role', 'اسم الصلاحية'], ['module', 'Module'], ['view', 'View'], ['create', 'Create'], ['update', 'Update'], ['delete', 'Delete'], ['export', 'Export'], ['upload', 'Upload'], ['restore', 'Restore']] },
  Sessions: { sheet: 'الجلسات', owner: 'AuthService',
    fields: [['token', 'Token'], ['username', 'اسم المستخدم'], ['role', 'الصلاحية'], ['createdAt', 'تاريخ الإنشاء'], ['expiresAt', 'تاريخ الانتهاء']] },
  Settings: { sheet: 'الإعدادات العامة', owner: 'SettingsService',
    fields: [['key', 'الإعداد'], ['value', 'القيمة'], ['note', 'ملاحظة']],
    seed: [['توليد كود الموظف تلقائيًا؟ (نعم/لا)', YES, 'لو "نعم"، كود الموظف الجديد بيتحدد تلقائيًا (MAX الأكواد الحالية + 1) — مش المستخدم اللي بيكتبه.'], ['عدد أيام "قريب الانتهاء" للمستندات', 30, 'المستند اللي باقي على انتهائه أقل من أو يساوي العدد ده بيظهر "قريب الانتهاء".']] },
  NameLists: { sheet: 'قوائم الأقسام والمسميات', owner: 'NameListService',
    fields: [['kind', 'النوع'], ['name', 'الاسم'], ['active', 'نشط؟ (نعم/لا)'], ['createdAt', 'تاريخ الإضافة']],
    // ONLY values confirmed by the OLD GHIS sheet (الإعدادات العامة: قسم — … / مسمى وظيفي — …)
    seed: [['قسم', 'الادارة', YES, ''], ['قسم', 'الامريكي', NO, ''], ['مسمى وظيفي', 'مدرسه', YES, ''], ['مسمى وظيفي', 'مدرس', NO, '']] },
  Employees: { sheet: 'بيانات الموظفين', owner: 'EmployeeService',
    fields: [['code', 'كود الموظف'], ['nameEn', 'الاسم بالإنجليزي (من برنامج البصمة)'], ['nameAr', 'الاسم بالعربي'], ['dept', 'القسم'],
      ['hireDate', 'تاريخ التعيين'], ['endDate', 'تاريخ نهاية الخدمة'], ['exemptManual', 'معفى يدويًا من البصمة؟ (نعم/لا)'], ['jobTitle', 'المسمى الوظيفي'],
      ['nationality', 'الجنسية'], ['phone', 'رقم التليفون'], ['paymentMethod', 'طريقة القبض (كاش/فيزا)'], ['receivedDocumentsAfterEnd', 'استلم أوراقه بعد ترك الخدمة؟ (نعم/لا)'],
      ['referencePhotoFileId', 'صورة الموظف'], ['summerDeduction', 'خصم الصيف'], ['firstYearDeduction', 'خصم السنة الأولى'], ['firstRaiseMonth', 'شهر أول علاوة مستحقة'], ['firstYearSummerDeduction', 'استثناء خصم السنة الأولى']] },
  EmployeeHistory: { sheet: 'سجل تعديلات بيانات الموظفين', owner: 'EmployeeService',
    fields: [['code', 'كود الموظف'], ['field', 'الحقل المعدل'], ['oldValue', 'القيمة القديمة'], ['newValue', 'القيمة الجديدة'], ['reason', 'السبب'], ['user', 'المستخدم'], ['timestamp', 'تاريخ ووقت التعديل'], ['operationId', 'معرّف العملية']] },
  ExemptDepartments: { sheet: 'الأقسام المعفاة من البصمة', owner: 'ExemptDepartmentService',
    fields: [['dept', 'القسم'], ['active', 'نشط؟ (نعم/لا)'], ['createdAt', 'تاريخ الإضافة']] },
  SyncLog: { sheet: 'سجل مزامنة الموظفين', owner: 'AttendanceService',
    fields: [['code', 'كود الموظف'], ['nameAr', 'الاسم بالعربي'], ['result', 'نتيجة المزامنة (نجح/فشل)'], ['details', 'تفاصيل/رسالة الخطأ'], ['timestamp', 'تاريخ ووقت المحاولة']] },
  ScheduleOverrides: { sheet: 'مواعيد خاصة لموظفين', owner: 'ScheduleOverrideService',
    fields: [['code', 'كود الموظف'], ['from', 'من تاريخ'], ['to', 'لحد تاريخ'], ['checkIn', 'موعد الحضور'], ['checkOut', 'موعد الانصراف'], ['user', 'المستخدم'], ['createdAt', 'تاريخ التسجيل']] },
  SalaryTxTypes: { sheet: 'أنواع حركات المرتب', owner: 'SalaryService',
    fields: [['name', 'اسم النوع'], ['kind', 'إضافة أم خصم؟'], ['active', 'نشط؟ (نعم/لا)'], ['category', 'التصنيف']],
    seed: [['الراتب الأساسي', 'إضافة', YES, 'basic']] },
  SalaryTransactions: { sheet: 'حركات المرتب', owner: 'SalaryService',
    fields: [['code', 'كود الموظف'], ['type', 'نوع الحركة'], ['from', 'من تاريخ'], ['to', 'لحد تاريخ (فاضي = مستمرة)'], ['value', 'القيمة'], ['reason', 'السبب'], ['createdAt', 'تاريخ التسجيل'], ['user', 'المستخدم'], ['txId', 'Tx ID']] },
  PhotoReview: { sheet: 'مراجعة صور الموظفين', owner: 'PhotoService',
    fields: [['code', 'كود الموظف'], ['pendingFileId', 'معرّف الصورة المعلّقة'], ['reviewStatus', 'حالة المراجعة'], ['submissionUsed', 'استُهلكت فرصة الرفع؟'], ['updatedAt', 'آخر تحديث']] },
  FaceReview: { sheet: 'حالة مراجعة مطابقة الوجه', owner: 'PhotoService',
    fields: [['code', 'كود الموظف'], ['failures', 'عدد الفشل المتتالي'], ['reviewStatus', 'حالة المراجعة'], ['updatedAt', 'آخر تحديث']] },
  FieldPermissions: { sheet: 'صلاحيات حقول الموظف', owner: 'ProfileRequestService',
    fields: [['key', 'الحقل'], ['label', 'التسمية'], ['col', 'رقم العمود'], ['visible', 'مرئي للموظف'], ['editable', 'قابل للتعديل'], ['required', 'إلزامي'], ['order', 'الترتيب']],
    seed: [['nameAr', 'الاسم بالعربي', 3, YES, YES, YES, 1], ['nameEn', 'الاسم بالإنجليزي', 2, YES, YES, NO, 2], ['phone', 'رقم الهاتف', 10, YES, YES, YES, 3], ['nationality', 'الجنسية', 9, YES, YES, NO, 4],
      ['referencePhoto', 'الصورة الشخصية', 13, YES, YES, YES, 5], ['dept', 'القسم', 4, YES, NO, NO, 6], ['jobTitle', 'المسمى الوظيفي', 8, YES, NO, NO, 7], ['hireDate', 'تاريخ التعيين', 5, YES, NO, NO, 8]] },
  EnrollmentStatus: { sheet: 'حالة استكمال بيانات الموظفين', owner: 'ProfileRequestService',
    fields: [['code', 'كود الموظف'], ['status', 'الحالة'], ['submittedAt', 'تاريخ التقديم'], ['approvedAt', 'تاريخ الاعتماد'], ['reviewer', 'المراجِع'], ['rejectReason', 'سبب الرفض']] },
  ProfileRequests: { sheet: 'طلبات تعديل بيانات الموظفين', owner: 'ProfileRequestService',
    fields: [['id', 'معرّف الطلب'], ['code', 'كود الموظف'], ['nameAr', 'اسم الموظف'], ['fields', 'الحقول (JSON)'], ['pendingPhotoFileId', 'معرّف ملف الصورة'], ['submittedAt', 'تاريخ التقديم'], ['status', 'الحالة'], ['reviewer', 'المراجِع'], ['reviewedAt', 'تاريخ المراجعة'], ['rejectReason', 'سبب الرفض']] },
  AcademicYears: { sheet: 'السنوات الدراسية', owner: 'AcademicYearService',
    fields: [['id', 'Year ID'], ['name', 'اسم السنة'], ['from', 'من تاريخ'], ['to', 'لحد تاريخ'], ['active', 'نشطة؟'], ['createdBy', 'أنشأها'], ['createdAt', 'تاريخ الإنشاء'], ['updatedAt', 'آخر تعديل']] },
  DocumentTypes: { sheet: 'أنواع المستندات', owner: 'DocumentService',
    fields: [['name', 'اسم النوع'], ['hasNumber', 'له رقم؟ (نعم/لا)'], ['required', 'مطلوب؟ (نعم/لا)'], ['hasExpiry', 'له تاريخ انتهاء؟ (نعم/لا)'], ['alertDays', 'مدة التنبيه بالأيام'], ['active', 'نشط؟ (نعم/لا)']] },
  EmployeeDocuments: { sheet: 'مستندات الموظفين', owner: 'DocumentService',   // append-only: latest row per (code,type) wins; soft-delete = new row with محذوف؟=نعم (OLD model)
    fields: [['code', 'كود الموظف'], ['type', 'نوع المستند'], ['documentNumber', 'رقم المستند'], ['copyType', 'أصل أم صورة؟'], ['issueDate', 'تاريخ الإصدار'], ['expiryDate', 'تاريخ الانتهاء'], ['driveFileId', 'معرّف ملف Drive'], ['isDeleted', 'محذوف؟ (نعم/لا)'], ['registeredAt', 'تاريخ التسجيل'], ['user', 'المستخدم']] },
  Investigations: { sheet: 'التحقيقات', owner: 'InvestigationService',       // append-only versions: edit = mark old row ملغى + append new (OLD model)
    fields: [['id', 'معرّف التحقيق'], ['date', 'تاريخ التحقيق'], ['title', 'عنوان/موضوع التحقيق'], ['decision', 'القرار/النتيجة'], ['notes', 'ملاحظات'], ['cancelled', 'ملغى؟ (نعم/لا)'], ['user', 'المستخدم'], ['registeredAt', 'تاريخ التسجيل']] },
  InvestigationEmployees: { sheet: 'موظفو التحقيقات', owner: 'InvestigationService',
    fields: [['id', 'معرّف التحقيق'], ['code', 'كود الموظف'], ['cancelled', 'ملغى؟ (نعم/لا)'], ['registeredAt', 'تاريخ التسجيل']] },
  InvestigationAttachments: { sheet: 'مرفقات التحقيقات', owner: 'InvestigationService',
    fields: [['id', 'معرّف التحقيق'], ['fileId', 'معرّف الملف'], ['fileName', 'اسم الملف'], ['fileUrl', 'رابط الملف'], ['cancelled', 'ملغى؟ (نعم/لا)'], ['uploadedAt', 'تاريخ الرفع'], ['uploadedBy', 'رفعه']] },
  ScheduleRules: { sheet: 'قواعد المواعيد', owner: 'RulesService',
    fields: [['from', 'من تاريخ'], ['to', 'لحد تاريخ'], ['checkIn', 'موعد الحضور'], ['checkOut', 'موعد الانصراف'], ['id', 'معرّف القاعدة']] },
  ScheduleRulesArchive: { sheet: 'أرشيف قواعد المواعيد', owner: 'RulesService',
    fields: [['archiveId', 'معرّف الأرشيف'], ['ruleId', 'معرّف القاعدة'], ['opType', 'نوع العملية'], ['from', 'من تاريخ'], ['to', 'لحد تاريخ'], ['checkIn', 'موعد الحضور'], ['checkOut', 'موعد الانصراف'], ['user', 'المستخدم'], ['timestamp', 'تاريخ ووقت العملية'], ['reason', 'السبب']] },
  DeductionRules: { sheet: 'قواعد الخصم', owner: 'RulesService',
    fields: [['from', 'من تاريخ'], ['to', 'لحد تاريخ'], ['lateUnitMinutes', 'وحدة التأخير (دقائق)'], ['lateUnitValue', 'قيمة وحدة التأخير (يوم)'], ['absenceValue', 'خصم الغياب (يوم)'], ['missingCheckoutValue', 'خصم عدم بصمة الانصراف (يوم)'], ['earlyLeaveEnabled', 'تفعيل خصم الانصراف المبكر؟ (نعم/لا)'], ['earlyLeaveUnitMinutes', 'وحدة الانصراف المبكر (دقائق)'], ['earlyLeaveUnitValue', 'قيمة وحدة الانصراف المبكر (يوم)'], ['permission1', 'الإذن الأول'], ['permission2', 'الإذن الثاني'], ['permission3', 'الإذن الثالث'], ['permission4', 'الإذن الرابع'], ['permission5Plus', 'الإذن الخامس فأكثر']] },
  OfficialHolidays: { sheet: 'الإجازات الرسمية', owner: 'RulesService',
    fields: [['date', 'التاريخ'], ['reason', 'المناسبة']] },
  PublicHolidays: { sheet: 'العطلات الرسمية', owner: 'HolidayService',   // snapshot محلي للعطلات الرسمية للدولة (المحرك بيقرأ من هنا، مش من الإنترنت)
    fields: [['date', 'التاريخ'], ['holidayName', 'المناسبة'], ['holidayType', 'النوع'], ['source', 'المصدر'], ['sourceDate', 'تاريخ المصدر'], ['originalDate', 'التاريخ الأصلي'], ['observedDate', 'يوم الإجازة الفعلي'], ['status', 'الحالة'], ['lastSyncedAt', 'آخر مزامنة'], ['note', 'ملاحظة']],
    // Seed: القرار المؤكَّد الوحيد المتاح — عيد القوات المسلحة 2026 مُرحَّل إلى 2026-10-08. لا تُخترع أي تواريخ أخرى؛ باقي العطلات تأتي من المزامنة أو بإدخال إداري.
    seed: [['2026-10-08', 'عيد القوات المسلحة (6 أكتوبر) — يوم الإجازة الفعلي', 'OFFICIAL_HOLIDAY', 'وزارة العمل / مجلس الوزراء', '', '2026-10-06', '2026-10-08', 'ACTIVE', '', 'قرار ترحيل الإجازة — الأحدث يفوز على القائمة السنوية'],
           ['2026-10-06', 'عيد القوات المسلحة (6 أكتوبر) — التاريخ الأصلي', 'ORIGINAL_DATE_SUPERSEDED', 'رئاسة الجمهورية (القائمة السنوية)', '', '2026-10-06', '2026-10-08', 'SUPERSEDED', '', 'مش إجازة فعلية — اتنقلت إلى 2026-10-08']] },
  ApprovedLeaves: { sheet: 'الإجازات الشخصية المعتمدة', owner: 'AttendanceAdjustService',
    fields: [['code', 'كود الموظف'], ['from', 'من تاريخ'], ['to', 'لحد تاريخ'], ['reason', 'السبب']] },
  Adjustments: { sheet: 'تعديلات الحضور', owner: 'AttendanceAdjustService',
    fields: [['code', 'كود الموظف'], ['date', 'التاريخ'], ['type', 'نوع التعديل'], ['value', 'القيمة'], ['reason', 'السبب'], ['user', 'المستخدم'], ['timestamp', 'تاريخ التسجيل']] },
  CheckoutExceptions: { sheet: 'استثناءات إلزام الانصراف', owner: 'AttendanceAdjustService',
    fields: [['scopeType', 'النطاق'], ['scopeValue', 'القيمة'], ['from', 'من تاريخ'], ['to', 'لحد تاريخ'], ['user', 'المستخدم'], ['timestamp', 'تاريخ التسجيل']] },
  PermissionsLog: { sheet: 'سجل الأذونات', owner: 'AttendanceAdjustService',
    fields: [['code', 'كود الموظف'], ['date', 'التاريخ'], ['user', 'المستخدم'], ['timestamp', 'تاريخ التسجيل']] },
  ForgotCheckinLog: { sheet: 'سجل نسيان تسجيل الحضور/الانصراف', owner: 'AttendanceAdjustService',
    fields: [['code', 'كود الموظف'], ['date', 'التاريخ'], ['user', 'المستخدم'], ['timestamp', 'تاريخ التسجيل']] },
  ExtraPenaltiesLog: { sheet: 'سجل الخصومات الإضافية والجزاءات', owner: 'AttendanceAdjustService',
    fields: [['code', 'كود الموظف'], ['date', 'التاريخ'], ['reason', 'السبب/النوع'], ['days', 'عدد الأيام المخصومة'], ['user', 'المستخدم'], ['timestamp', 'تاريخ التسجيل']] },
  LateWaivers: { sheet: 'إعفاءات التأخير المعتمدة', owner: 'LateWaiverService',
    fields: [['code', 'كود الموظف'], ['date', 'التاريخ'], ['minutes', 'دقائق الإعفاء المعتمدة'], ['reason', 'سبب الإعفاء'], ['approvedBy', 'جهة الموافقة'], ['createdBy', 'المستخدم'], ['createdAt', 'تاريخ التسجيل'], ['id', 'معرّف الإعفاء']] },
  Periods: { sheet: 'فترات المرتبات', owner: 'ClosingService',
    fields: [['id', 'Period ID'], ['name', 'اسم الفترة'], ['from', 'من تاريخ'], ['to', 'لحد تاريخ'], ['status', 'الحالة'], ['createdBy', 'أنشأها'], ['createdAt', 'تاريخ الإنشاء'], ['closedBy', 'أقفلها'], ['closedAt', 'تاريخ الإقفال']] },
  PeriodCloseLog: { sheet: 'سجل إقفال الفترات', owner: 'ClosingService',
    fields: [['periodId', 'Period ID'], ['periodName', 'اسم الفترة'], ['action', 'الإجراء'], ['user', 'المستخدم'], ['timestamp', 'تاريخ ووقت الإجراء'], ['reason', 'السبب'], ['hasGaps', 'فيه تحذير فجوات؟'], ['gaps', 'تفاصيل الفجوات']] },
  ClosingSnapshots: { sheet: 'نسخ الإقفال الشهري', owner: 'ClosingService',
    fields: [['snapshotId', 'Snapshot ID'], ['periodId', 'Period ID'], ['periodName', 'اسم الفترة'], ['fromDate', 'من تاريخ'], ['toDate', 'لحد تاريخ'], ['state', 'الحالة'], ['dayColumns', 'أعمدة الأيام (JSON)'], ['diagnostics', 'تشخيصات (JSON)'], ['rowCount', 'عدد الموظفين'], ['closedBy', 'أقفلها'], ['closedAt', 'تاريخ الإقفال']] },
  ClosingSnapshotRows: { sheet: 'صفوف نسخ الإقفال', owner: 'ClosingService',
    fields: [['snapshotId', 'Snapshot ID'], ['snapshotOrder', 'الترتيب'], ['code', 'كود الموظف'], ['nameAr', 'الاسم بالعربي'], ['dept', 'القسم'], ['status', 'الحالة'], ['cells', 'الخلايا (JSON)'], ['totals', 'الإجماليات (JSON)']] },
  Advances: { sheet: 'سلف الموظفين', owner: 'SalaryService',
    fields: [['id', 'Advance ID'], ['code', 'كود الموظف'], ['advanceDate', 'تاريخ السلفة'], ['total', 'الإجمالي'], ['count', 'عدد الأقساط'], ['startDate', 'تاريخ بدء الخصم'], ['installmentValue', 'قيمة القسط'], ['status', 'الحالة'], ['notes', 'ملاحظات'], ['createdBy', 'سجّله'], ['createdAt', 'تاريخ التسجيل']] },
  PayrollYearSettings: { sheet: 'إعدادات المرتبات للسنة', owner: 'SalaryService',
    fields: [['yearId', 'Year ID'], ['raiseMonth', 'شهر العلاوة السنوية'], ['quarterDeductMonths', 'شهور خصم الربعين'], ['quarterRefundMonth', 'شهر رد الربعين'], ['departments', 'أقسام خصم الربعين'], ['savedBy', 'سجّله'], ['savedAt', 'تاريخ التسجيل']] },
  QuarterDeductionPolicy: { sheet: 'سياسة خصم الربعين', owner: 'SalaryService',
    fields: [['yearId', 'Year ID'], ['dept', 'القسم'], ['month', 'الشهر'], ['applied', 'مطبق؟'], ['user', 'سجّله'], ['timestamp', 'تاريخ التسجيل']] },
  SummerDeductionDepartments: { sheet: 'تصنيف أقسام خصم الصيف', owner: 'SalaryService',
    fields: [['dept', 'اسم القسم'], ['rule', 'القاعدة (Rule 1 / Rule 2)'], ['active', 'نشط؟']] },
  SummerDeductionLog: { sheet: 'سجل خصم واسترداد الصيف', owner: 'SalaryService',
    fields: [['code', 'كود الموظف'], ['month', 'الشهر'], ['year', 'السنة'], ['basicSalaryAtTime', 'الراتب الأساسي وقتها'], ['amount', 'المبلغ المخصوم فعليًا'], ['refundStatus', 'حالة الرد'], ['refundDate', 'تاريخ الرد الفعلي'], ['user', 'المستخدم'], ['registeredAt', 'تاريخ التسجيل'], ['type', 'نوع الخصم']] },
  Notifications: { sheet: 'التنبيهات والإشعارات', owner: 'NotificationService',
    fields: [['id', 'معرّف الإشعار'], ['type', 'النوع'], ['period', 'الشهر/الفترة'], ['title', 'العنوان'], ['message', 'النص'], ['contents', 'عناصر المحتوى'], ['showDate', 'تاريخ الإظهار'], ['showTime', 'وقت الإظهار'], ['scope', 'النطاق'], ['scopeValue', 'قيمة النطاق'], ['status', 'الحالة'], ['createdBy', 'أنشأه'], ['createdAt', 'تاريخ الإنشاء'], ['updatedAt', 'آخر تحديث'], ['durationMinutes', 'مدة الظهور (دقائق)'], ['reportFrom', 'فترة التقرير من'], ['reportTo', 'فترة التقرير إلى']] },
  NotificationTargets: { sheet: 'مستهدفو الإشعارات', owner: 'NotificationService',
    fields: [['id', 'معرّف الإشعار'], ['code', 'كود الموظف'], ['showAt', 'تاريخ ووقت الإظهار'], ['readAt', 'تاريخ القراءة'], ['hideAt', 'تاريخ الإخفاء']] },
  BackupRegistry: { sheet: 'سجل النسخ الاحتياطية', owner: 'BackupService',
    fields: [['id', 'رقم النسخة'], ['timestamp', 'التاريخ والوقت'], ['description', 'الوصف'], ['source', 'المصدر'], ['size', 'الحجم التقريبي'], ['fileId', 'Drive File ID'], ['user', 'المستخدم']] },
  AuditLog: { sheet: 'سجل النظام', owner: 'SystemService',
    fields: [['timestamp', 'التاريخ والوقت'], ['username', 'المستخدم'], ['action', 'الإجراء'], ['target', 'الهدف'], ['details', 'التفاصيل']] }
};
Object.keys(SCHEMA).forEach(n => { SCHEMA[n].keys = SCHEMA[n].fields.map(f => f[0]); SCHEMA[n].headers = SCHEMA[n].fields.map(f => f[1]); });

/* ===================================================================== */
/* 3. DATA ACCESS — the ONLY place that calls SpreadsheetApp             */
/* ===================================================================== */

const DA = (() => {
  let _ss = null; const _tables = {}; const _ext = {}; const stats = { reads: 0, writes: 0, extReads: 0, extWrites: 0 };
  function reset() { for (const k in _tables) delete _tables[k]; for (const k in _ext) delete _ext[k]; stats.reads = stats.writes = stats.extReads = stats.extWrites = 0; }
  /** The ONLY way the app reaches the database: the explicitly bound id. getActiveSpreadsheet() is never used. */
  function ss() { if (_ss) return _ss; const id = PropertiesService.getScriptProperties().getProperty(CFG.PROP_SSID); if (!id) fail(ERR.SERVER_ERROR, 'قاعدة البيانات مش مربوطة — GHIS_V2_SPREADSHEET_ID غير مضبوط. شغّل diagnoseV2DatabaseBinding() ثم bindV2Database("<spreadsheetId>").'); try { _ss = SpreadsheetApp.openById(id); } catch (e) { fail(ERR.SERVER_ERROR, 'تعذّر فتح قاعدة البيانات المربوطة (' + id + '): ' + (e && e.message || e)); } return _ss; }
  /** إنشاء شيت مُعرَّف في SCHEMA لو مش موجود — نفس مسار التهيئة، idempotent: لو موجود بيرجعه زي ما هو بصفر تعديل */
  function ensureSheet_(name) {
    const def = SCHEMA[name]; if (!def) fail(ERR.SERVER_ERROR, 'Unknown table: ' + name);
    const s2 = ss(); let sh = s2.getSheetByName(def.sheet); const isNew = !sh;
    if (!sh) sh = s2.insertSheet(def.sheet);
    if (sh.getLastRow() === 0) { sh.getRange(1, 1, 1, def.headers.length).setValues([def.headers]).setFontWeight('bold'); sh.setFrozenRows(1); if (def.seed && def.seed.length) sh.getRange(2, 1, def.seed.length, def.headers.length).setValues(def.seed); }
    return { sheet: sh, created: isNew };
  }
  // شيت ناقص في قاعدة مربوطة (اتضاف للـSCHEMA بعد التهيئة) = يتعمل بنفس مسار التهيئة بدل ما العملية تفشل بـMissing sheet
  function sheet(name) { const sh = ss().getSheetByName(SCHEMA[name].sheet); return sh || ensureSheet_(name).sheet; }
  function norm(v) { return (v instanceof Date) ? v.toISOString() : v; }
  function table(name) {
    if (_tables[name]) return _tables[name];
    const sh = sheet(name), keys = SCHEMA[name].keys, lastRow = sh.getLastRow();
    const values = lastRow > 1 ? sh.getRange(2, 1, lastRow - 1, keys.length).getValues() : []; stats.reads++;
    const rows = [];
    for (let i = 0; i < values.length; i++) { const o = {}; let empty = true; for (let c = 0; c < keys.length; c++) { const v = norm(values[i][c]); if (v !== '' && v !== null && v !== undefined) empty = false; o[keys[c]] = v; } if (!empty) { o._row = i + 2; rows.push(o); } }
    return (_tables[name] = { sheet: sh, keys, rows });
  }
  function match(row, where) { if (!where) return true; if (typeof where === 'function') return where(row); return Object.keys(where).every(k => String(row[k]) === String(where[k])); }
  function strip(r) { const o = {}; for (const k in r) if (k !== '_row') o[k] = r[k]; return o; }
  function readMany(name, opts) { opts = opts || {}; const t = table(name), out = []; for (const r of t.rows) { if (!match(r, opts.where)) continue; out.push(strip(r)); if (opts.limit && out.length >= opts.limit) break; } return out; }
  function readOne(name, where) { return readMany(name, { where, limit: 1 })[0] || null; }
  function count(name, where) { return table(name).rows.filter(r => match(r, where)).length; }
  function toRowArray(name, obj) { return SCHEMA[name].keys.map(k => (obj[k] === undefined || obj[k] === null) ? '' : obj[k]); }
  function insert(name, obj) { return batch([{ op: 'insert', table: name, data: obj }]).inserted; }
  function update(name, where, patch) { const t = table(name); let n = 0; for (const r of t.rows) { if (!match(r, where)) continue; Object.assign(r, patch); t.sheet.getRange(r._row, 1, 1, t.keys.length).setValues([toRowArray(name, r)]); stats.writes++; n++; } return n; }
  function remove(name, where) { const t = table(name); const rows = t.rows.filter(r => match(r, where)).map(r => r._row).sort((a, b) => b - a); rows.forEach(r => { t.sheet.deleteRow(r); stats.writes++; }); delete _tables[name]; return rows.length; }
  function batch(ops) {
    const result = { inserted: 0, updated: 0, deleted: 0 }; const insertsByTable = {};
    ops.forEach(o => { if (o.op === 'insert') (insertsByTable[o.table] = insertsByTable[o.table] || []).push(o.data); else if (o.op === 'update') result.updated += update(o.table, o.where, o.patch); else if (o.op === 'delete') result.deleted += remove(o.table, o.where); else fail(ERR.SERVER_ERROR, 'Unknown batch op'); });
    Object.keys(insertsByTable).forEach(name => { const sh = sheet(name); const rows = insertsByTable[name].map(d => toRowArray(name, d)); sh.getRange(sh.getLastRow() + 1, 1, rows.length, SCHEMA[name].keys.length).setValues(rows); stats.writes++; result.inserted += rows.length; delete _tables[name]; });
    return result;
  }
  /** Ensures the expected sheets exist INSIDE the already-bound Spreadsheet. Never creates a Spreadsheet. */
  function ensureSchema() {
    const id = PropertiesService.getScriptProperties().getProperty(CFG.PROP_SSID);
    if (!id) fail(ERR.SERVER_ERROR, 'GHIS_V2_SPREADSHEET_ID غير مضبوط. اربط قاعدة البيانات أولًا: bindV2Database("<spreadsheetId>") لقاعدة موجودة، أو createV2DatabaseExplicitly() لإنشاء قاعدة جديدة عن قصد.');
    const s = ss(); const report = { spreadsheetId: id, name: s.getName(), url: s.getUrl(), created: false, sheets: [] };
    Object.keys(SCHEMA).forEach(name => {
      const def = SCHEMA[name]; const r = ensureSheet_(name); const isNew = r.created;
      report.sheets.push({ sheet: def.sheet, owner: def.owner, created: isNew, columns: def.headers.length });
    });
    const dflt = s.getSheetByName('Sheet1'); if (dflt && s.getSheets().length > 1 && dflt.getLastRow() === 0 && dflt.getLastColumn() === 0) s.deleteSheet(dflt);   // only the untouched default sheet
    return report;
  }
  /* ---- external spreadsheet access (attendance/fingerprint file) — read-mostly, minimal writes, per-execution memo ---- */
  function extSheet(spreadsheetId, sheetName) { const k = spreadsheetId + '|' + sheetName; if (_ext[k]) return _ext[k]; const sh = SpreadsheetApp.openById(spreadsheetId).getSheetByName(sheetName); if (!sh) fail(ERR.NOT_FOUND, 'شيت "' + sheetName + '" مش موجود في ملف البصمة.'); return (_ext[k] = sh); }
  function extReadColumns(spreadsheetId, sheetName, nCols) { const sh = extSheet(spreadsheetId, sheetName); const last = sh.getLastRow(); stats.extReads++; return last < 2 ? [] : sh.getRange(2, 1, last - 1, nCols).getValues(); }
  function extAppendRow(spreadsheetId, sheetName, row) { extSheet(spreadsheetId, sheetName).appendRow(row); stats.extWrites++; }
  function extSetCell(spreadsheetId, sheetName, rowNumber, col, value) { extSheet(spreadsheetId, sheetName).getRange(rowNumber, col).setValue(value); stats.extWrites++; }
  function unbind() { _ss = null; reset(); }
  return { stats, reset, unbind, readMany, readOne, count, insert, update, remove, batch, ensureSchema, ensureSheet: ensureSheet_, extReadColumns, extAppendRow, extSetCell };
})();

/* ===================================================================== */
/* 4. CACHE + HELPERS                                                    */
/* ===================================================================== */

const Cache = {
  get(key) { const v = CacheService.getScriptCache().get(key); return v ? JSON.parse(v) : null; },
  put(key, obj, sec) { CacheService.getScriptCache().put(key, JSON.stringify(obj), sec || CFG.CACHE_SHARED_SEC); },
  remove(key) { CacheService.getScriptCache().remove(key); },
  memo(key, sec, fn) { const c = Cache.get(key); if (c) return c; const v = fn(); Cache.put(key, v, sec); return v; }
};
const U = {
  now() { return new Date().toISOString(); },
  yes(v) { return v === true || String(v).trim() === YES || String(v).toLowerCase() === 'true'; },
  yn(v) { return U.yes(v) ? YES : NO; },
  str(v) { return (v === undefined || v === null) ? '' : String(v).trim(); },
  hex(bytes) { return bytes.map(b => ('0' + (b & 255).toString(16)).slice(-2)).join(''); },
  hash(password, salt) { return U.hex(Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, salt + '::' + password, Utilities.Charset.UTF_8)); },
  salt() { return Utilities.getUuid().replace(/-/g, ''); },
  token() { return (Utilities.getUuid() + Utilities.getUuid()).replace(/-/g, ''); },
  uuid() { return Utilities.getUuid(); },
  parseJson(s, dflt) { try { return JSON.parse(s); } catch (e) { return dflt; } },
  dateStr(v) { v = U.str(v); if (!v) return ''; if (/^\d{4}-\d{2}-\d{2}$/.test(v)) return v; const d = new Date(v); return isNaN(d) ? '' : Utilities.formatDate(d, CFG.TZ, 'yyyy-MM-dd'); },
  isDate(v) { return /^\d{4}-\d{2}-\d{2}$/.test(U.str(v)) && !isNaN(new Date(v)); },
  today() { return Utilities.formatDate(new Date(), CFG.TZ, 'yyyy-MM-dd'); },
  stamp(v) { v = U.str(v); if (!v) return ''; const d = new Date(v); return isNaN(d) ? v : Utilities.formatDate(d, CFG.TZ, 'yyyy-MM-dd HH:mm'); },
  shiftDate(key, days) { const d = new Date(key + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + days); return d.toISOString().slice(0, 10); },
  timeText(v, dflt) { v = U.str(v); if (!v) return dflt; if (/^\d{1,2}:\d{2}$/.test(v)) return v; const d = new Date(v); return isNaN(d) ? dflt : Utilities.formatDate(d, CFG.TZ, 'HH:mm'); },
  id(prefix) { return prefix + Date.now().toString(36).toUpperCase() + Math.floor(Math.random() * 1296).toString(36).toUpperCase().padStart(2, '0'); },
  dataUrl(s) { const m = String(s || '').match(/^data:([^;]+);base64,(.+)$/); return m ? { mime: m[1], base64: m[2] } : null; },
  /** V1 notifNowStamp_ */
  notifNow() { return Utilities.formatDate(new Date(), CFG.TZ, 'yyyy-MM-dd HH:mm'); },
  /** V1 notifStamp_ — السبب الجذري: جوجل شيتس بيحوّل النص لـDate، والمقارنة النصية بتفشل. التطبيع إلزامي قبل أي مقارنة. */
  notifStamp(v) { if (v instanceof Date) return Utilities.formatDate(v, CFG.TZ, 'yyyy-MM-dd HH:mm'); const t = U.str(v); if (!t) return ''; const d = new Date(t); return isNaN(d) ? t : Utilities.formatDate(d, CFG.TZ, 'yyyy-MM-dd HH:mm'); },
  /** V1 buildNotifHideAt_ — نهاية نافذة الظهور = showAt + durationMinutes ('' = بدون انتهاء) */
  notifHideAt(dateStr, timeStr, durationMinutes) { const mins = Number(durationMinutes) || 0; if (mins <= 0) return ''; const base = new Date(U.dateStr(dateStr) + 'T' + U.timeText(timeStr, '00:00') + ':00'); if (isNaN(base)) return ''; base.setMinutes(base.getMinutes() + mins); return Utilities.formatDate(base, CFG.TZ, 'yyyy-MM-dd HH:mm'); },
  withLock(fn, ms) { const lock = LockService.getScriptLock(); lock.waitLock(ms || 15000); try { return fn(); } finally { lock.releaseLock(); } }
};

/* ===================================================================== */
/* 5. FILE STORE — the ONLY place that calls DriveApp                     */
/* ===================================================================== */

const FileStore = (() => {
  const PROP = 'GHIS_V2_FILES_FOLDER_ID', ROOT_NAME = 'GHIS V2 Files'; let _root = null;
  function root() { if (_root) return _root; const props = PropertiesService.getScriptProperties(); const id = props.getProperty(PROP); if (id) { try { return (_root = DriveApp.getFolderById(id)); } catch (e) { } } _root = DriveApp.createFolder(ROOT_NAME); props.setProperty(PROP, _root.getId()); return _root; }
  function folder(sub) { const it = root().getFoldersByName(sub); return it.hasNext() ? it.next() : root().createFolder(sub); }
  function saveBase64(sub, base64, mime, name) { assert(base64, 'الملف مطلوب'); const bytes = Utilities.base64Decode(base64); assert(bytes.length <= CFG.MAX_FILE_BYTES, 'حجم الملف يتجاوز 4MB'); const f = folder(sub).createFile(Utilities.newBlob(bytes, mime || 'application/octet-stream', name)); return { fileId: f.getId(), fileName: f.getName() }; }
  function getBase64(fileId) { try { const f = DriveApp.getFileById(fileId); const b = f.getBlob(); return { name: f.getName(), mimeType: b.getContentType(), base64: Utilities.base64Encode(b.getBytes()) }; } catch (e) { fail(ERR.NOT_FOUND, 'الملف غير موجود'); } }
  function trash(fileId) { if (!fileId) return; try { DriveApp.getFileById(fileId).setTrashed(true); } catch (e) { /* already gone */ } }
  function ping() { DriveApp.getRootFolder(); return true; }
  return { ping, saveBase64, getBase64, trash, FOLDER_REF_PHOTOS: 'صور مرجعية للتحقق من الهوية', FOLDER_PENDING_PHOTOS: 'الصور المرجعية المعلّقة', FOLDER_DOCS: 'مستندات موظفين GHIS', FOLDER_BACKUPS: 'النسخ الاحتياطية GHIS V2' };
})();

/* ===================================================================== */
/* 6. SERVICES                                                           */
/* ===================================================================== */

/* ---------- SettingsService (key/value «الإعدادات العامة», OLD 3-column model) ---------- */
const SettingsService = {
  KEY_AUTO_CODE: 'توليد كود الموظف تلقائيًا؟ (نعم/لا)',
  get(key, dflt) { const r = DA.readOne('Settings', { key }); return r ? U.str(r.value) : (dflt === undefined ? '' : dflt); },
  set(key, value, note) { const exists = DA.readOne('Settings', { key }); if (exists) DA.update('Settings', { key }, { value }); else DA.insert('Settings', { key, value, note: note || '' }); Cache.remove('settings:' + key); return { ok: true }; },
  autoCodeEnabled() { return Cache.memo('settings:' + this.KEY_AUTO_CODE, CFG.CACHE_SHARED_SEC, () => ({ v: this.get(this.KEY_AUTO_CODE, YES) !== NO })).v; },
  setAutoCode(payload, ctx) { assert(ctx.session.isAdmin, 'تغيير إعداد توليد الأكواد للأدمن بس.'); this.set(this.KEY_AUTO_CODE, U.yes(payload.enabled) ? YES : NO, SCHEMA.Settings.seed[0][2]); SystemService.audit(ctx, 'settings.autoCode', '', U.yn(payload.enabled)); return { ok: true, autoGenerate: U.yes(payload.enabled) }; }
};

/* ---------- NameListService («إدارة الأقسام» / «إدارة المسميات الوظيفية») ---------- */
const NameListService = {
  KINDS: { dept: 'قسم', jobTitle: 'مسمى وظيفي' },
  _norm(x) { return U.str(x).replace(/\s+/g, ' '); },
  all() { return Cache.memo('nameLists', CFG.CACHE_SHARED_SEC, () => { const rows = DA.readMany('NameLists'); const pick = k => rows.filter(r => U.str(r.kind) === k).map(r => ({ name: U.str(r.name), active: U.yes(r.active) })).sort((a, b) => a.name.localeCompare(b.name, 'ar')); return { departments: pick('قسم'), jobTitles: pick('مسمى وظيفي') }; }); },
  /** op: add | rename | setActive — never deletes; employee data never touched (same as OLD manageNamedList_) */
  manage(payload, ctx) {
    const kind = this.KINDS[U.str(payload.kind)]; assert(kind, 'نوع القائمة غير معروف.');
    const label = kind === 'قسم' ? 'القسم' : 'المسمى الوظيفي'; const nm = U.str(payload.name); assert(nm, 'الاسم مطلوب.');
    const rows = DA.readMany('NameLists', { where: { kind } }); const has = x => rows.some(r => this._norm(r.name) === this._norm(x)); const op = U.str(payload.op);
    let out;
    if (op === 'add') { assert(!has(nm), label + ' «' + nm + '» موجود بالفعل.'); DA.insert('NameLists', { kind, name: nm, active: YES, createdAt: U.now() }); out = { ok: true, name: nm }; }
    else if (op === 'rename') { const nn = U.str(payload.newName); assert(nn, 'الاسم الجديد مطلوب.'); assert(this._norm(nn) === this._norm(nm) || !has(nn), label + ' «' + nn + '» موجود بالفعل.'); assert(DA.update('NameLists', r => U.str(r.kind) === kind && U.str(r.name) === nm, { name: nn }) > 0, label + ' «' + nm + '» مش موجود.'); out = { ok: true, name: nn, note: 'بيانات الموظفين الحالية مالهاش تغيير.' }; }
    else if (op === 'setActive') { const val = U.yn(payload.active); assert(DA.update('NameLists', r => U.str(r.kind) === kind && U.str(r.name) === nm, { active: val }) > 0, label + ' «' + nm + '» مش موجود.'); out = { ok: true, name: nm, active: val === YES }; }
    else fail(ERR.VALIDATION_ERROR, 'عملية غير معروفة.');
    Cache.remove('nameLists'); SystemService.audit(ctx, 'nameList.' + op, kind + ':' + nm, U.str(payload.newName || payload.active));
    return out;
  }
};

/* ---------- ExemptDepartmentService («الأقسام المعفاة من رصد البصمة») ---------- */
const ExemptDepartmentService = {
  activeSet() { return Cache.memo('exemptDepts', CFG.CACHE_SHARED_SEC, () => { const set = {}; DA.readMany('ExemptDepartments').forEach(r => { if (U.yes(r.active) && U.str(r.dept)) set[U.str(r.dept)] = true; }); return set; }); },
  list() { return { items: DA.readMany('ExemptDepartments').map(r => ({ dept: U.str(r.dept), active: U.yes(r.active), createdAt: U.stamp(r.createdAt) })) }; },
  add(payload, ctx) { const dept = U.str(payload.dept); assert(dept, 'اسم القسم مطلوب.'); const ex = DA.readOne('ExemptDepartments', { dept }); if (ex) DA.update('ExemptDepartments', { dept }, { active: YES }); else DA.insert('ExemptDepartments', { dept, active: YES, createdAt: U.now() }); Cache.remove('exemptDepts'); SystemService.audit(ctx, 'exemptDept.add', dept, ''); return { ok: true }; },
  remove(payload, ctx) { const dept = U.str(payload.dept); assert(DA.update('ExemptDepartments', { dept }, { active: NO }) > 0, 'القسم مش موجود في القائمة.'); Cache.remove('exemptDepts'); SystemService.audit(ctx, 'exemptDept.remove', dept, ''); return { ok: true }; }
};

/* ---------- RoleService (مستويات الصلاحيات + مصفوفة الصلاحيات) ---------- */
const RoleService = {
  _all() { return Cache.memo('roles:all', CFG.CACHE_SHARED_SEC, () => DA.readMany('Roles').map(r => ({ name: U.str(r.name), sections: U.str(r.sections).split(',').map(s => s.trim()).filter(Boolean), isAdmin: U.yes(r.isAdmin), active: U.yes(r.active) }))); },
  find(name) { return this._all().find(r => r.name === name) || null; },
  list() { return { roles: this._all(), sectionCatalog: SECTION_KEYS_.map(k => ({ key: k, label: SECTION_LABELS_[k] })), registry: MODULE_ACTION_REGISTRY_ }; },
  save(payload, ctx) {
    const name = U.str(payload.name); assert(name, 'اسم الصلاحية مطلوب.');
    const sections = (Array.isArray(payload.sections) ? payload.sections : U.str(payload.sections).split(',')).map(s => U.str(s)).filter(s => SECTION_KEYS_.includes(s));
    const data = { name, sections: sections.join(','), isAdmin: U.yn(payload.isAdmin), active: payload.active === undefined ? YES : U.yn(payload.active) };
    if (DA.readOne('Roles', { name })) DA.update('Roles', { name }, data); else DA.insert('Roles', data);
    Cache.remove('roles:all'); SystemService.audit(ctx, 'role.save', name, data.sections); return { ok: true };
  },
  toggle(payload, ctx) { const name = U.str(payload.name); assert(DA.update('Roles', { name }, { active: U.yn(payload.active) }) > 0, 'الصلاحية مش موجودة: ' + name); Cache.remove('roles:all'); SystemService.audit(ctx, 'role.toggle', name, U.yn(payload.active)); return { ok: true }; },
  /* --- Matrix (Module × View/Create/Update/Delete/Export/Upload/Restore) — same registry, validation and flattening as OLD --- */
  matrixRows(roleName) { const map = {}; DA.readMany('PermissionsMatrix', { where: { role: roleName } }).forEach(r => { map[U.str(r.module)] = { module: U.str(r.module), view: U.yes(r.view), create: U.yes(r.create), update: U.yes(r.update), delete: U.yes(r.delete), export: U.yes(r.export), upload: U.yes(r.upload), restore: U.yes(r.restore) }; }); const rows = Object.values(map); return rows.length ? rows : null; },
  derivePreview(sections) { return MODULE_ACTION_REGISTRY_.map(e => { const row = { module: e.module, label: e.label }; ['view', 'create', 'update', 'delete', 'export'].forEach(a => { row[a] = e.actions[a] ? sections.includes(e.actions[a]) : false; }); if (e.special) Object.keys(e.special).forEach(sa => { row[sa] = sections.includes(e.special[sa]); }); return row; }); },
  flatten(matrixRows) { const keys = new Set(); matrixRows.forEach(row => { const e = MODULE_ACTION_REGISTRY_.find(m => m.module === row.module); if (!e) return; ['view', 'create', 'update', 'delete', 'export'].forEach(a => { if (row[a] && e.actions[a]) keys.add(e.actions[a]); }); if (e.special) Object.keys(e.special).forEach(sa => { if (row[sa]) keys.add(e.special[sa]); }); }); return [...keys]; },
  validateMatrix(matrixRows) {
    matrixRows.forEach(row => { const e = MODULE_ACTION_REGISTRY_.find(m => m.module === row.module); assert(e, 'Module غير معروف: ' + row.module);
      ['view', 'create', 'update', 'delete', 'export'].forEach(a => assert(!(row[a] && !e.actions[a]), 'Action "' + a + '" غير متاحة لـ' + e.label));
      Object.keys(row).forEach(k => { if (['view', 'create', 'update', 'delete', 'export', 'module', 'label'].includes(k)) return; assert(!(row[k] && !(e.special && e.special[k])), 'Action "' + k + '" غير متاحة لـ' + e.label); });
      e.sharedGroups.forEach(g => { const vals = g.actions.map(a => !!row[a]); assert(vals.every(v => v === vals[0]), 'تعارض في مجموعة صلاحيات مشتركة (' + g.key + ') لـ' + e.label + ' — الأكشنز دي بتستخدم نفس المفتاح فعليًا، لازم يبقوا بنفس القيمة.'); }); });
  },
  matrixGet(payload) { const role = this.find(U.str(payload.roleName)); assert(role, 'الصلاحية "' + U.str(payload.roleName) + '" مش موجودة.'); const saved = this.matrixRows(role.name); return { roleName: role.name, isAdmin: role.isAdmin, source: saved ? 'matrix' : 'legacy', matrix: saved ? saved.map(r => Object.assign({ label: (MODULE_ACTION_REGISTRY_.find(m => m.module === r.module) || {}).label }, r)) : this.derivePreview(role.sections), registry: MODULE_ACTION_REGISTRY_ }; },
  matrixSave(payload, ctx) {
    const role = this.find(U.str(payload.roleName)); assert(role, 'الصلاحية مش موجودة.'); const rows = Array.isArray(payload.matrixRows) ? payload.matrixRows : []; this.validateMatrix(rows);
    const ops = rows.map(r => { const data = { role: role.name, module: r.module, view: U.yn(r.view), create: U.yn(r.create), update: U.yn(r.update), delete: U.yn(r.delete), export: U.yn(r.export), upload: U.yn(r.upload), restore: U.yn(r.restore) }; return DA.readOne('PermissionsMatrix', { role: role.name, module: r.module }) ? { op: 'update', table: 'PermissionsMatrix', where: { role: role.name, module: r.module }, patch: data } : { op: 'insert', table: 'PermissionsMatrix', data }; });
    if (ops.length) DA.batch(ops); Cache.remove('roles:all'); SystemService.audit(ctx, 'matrix.save', role.name, rows.length + ' modules'); return { ok: true, saved: rows.length };
  },
  /** effective sections = role sections ∪ matrix keys (OLD getEffectiveRoleSections_) */
  effectiveSections(role) { const m = this.matrixRows(role.name); const set = new Set(role.sections); if (m) this.flatten(m).forEach(k => set.add(k)); return [...set]; }
};

/* ---------- UserService (مستخدمو النظام) ---------- */
const UserService = {
  _public(u) { return { username: U.str(u.username), role: U.str(u.role), active: U.yes(u.active), createdAt: U.stamp(u.createdAt), lastLoginAt: U.stamp(u.lastLoginAt) }; },
  list() { return { users: DA.readMany('Users').map(this._public), roles: RoleService._all() }; },
  add(payload, ctx) { const username = U.str(payload.username), pw = U.str(payload.password), role = U.str(payload.role); assert(username, 'اسم المستخدم مطلوب.'); assert(pw.length >= 6, 'كلمة المرور 6 حروف على الأقل.'); assert(RoleService.find(role), 'الصلاحية مش موجودة.'); assert(!DA.readOne('Users', { username }), 'اسم المستخدم موجود بالفعل.'); const salt = U.salt(); DA.insert('Users', { username, passwordHash: U.hash(pw, salt), salt, role, active: YES, createdAt: U.now(), lastLoginAt: '' }); SystemService.audit(ctx, 'user.add', username, role); return { ok: true }; },
  toggle(payload, ctx) { const username = U.str(payload.username); assert(DA.update('Users', { username }, { active: U.yn(payload.active) }) > 0, 'المستخدم مش موجود.'); SystemService.audit(ctx, 'user.toggle', username, U.yn(payload.active)); return { ok: true }; },
  adminResetPassword(payload, ctx) { const username = U.str(payload.username), pw = U.str(payload.newPassword); assert(pw.length >= 6, 'كلمة المرور 6 حروف على الأقل.'); const salt = U.salt(); assert(DA.update('Users', { username }, { salt, passwordHash: U.hash(pw, salt) }) > 0, 'المستخدم مش موجود.'); SystemService.audit(ctx, 'user.resetPassword', username, ''); return { ok: true }; },
  changeOwnPassword(payload, ctx) { const cur = U.str(payload.currentPassword), next = U.str(payload.newPassword); assert(next.length >= 6, 'كلمة المرور الجديدة 6 حروف على الأقل.'); const u = DA.readOne('Users', { username: ctx.session.username }); if (!u || U.hash(cur, U.str(u.salt)) !== U.str(u.passwordHash)) fail(ERR.AUTH_ERROR, 'كلمة المرور الحالية غير صحيحة'); const salt = U.salt(); DA.update('Users', { username: u.username }, { salt, passwordHash: U.hash(next, salt) }); SystemService.audit(ctx, 'user.password', u.username, ''); return { changed: true }; }
};

/* ---------- AuthService (login = auth + session + bootstrap; OLD permission semantics) ---------- */
const AuthService = {
  _key(t) { return 'sess:' + t; },
  login(payload) {
    const username = U.str(payload.username), password = U.str(payload.password); assert(username && password, 'اكتب اسم المستخدم وكلمة المرور.');
    const u = DA.readOne('Users', r => U.str(r.username) === username); if (!u) fail(ERR.AUTH_ERROR, 'اسم المستخدم أو كلمة المرور غلط.');
    if (!U.yes(u.active)) fail(ERR.AUTH_ERROR, 'الحساب ده متوقف — كلم الأدمن.');
    if (U.hash(password, U.str(u.salt)) !== U.str(u.passwordHash)) fail(ERR.AUTH_ERROR, 'اسم المستخدم أو كلمة المرور غلط.');
    const role = RoleService.find(U.str(u.role)); if (!role || !role.active) fail(ERR.AUTH_ERROR, 'الصلاحية بتاعة الحساب ده متوقفة — كلم الأدمن.');
    const now = new Date();
    const session = { token: U.token(), username: u.username, role: role.name, sections: RoleService.effectiveSections(role), isAdmin: role.isAdmin, createdAt: now.toISOString(), expiresAt: new Date(now.getTime() + CFG.SESSION_TTL_HOURS * 3600000).toISOString() };
    Cache.put(this._key(session.token), session, CFG.CACHE_SESSION_SEC);
    DA.batch([{ op: 'insert', table: 'Sessions', data: { token: session.token, username: session.username, role: session.role, createdAt: session.createdAt, expiresAt: session.expiresAt } },
      { op: 'update', table: 'Users', where: { username: u.username }, patch: { lastLoginAt: session.createdAt } },
      { op: 'insert', table: 'AuditLog', data: { timestamp: session.createdAt, username: session.username, action: 'auth.login', target: '', details: '' } }]);
    return Object.assign({ session: { token: session.token, expiresAt: session.expiresAt } }, AppService.bootstrap(session));
  },
  resolve(token) {
    token = U.str(token); if (!token) fail(ERR.AUTH_ERROR, 'لازم تسجّل دخول الأول.');
    let s = Cache.get(this._key(token));
    if (!s) { const row = DA.readOne('Sessions', { token }); if (!row) fail(ERR.SESSION_EXPIRED, 'انتهت صلاحية الجلسة — سجّل دخول تاني.'); const role = RoleService.find(U.str(row.role)); if (!role || !role.active) fail(ERR.SESSION_EXPIRED, 'الصلاحية بتاعة الحساب ده متوقفة — سجّل دخول تاني.'); s = { token, username: U.str(row.username), role: role.name, sections: RoleService.effectiveSections(role), isAdmin: role.isAdmin, createdAt: row.createdAt, expiresAt: row.expiresAt }; }
    if (!s.expiresAt || Date.now() >= Date.parse(s.expiresAt)) { Cache.remove(this._key(token)); fail(ERR.SESSION_EXPIRED, 'انتهت صلاحية الجلسة — سجّل دخول تاني.'); }
    Cache.put(this._key(token), s, CFG.CACHE_SESSION_SEC); return s;
  },
  /** OLD checkPermission_: admin → all; direct key; or any coarse key that implies it */
  can(session, key) { if (!key) return true; if (session.isAdmin) return true; if (session.sections.includes(key)) return true; for (const coarse in COARSE_IMPLIES_) if (COARSE_IMPLIES_[coarse].includes(key) && session.sections.includes(coarse)) return true; return false; },
  logout(payload, ctx) { Cache.remove(this._key(ctx.session.token)); DA.update('Sessions', { token: ctx.session.token }, { expiresAt: new Date(Date.now() - 1000).toISOString() }); return { loggedOut: true }; }
};

/* ---------- AppService (bootstrap: user + sections + small lists only) ---------- */
const AppService = {
  bootstrap(session) { return { version: CFG.VERSION, serverTime: U.now(), user: { username: session.username, role: session.role, isAdmin: session.isAdmin, expiresAt: session.expiresAt }, sections: session.sections, nameLists: NameListService.all(), settings: { autoEmployeeCode: SettingsService.autoCodeEnabled() } }; }
};

/* ---------- EmployeeService («بيانات الموظفين» — 17 OLD fields, computed status, auto code, field-level history, sync) ---------- */
const EMP = {
  FIELDS: ['nameEn', 'nameAr', 'dept', 'hireDate', 'endDate', 'exemptManual', 'jobTitle', 'nationality', 'phone', 'paymentMethod', 'receivedDocumentsAfterEnd', 'summerDeduction', 'firstYearDeduction', 'firstRaiseMonth', 'firstYearSummerDeduction'],
  DATE: ['hireDate', 'endDate'], YESNO: ['exemptManual', 'receivedDocumentsAfterEnd'],
  SUMMER: ['', 'ربع', 'ربعين'], FIRST_YEAR_SUMMER: ['', '1', '2', '3', '4']
};
const EmployeeService = {
  _shape(r, exemptSet) {
    const e = { code: U.str(r.code), nameEn: U.str(r.nameEn), nameAr: U.str(r.nameAr), dept: U.str(r.dept), hireDate: U.dateStr(r.hireDate), endDate: U.dateStr(r.endDate), exemptManual: U.yes(r.exemptManual), jobTitle: U.str(r.jobTitle), nationality: U.str(r.nationality), phone: U.str(r.phone), paymentMethod: U.str(r.paymentMethod), receivedDocumentsAfterEnd: U.yes(r.receivedDocumentsAfterEnd), hasReferencePhoto: !!U.str(r.referencePhotoFileId), summerDeduction: U.str(r.summerDeduction), firstYearDeduction: U.str(r.firstYearDeduction), firstRaiseMonth: U.str(r.firstRaiseMonth).slice(0, 7), firstYearSummerDeduction: U.str(r.firstYearSummerDeduction) };
    e.status = e.endDate ? 'منتهي الخدمة' : 'نشط';                       // OLD computeEmployeeComputedFields_
    e.exempt = e.exemptManual || !!(exemptSet || {})[e.dept];              // معفى من البصمة (نهائي)
    return e;
  },
  _exists(code) { const r = DA.readOne('Employees', { code }); if (!r) fail(ERR.NOT_FOUND, 'كود الموظف مش موجود: ' + code); return r; },
  /** ONE read of Employees (+ cached exempt departments). Labels come from bootstrap nameLists. */
  list() { const ex = ExemptDepartmentService.activeSet(); return { employees: DA.readMany('Employees').map(r => this._shape(r, ex)) }; },
  get(payload) { return { employee: this._shape(this._exists(U.str(payload.code)), ExemptDepartmentService.activeSet()) }; },
  _clean(raw) { const d = {}; EMP.FIELDS.forEach(k => { let v = U.str(raw[k]); if (EMP.YESNO.includes(k)) v = U.yn(raw[k] === true || v === YES); if (EMP.DATE.includes(k)) { assert(!v || U.isDate(v), 'صيغة التاريخ لازم تكون yyyy-mm-dd: ' + v); } d[k] = v; }); assert(EMP.SUMMER.includes(d.summerDeduction), 'خصم الصيف: القيمة لازم تكون «ربع» أو «ربعين» أو فاضية.'); assert(EMP.FIRST_YEAR_SUMMER.includes(d.firstYearSummerDeduction), 'استثناء خصم السنة الأولى غير صالح.'); assert(!d.firstRaiseMonth || /^\d{4}-\d{2}$/.test(d.firstRaiseMonth), 'شهر أول علاوة مستحقة لازم يكون yyyy-MM.'); return d; },
  _nextNumericCode(rows) { let max = 0; rows.forEach(r => { const raw = U.str(r.code); if (/^\d+$/.test(raw)) { const n = parseInt(raw, 10); if (n > max) max = n; } }); return String(max + 1); },
  history(payload) { const code = U.str(payload.code); assert(code, 'كود الموظف مطلوب'); return { history: DA.readMany('EmployeeHistory', { where: { code } }).map(h => ({ field: U.str(h.field), oldValue: U.str(h.oldValue), newValue: U.str(h.newValue), reason: U.str(h.reason), user: U.str(h.user), timestamp: U.stamp(h.timestamp), operationId: U.str(h.operationId) })).reverse() }; },
  _log(code, field, oldValue, newValue, reason, user, opId) { return { op: 'insert', table: 'EmployeeHistory', data: { code, field, oldValue: U.str(oldValue), newValue: U.str(newValue), reason: U.str(reason), user: U.str(user), timestamp: U.now(), operationId: opId || '' } }; },
  /** OLD addEmployee_: auto code (MAX numeric + 1, under a short lock) or manual; duplicate check in both cases; history + sync */
  add(payload, ctx) {
    const data = this._clean(payload.data || {}); assert(data.nameAr, 'الاسم بالعربي مطلوب.'); const auto = SettingsService.autoCodeEnabled();
    return U.withLock(() => {
      const rows = DA.readMany('Employees'); let code;
      if (auto) code = this._nextNumericCode(rows); else { code = U.str((payload.data || {}).code); assert(code, 'كود الموظف مطلوب.'); }
      assert(!rows.some(r => U.str(r.code) === code), 'كود الموظف ده موجود بالفعل: ' + code);
      DA.batch([{ op: 'insert', table: 'Employees', data: Object.assign({ code, referencePhotoFileId: '' }, data) }, this._log(code, 'إضافة موظف جديد', '', 'تم الإنشاء' + (auto ? ' (كود تلقائي)' : ' (كود يدوي)'), (payload.data || {}).reason, ctx.session.username, U.uuid())]);
      AttendanceService.syncEmployee(code, data.nameAr, ctx.session.username);   // never throws
      return { code, employee: this._shape(DA.readOne('Employees', { code }), ExemptDepartmentService.activeSet()), syncStatus: AttendanceService.statuses().statuses[code] || 'Pending' };
    }, 15000);
  },
  /** OLD updateEmployee_: one operationId per call; a history row per changed field; sync after any change */
  update(payload, ctx) {
    const code = U.str(payload.code); const old = this._exists(code); const changes = payload.changes || {}; const reason = U.str(payload.reason); const opId = U.uuid();
    const patch = {}, ops = [];
    EMP.FIELDS.forEach(k => { if (!(k in changes)) return; const cleaned = this._clean(Object.assign({}, old, changes))[k]; const oldVal = EMP.DATE.includes(k) ? U.dateStr(old[k]) : (EMP.YESNO.includes(k) ? U.yn(old[k]) : U.str(old[k])); if (String(oldVal) === String(cleaned)) return; patch[k] = cleaned; ops.push(this._log(code, k, oldVal, cleaned, reason, ctx.session.username, opId)); });
    if (ops.length) { ops.unshift({ op: 'update', table: 'Employees', where: { code }, patch }); DA.batch(ops); }
    if (patch.nameAr !== undefined) AttendanceService.syncEmployee(code, U.str(patch.nameAr), ctx.session.username);   // attendance holds code + Arabic name only → sync only when the name changed
    return { code, changedCount: ops.length ? ops.length - 1 : 0, employee: this._shape(DA.readOne('Employees', { code }), ExemptDepartmentService.activeSet()), syncStatus: AttendanceService.statuses().statuses[code] || 'Pending' };
  },
  /** OLD bulkAddEmployees_: ≤200 rows, one read, sequential MAX+1 for blank codes, one write, history + sync per employee */
  bulkAdd(payload, ctx) {
    const rows = Array.isArray(payload.rows) ? payload.rows : []; assert(rows.length, 'مفيش صفوف للاستيراد.'); assert(rows.length <= CFG.BULK_MAX_ROWS, 'الحد الأقصى ' + CFG.BULK_MAX_ROWS + ' صف في الدفعة الواحدة.');
    return U.withLock(() => {
      const existing = {}; let maxCode = 0; DA.readMany('Employees').forEach(r => { const raw = U.str(r.code); if (!raw) return; existing[raw] = true; if (/^\d+$/.test(raw)) maxCode = Math.max(maxCode, parseInt(raw, 10)); });
      const seen = {}, ops = [], names = [], report = [];
      rows.forEach((r, idx) => {
        const rowNo = Number(r.__row) || (idx + 2); const given = U.str(r.code); let code;
        if (given) { if (existing[given]) { report.push({ row: rowNo, name: r.nameAr || '', ok: false, error: 'كود الموظف ' + given + ' مستخدم بالفعل لموظف حالي.' }); return; } if (seen[given]) { report.push({ row: rowNo, name: r.nameAr || '', ok: false, error: 'كود الموظف ' + given + ' مكرر داخل الملف.' }); return; } code = given; }
        else { maxCode += 1; code = String(maxCode); while (existing[code] || seen[code]) { maxCode += 1; code = String(maxCode); } }
        let data; try { data = this._clean(r); } catch (e) { report.push({ row: rowNo, name: r.nameAr || '', ok: false, error: e.message }); return; }
        if (!data.nameAr) { report.push({ row: rowNo, name: '', ok: false, error: 'الاسم بالعربي مطلوب.' }); return; }
        seen[code] = true; ops.push({ op: 'insert', table: 'Employees', data: Object.assign({ code, referencePhotoFileId: '' }, data) }); ops.push(this._log(code, 'إضافة موظف جديد', '', 'تم الإنشاء (استيراد جماعي)', 'استيراد Excel', ctx.session.username, U.uuid())); names.push({ code, nameAr: data.nameAr }); report.push({ row: rowNo, name: data.nameAr, ok: true, code });
      });
      if (ops.length) DA.batch(ops);
      names.forEach(x => AttendanceService.syncEmployee(x.code, x.nameAr, ctx.session.username));
      return { added: names.length, failed: report.filter(x => !x.ok).length, report };
    }, 30000);
  }
};

/* ---------- AttendanceService — the ONLY integration layer with the attendance/fingerprint file (Employee Code is the key) ---------- */
const AttendanceService = {
  attId() { return U.str(PropertiesService.getScriptProperties().getProperty(CFG.PROP_ATT_SSID)); },
  _log(code, nameAr, result, details) { try { DA.insert('SyncLog', { code, nameAr, result, details: U.str(details), timestamp: U.now() }); } catch (e) { /* never block */ } },
  /** OLD syncEmployeeToAttendanceSheet_: idempotent (code + Arabic name only); any failure is logged, never thrown */
  syncEmployee(code, nameAr, user) {
    try {
      const id = this.attId(); if (!id) { this._log(code, nameAr, 'فشل', 'ملف البصمة غير مضبوط (ATTENDANCE_SPREADSHEET_ID).'); return; }
      const rows = DA.extReadColumns(id, CFG.ATT_EMPLOYEES_SHEET, 2); let found = -1; for (let i = 0; i < rows.length; i++) if (U.str(rows[i][0]) === code) { found = i + 2; break; }
      if (found === -1) { DA.extAppendRow(id, CFG.ATT_EMPLOYEES_SHEET, [code, nameAr]); this._log(code, nameAr, 'نجح', 'موظف جديد اتضاف لشيت البصمة.'); }
      else { const cur = U.str(rows[found - 2][1]); if (cur !== nameAr) { DA.extSetCell(id, CFG.ATT_EMPLOYEES_SHEET, found, 2, nameAr); this._log(code, nameAr, 'نجح', 'الاسم اتحدّث (كان: "' + cur + '").'); } else this._log(code, nameAr, 'نجح', 'مفيش فرق — الصف موجود ومطابق بالفعل (Idempotent).'); }
    } catch (err) { this._log(code, nameAr, 'فشل', String(err && err.message || err)); }
  },
  /** ONE read of the sync log → latest result per code (OLD getAllSyncStatuses_) */
  statuses() { const latest = {}; DA.readMany('SyncLog').forEach(r => { const code = U.str(r.code); if (!code) return; const ts = Date.parse(r.timestamp) || 0; if (!latest[code] || ts >= latest[code].ts) latest[code] = { result: U.str(r.result), ts }; }); const out = {}; Object.keys(latest).forEach(c => { out[c] = latest[c].result === 'نجح' ? 'Synced' : 'Sync Failed'; }); return { statuses: out }; },
  retry(payload, ctx) { const code = U.str(payload.code); const emp = EmployeeService._exists(code); this.syncEmployee(code, U.str(emp.nameAr), ctx.session.username); const st = this.statuses().statuses[code]; return { ok: true, status: st || 'Pending' }; },
  /** OLD adminResetEmployeePassword_: reset the attendance-app password to the default; logged in employee history */
  resetEmployeePassword(payload, ctx) {
    const code = U.str(payload.code); assert(code, 'كود الموظف مطلوب.'); const id = this.attId(); assert(id, 'ملف البصمة غير مضبوط (ATTENDANCE_SPREADSHEET_ID).');
    const rows = DA.extReadColumns(id, CFG.ATT_PASSWORDS_SHEET, 1); let rowNum = -1; for (let i = 0; i < rows.length; i++) if (U.str(rows[i][0]) === code) { rowNum = i + 2; break; }
    if (rowNum === -1) fail(ERR.NOT_FOUND, 'الموظف ده لسه ما سجّلش دخول ولا مرة — كلمة المرور الافتراضية شغالة معاه بالفعل.');
    const salt = U.salt(); const hash = U.hex(Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, salt + CFG.ATT_DEFAULT_PASSWORD, Utilities.Charset.UTF_8));
    DA.extSetCell(id, CFG.ATT_PASSWORDS_SHEET, rowNum, 2, salt + ':' + hash); DA.extSetCell(id, CFG.ATT_PASSWORDS_SHEET, rowNum, 3, new Date());
    DA.insert('EmployeeHistory', { code, field: 'password', oldValue: '', newValue: '', reason: 'إعادة تعيين كلمة المرور للافتراضي', user: ctx.session.username, timestamp: U.now(), operationId: U.uuid() });
    return { ok: true };
  }
};

/* ---------- ScheduleOverrideService («مواعيد خاصة لموظفين» — same auto-segmentation as OLD addScheduleOverride_) ---------- */
const ScheduleOverrideService = {
  _shape(r) { return { code: U.str(r.code), from: U.dateStr(r.from), to: U.dateStr(r.to), checkIn: U.timeText(r.checkIn, '08:00'), checkOut: U.timeText(r.checkOut, '15:00'), user: U.str(r.user), createdAt: U.stamp(r.createdAt) }; },
  listForEmployee(payload) { const code = U.str(payload.code); assert(code, 'كود الموظف مطلوب.'); return { overrides: DA.readMany('ScheduleOverrides', { where: { code } }).map(this._shape).filter(o => o.from && o.to).sort((a, b) => a.from.localeCompare(b.from)) }; },
  add(payload, ctx) {
    const code = U.str(payload.code); assert(code, 'كود الموظف مطلوب.'); EmployeeService._exists(code);
    const from = U.dateStr(payload.from), to = U.dateStr(payload.to); assert(U.isDate(from) && U.isDate(to), 'لازم تحدد من تاريخ ولحد تاريخ صحيحين.'); assert(from <= to, '«من تاريخ» لازم يكون قبل أو يساوي «لحد تاريخ».');
    const checkIn = U.timeText(payload.checkIn, ''), checkOut = U.timeText(payload.checkOut, ''); assert(checkIn && checkOut, 'لازم تحدد موعد الحضور والانصراف.');
    return U.withLock(() => {
      const ops = []; let trimmed = 0, removed = 0, split = 0;
      DA.readMany('ScheduleOverrides', { where: { code } }).map(this._shape).forEach(ex => {
        if (!ex.from || !ex.to || from > ex.to || to < ex.from) return; const keepHead = ex.from < from, keepTail = ex.to > to;
        const where = r => U.str(r.code) === code && U.dateStr(r.from) === ex.from && U.dateStr(r.to) === ex.to;
        if (keepHead && keepTail) { ops.push({ op: 'update', table: 'ScheduleOverrides', where, patch: { to: U.shiftDate(from, -1) } }); ops.push({ op: 'insert', table: 'ScheduleOverrides', data: { code, from: U.shiftDate(to, 1), to: ex.to, checkIn: ex.checkIn, checkOut: ex.checkOut, user: ex.user, createdAt: ex.createdAt } }); trimmed++; split++; }
        else if (keepHead) { ops.push({ op: 'update', table: 'ScheduleOverrides', where, patch: { to: U.shiftDate(from, -1) } }); trimmed++; }
        else if (keepTail) { ops.push({ op: 'update', table: 'ScheduleOverrides', where, patch: { from: U.shiftDate(to, 1) } }); trimmed++; }
        else { ops.push({ op: 'delete', table: 'ScheduleOverrides', where }); removed++; }
      });
      ops.push({ op: 'insert', table: 'ScheduleOverrides', data: { code, from, to, checkIn, checkOut, user: ctx.session.username, createdAt: U.now() } });
      DA.batch(ops); SystemService.audit(ctx, 'scheduleOverride.add', code, from + '→' + to);
      return Object.assign({ ok: true, trimmed, removed, split }, this.listForEmployee({ code }));
    }, 30000);
  }
};

/* ---------- SalaryService (Phase 1 scope: «الراتب الأساسي» from the employee profile — OLD salaryTransactions/salaryTransactionAdd) ---------- */
const SalaryService = {
  BASIC: 'الراتب الأساسي',
  types() { return Cache.memo('salaryTxTypes', CFG.CACHE_SHARED_SEC, () => DA.readMany('SalaryTxTypes').map(t => ({ name: U.str(t.name), kind: U.str(t.kind), active: U.yes(t.active), category: U.str(t.category) }))); },
  _shape(t) { return { code: U.str(t.code), type: U.str(t.type), from: U.dateStr(t.from), to: U.dateStr(t.to), value: Number(t.value) || 0, reason: U.str(t.reason), createdAt: U.stamp(t.createdAt), user: U.str(t.user), txId: U.str(t.txId) }; },
  transactionsForEmployee(payload) { const code = U.str(payload.code); assert(code, 'كود الموظف مطلوب.'); return { transactions: DA.readMany('SalaryTransactions', { where: { code } }).map(this._shape) }; },
  add(payload, ctx) {
    const d = payload.data || {}; const code = U.str(d.code), type = U.str(d.type); assert(code, 'كود الموظف مطلوب.'); assert(type, 'نوع الحركة مطلوب.'); EmployeeService._exists(code);
    const def = this.types().find(t => t.name === type); assert(def, 'نوع الحركة ده مش موجود في قائمة الأنواع: ' + type); assert(def.active, 'نوع الحركة ده متوقف حاليًا: ' + type);
    const from = U.dateStr(d.from); assert(U.isDate(from), 'تاريخ البداية مطلوب.'); const to = U.str(d.to) ? U.dateStr(d.to) : ''; assert(!to || U.isDate(to), 'تاريخ النهاية غير صالح.'); const value = Number(d.value); assert(value > 0, 'القيمة غير صحيحة.');
    if (type === this.BASIC) assert(!DA.readMany('SalaryTransactions', { where: { code } }).some(t => U.str(t.type) === this.BASIC && U.dateStr(t.from) === from), 'فيه راتب أساسي مسجَّل بالفعل بنفس تاريخ السريان (' + from + '). عدّل الحركة الحالية أو ألغِها الأول.');
    const txId = U.id('TX-'); DA.insert('SalaryTransactions', { code, type, from, to, value, reason: U.str(d.reason), createdAt: U.now(), user: ctx.session.username, txId }); SystemService.audit(ctx, 'salaryTx.add', code, type + ' ' + value + ' من ' + from);
    return Object.assign({ ok: true, txId }, this.transactionsForEmployee({ code }));
  }
};

/* ---------- PhotoService (reference photo, pending review, face-match lock) ---------- */
const PhotoService = {
  _reviewRow(code) { return DA.readOne('PhotoReview', { code }); },
  _setReview(code, patch) { if (DA.readOne('PhotoReview', { code })) DA.update('PhotoReview', { code }, Object.assign({ updatedAt: U.now() }, patch)); else DA.insert('PhotoReview', Object.assign({ code, pendingFileId: '', reviewStatus: '', submissionUsed: '', updatedAt: U.now() }, patch)); },
  _hist(code, field, oldV, newV, reason, user) { DA.insert('EmployeeHistory', { code, field, oldValue: U.str(oldV), newValue: U.str(newV), reason, user, timestamp: U.now(), operationId: U.uuid() }); },
  view(payload) { const emp = EmployeeService._exists(U.str(payload.code)); const fid = U.str(emp.referencePhotoFileId); if (!fid) fail(ERR.NOT_FOUND, 'مفيش صورة مرجعية مسجَّلة لهذا الموظف.'); return { file: FileStore.getBase64(fid) }; },
  upload(payload, ctx) { const code = U.str(payload.code); const emp = EmployeeService._exists(code); const du = U.dataUrl(payload.photoBase64); assert(du && /^image\//.test(du.mime), 'صيغة الصورة غير صحيحة.'); const old = U.str(emp.referencePhotoFileId); FileStore.trash(old); const f = FileStore.saveBase64(FileStore.FOLDER_REF_PHOTOS, du.base64, du.mime, 'ref_' + code + '_' + Date.now()); DA.update('Employees', { code }, { referencePhotoFileId: f.fileId }); this._hist(code, 'referencePhotoFileId', old, f.fileId, 'رفع صورة مرجعية', ctx.session.username); return { ok: true }; },
  remove(payload, ctx) { const code = U.str(payload.code); const emp = EmployeeService._exists(code); const old = U.str(emp.referencePhotoFileId); assert(old, 'مفيش صورة مرجعية مسجَّلة.'); FileStore.trash(old); DA.update('Employees', { code }, { referencePhotoFileId: '' }); this._hist(code, 'referencePhotoFileId', old, '', 'حذف الصورة المرجعية', ctx.session.username); return { ok: true }; },
  /* --- review of employee-submitted photos («طلبات استكمال البيانات») --- */
  reviewList() { const emps = {}; DA.readMany('Employees').forEach(e => { emps[U.str(e.code)] = U.str(e.nameAr); }); return { items: DA.readMany('PhotoReview').filter(r => U.str(r.pendingFileId)).map(r => ({ code: U.str(r.code), nameAr: emps[U.str(r.code)] || '', status: U.str(r.reviewStatus), updatedAt: U.stamp(r.updatedAt) })) }; },
  pendingView(payload) { const r = this._reviewRow(U.str(payload.code)); if (!r || !U.str(r.pendingFileId)) fail(ERR.NOT_FOUND, 'مفيش صورة معلّقة لهذا الموظف.'); return { file: FileStore.getBase64(U.str(r.pendingFileId)) }; },
  approve(payload, ctx) { const code = U.str(payload.code); const emp = EmployeeService._exists(code); const r = this._reviewRow(code); const pending = r ? U.str(r.pendingFileId) : ''; assert(pending, 'مفيش صورة معلّقة لهذا الموظف.'); const old = U.str(emp.referencePhotoFileId); DA.update('Employees', { code }, { referencePhotoFileId: pending }); this._setReview(code, { pendingFileId: '', reviewStatus: 'approved' }); FileStore.trash(old); this._hist(code, 'referencePhotoFileId', old, pending, 'اعتماد الصورة الجديدة', ctx.session.username); return { ok: true }; },
  reject(payload, ctx) { const code = U.str(payload.code); EmployeeService._exists(code); const r = this._reviewRow(code); const pending = r ? U.str(r.pendingFileId) : ''; FileStore.trash(pending); this._setReview(code, { pendingFileId: '', reviewStatus: 'rejected' }); this._hist(code, 'pendingPhotoFileId', pending, '', 'رفض الصورة الجديدة: ' + U.str(payload.reason), ctx.session.username); return { ok: true }; },
  adminReplace(payload, ctx) { const code = U.str(payload.code); const emp = EmployeeService._exists(code); const du = U.dataUrl(payload.photoBase64); assert(du && /^image\//.test(du.mime), 'صيغة الصورة غير صحيحة.'); const old = U.str(emp.referencePhotoFileId); const f = FileStore.saveBase64(FileStore.FOLDER_REF_PHOTOS, du.base64, du.mime, 'ref_' + code + '_' + Date.now()); DA.update('Employees', { code }, { referencePhotoFileId: f.fileId }); const r = this._reviewRow(code); if (r && U.str(r.pendingFileId)) FileStore.trash(U.str(r.pendingFileId)); this._setReview(code, { pendingFileId: '', reviewStatus: 'admin_replaced' }); FileStore.trash(old); this._hist(code, 'referencePhotoFileId', old, f.fileId, 'رفع صورة بديلة من الإدارة', ctx.session.username); return { ok: true }; },
  /* --- employee self upload via bridge: one chance, goes to Pending --- */
  selfUpload(code, dataUrl) { const emp = DA.readOne('Employees', { code }); if (!emp) return { status: 'error', message: 'employee_not_found' }; const r = this._reviewRow(code); if (r && (U.str(r.reviewStatus) === 'pending' || U.str(r.pendingFileId))) return { status: 'error', message: 'photo_review_pending' }; const du = U.dataUrl(dataUrl); if (!du) return { status: 'error', message: 'missing_photo' }; if (r && U.str(r.pendingFileId)) FileStore.trash(U.str(r.pendingFileId)); const f = FileStore.saveBase64(FileStore.FOLDER_PENDING_PHOTOS, du.base64, du.mime, 'pending_' + code + '_' + Date.now()); this._setReview(code, { pendingFileId: f.fileId, reviewStatus: 'pending', submissionUsed: 'true' }); this._hist(code, 'photoSubmission', '', f.fileId, 'رفع صورة من الموظف (فرصة واحدة — Pending للمراجعة)', 'employee:' + code); return { status: 'ok', mode: 'pending', hadActive: !!U.str(emp.referencePhotoFileId) }; },
  state(code) { const emp = DA.readOne('Employees', { code }); if (!emp) return null; const r = this._reviewRow(code) || {}; return { hasActivePhoto: !!U.str(emp.referencePhotoFileId), photoRejected: U.str(r.reviewStatus) === 'rejected', canUploadPhoto: U.str(r.reviewStatus) !== 'pending' && !U.str(r.pendingFileId) }; },
  /* --- face-match review lock (3 consecutive failures) --- */
  faceList() { return { items: DA.readMany('FaceReview').filter(r => U.str(r.reviewStatus) === 'pending_review').map(r => ({ code: U.str(r.code), failures: Number(r.failures) || 0, since: U.dateStr(r.updatedAt) })) }; },
  faceClear(payload, ctx) { const code = U.str(payload.code); if (DA.update('FaceReview', { code }, { failures: 0, reviewStatus: '', updatedAt: U.now() }) > 0) this._hist(code, 'faceReview', 'pending_review', '', 'فك قفل مراجعة مطابقة الوجه', ctx.session.username); return { ok: true }; },
  faceFail(code, matched) { const r = DA.readOne('FaceReview', { code }); if (matched) { if (r) DA.update('FaceReview', { code }, { failures: 0, updatedAt: U.now() }); return { locked: false, failures: 0 }; } const failures = (r ? Number(r.failures) || 0 : 0) + 1; const locked = failures >= 3; const patch = { failures, reviewStatus: locked ? 'pending_review' : (r ? U.str(r.reviewStatus) : ''), updatedAt: U.now() }; if (r) DA.update('FaceReview', { code }, patch); else DA.insert('FaceReview', Object.assign({ code }, patch)); return { locked, failures }; },
  faceLocked(code) { const r = DA.readOne('FaceReview', { code }); return !!r && U.str(r.reviewStatus) === 'pending_review'; }
};

/* ---------- ProfileRequestService (employee self-service: field permissions, enrollment, change requests) ---------- */
const ProfileRequestService = {
  fieldPermissions() { return DA.readMany('FieldPermissions').filter(p => U.str(p.key)).map(p => ({ key: U.str(p.key), label: U.str(p.label), visible: U.yes(p.visible), editable: U.yes(p.editable), required: U.yes(p.required), order: Number(p.order) || 999 })).sort((a, b) => a.order - b.order); },
  enrollment(code) { const r = DA.readOne('EnrollmentStatus', { code }); return r ? (U.str(r.status) || 'not_enrolled') : 'not_enrolled'; },
  _setEnrollment(code, status, reason) { const patch = { status, rejectReason: U.str(reason) }; if (status === 'approved') patch.approvedAt = U.now(); if (DA.readOne('EnrollmentStatus', { code })) DA.update('EnrollmentStatus', { code }, patch); else DA.insert('EnrollmentStatus', Object.assign({ code, submittedAt: U.now(), approvedAt: '', reviewer: '' }, patch)); },
  submit(code, pl) { const st = this.enrollment(code); if (st === 'approved') return { status: 'error', message: 'already_approved' }; if (st === 'pending') return { status: 'error', message: 'request_pending' }; const editable = {}; this.fieldPermissions().forEach(p => { if (p.editable) editable[p.key] = true; }); const clean = {}; Object.keys((pl && pl.fields) || {}).forEach(k => { if (editable[k]) clean[k] = pl.fields[k]; }); const emp = DA.readOne('Employees', { code }); if (!emp) return { status: 'error', message: 'employee_not_found' }; const id = U.uuid(); DA.insert('ProfileRequests', { id, code, nameAr: U.str(emp.nameAr), fields: JSON.stringify(clean), pendingPhotoFileId: U.str(pl && pl.pendingPhotoFileId), submittedAt: U.now(), status: 'pending', reviewer: '', reviewedAt: '', rejectReason: '' }); this._setEnrollment(code, 'pending', ''); return { status: 'ok', requestId: id }; },
  pending() { return { items: DA.readMany('ProfileRequests', { where: { status: 'pending' } }).map(r => ({ id: U.str(r.id), code: U.str(r.code), nameAr: U.str(r.nameAr), fields: U.parseJson(U.str(r.fields), {}), hasPhoto: !!U.str(r.pendingPhotoFileId), submittedAt: U.stamp(r.submittedAt) })) }; },
  approve(payload, ctx) { const id = U.str(payload.id); const r = DA.readOne('ProfileRequests', { id }); if (!r || U.str(r.status) !== 'pending') fail(ERR.NOT_FOUND, 'الطلب مش موجود أو اتراجع بالفعل.'); const code = U.str(r.code); const fields = U.parseJson(U.str(r.fields), {}); const allowed = {}; EMP.FIELDS.forEach(k => { if (k in fields) allowed[k] = fields[k]; }); if (Object.keys(allowed).length) EmployeeService.update({ code, changes: allowed, reason: 'اعتماد طلب تعديل بيانات' }, ctx); DA.update('ProfileRequests', { id }, { status: 'approved', reviewer: ctx.session.username, reviewedAt: U.now() }); this._setEnrollment(code, 'approved', ''); return { ok: true }; },
  reject(payload, ctx) { const id = U.str(payload.id); const r = DA.readOne('ProfileRequests', { id }); if (!r || U.str(r.status) !== 'pending') fail(ERR.NOT_FOUND, 'الطلب مش موجود أو اتراجع بالفعل.'); DA.update('ProfileRequests', { id }, { status: 'rejected', reviewer: ctx.session.username, reviewedAt: U.now(), rejectReason: U.str(payload.reason) }); this._setEnrollment(U.str(r.code), 'rejected', U.str(payload.reason)); return { ok: true }; },
  selfProfile(code) { const emp = DA.readOne('Employees', { code }); if (!emp) return { status: 'error', message: 'not_found' }; const e = EmployeeService._shape(emp); const fields = this.fieldPermissions().filter(p => p.visible).map(p => ({ key: p.key, label: p.label, editable: p.editable, required: p.required, value: p.key === 'referencePhoto' ? '' : (e[p.key] === undefined ? '' : String(e[p.key])) })); return { status: 'ok', code: e.code, enrollment: this.enrollment(code), hasPhoto: e.hasReferencePhoto, fields }; }
};

/* ---------- AcademicYearService («السنة الدراسية») ---------- */
const AcademicYearService = {
  _shape(r) { return { id: U.str(r.id), name: U.str(r.name), from: U.dateStr(r.from), to: U.dateStr(r.to), active: U.yes(r.active), createdBy: U.str(r.createdBy), createdAt: U.stamp(r.createdAt), updatedAt: U.stamp(r.updatedAt) }; },
  list() { return { years: DA.readMany('AcademicYears').map(this._shape) }; },
  add(payload, ctx) { const name = U.str(payload.name), from = U.dateStr(payload.from), to = U.dateStr(payload.to); assert(name, 'اسم السنة مطلوب.'); assert(U.isDate(from) && U.isDate(to) && from <= to, 'من تاريخ / لحد تاريخ غير صالحين.'); assert(!DA.readOne('AcademicYears', { name }), 'السنة موجودة بالفعل.'); const id = 'AY-' + U.uuid().slice(0, 8); DA.insert('AcademicYears', { id, name, from, to, active: NO, createdBy: ctx.session.username, createdAt: U.now(), updatedAt: U.now() }); SystemService.audit(ctx, 'academicYear.add', name, from + '→' + to); return { ok: true, id }; },
  update(payload, ctx) { const id = U.str(payload.id); const patch = {}; if (payload.name !== undefined) { assert(U.str(payload.name), 'اسم السنة مطلوب.'); patch.name = U.str(payload.name); } if (payload.from !== undefined) { assert(U.isDate(U.dateStr(payload.from)), 'من تاريخ غير صالح.'); patch.from = U.dateStr(payload.from); } if (payload.to !== undefined) { assert(U.isDate(U.dateStr(payload.to)), 'لحد تاريخ غير صالح.'); patch.to = U.dateStr(payload.to); } patch.updatedAt = U.now(); assert(DA.update('AcademicYears', { id }, patch) > 0, 'السنة مش موجودة.'); SystemService.audit(ctx, 'academicYear.update', id, ''); return { ok: true }; },
  setActive(payload, ctx) { const id = U.str(payload.id); assert(DA.readOne('AcademicYears', { id }), 'السنة مش موجودة.'); DA.batch([{ op: 'update', table: 'AcademicYears', where: r => U.yes(r.active), patch: { active: NO, updatedAt: U.now() } }, { op: 'update', table: 'AcademicYears', where: { id }, patch: { active: YES, updatedAt: U.now() } }]); SystemService.audit(ctx, 'academicYear.setActive', id, ''); return { ok: true }; }
};

/** V1 EMPLOYEE_VISIBLE_SUMMARY_ / EMPLOYEE_VISIBLE_DAY_ / pickFields_ — حقول التقرير المرئية للموظف (منقولة حرفيًا) */
const EMPLOYEE_VISIBLE_SUMMARY_ = ['workDaysRequired', 'presentDays', 'absentDays',
  'leaveDays', 'attendanceRate', 'lateCount', 'lateMinutesTotal', 'earlyCount',
  'lateMinutesOriginal', 'lateWaiverMinutes', 'lateMinutesFinal',
  'lateDeductionOriginal', 'lateDeductionFinal', 'totalDeductionOriginal',
  'earlyMinutesTotal', 'missingCheckoutDays', 'absenceDeductionTotal',
  'lateDeductionTotal', 'earlyDeductionTotal', 'missingCheckoutDeductionTotal',
  'permissionsCount', 'forgotCheckinsCount', 'extraPenaltyDays', 'totalDeduction'];
const EMPLOYEE_VISIBLE_DAY_ = ['dateKey', 'weekdayName', 'status', 'checkInActual',
  'checkOutActual', 'lateMinutes', 'earlyLeaveMinutes', 'holidayReason', 'missingCheckout',
  'lateWaiverMinutes', 'lateMinutesFinal'];
function pickFields_(obj, allowed) { const out = {}; (allowed || []).forEach(k => { if (obj && obj[k] !== undefined) out[k] = obj[k]; }); return out; }

/* ---------- BridgeService (OLD employeeBridge: server-to-server for the Attendance app, shared secret) ---------- */
const BridgeService = {
  handle(params) {
    const expected = U.str(PropertiesService.getScriptProperties().getProperty(CFG.PROP_BRIDGE_SECRET)); if (!expected || U.str(params.secret) !== expected) return { status: 'error', message: 'unauthorized' };
    const code = U.str(params.code); if (!code) return { status: 'error', message: 'missing_code' }; const op = U.str(params.op), pl = params.payload || {};
    const emp = DA.readOne('Employees', { code });
    if (op === 'gate') { if (!emp) return { status: 'ok', exists: false, terminated: false, enrollment: 'not_enrolled', faceLocked: false }; const e = EmployeeService._shape(emp, ExemptDepartmentService.activeSet()); return { status: 'ok', exists: true, terminated: e.status === 'منتهي الخدمة', exempt: e.exempt, enrollment: ProfileRequestService.enrollment(code), faceLocked: PhotoService.faceLocked(code) }; }
    if (op === 'profile') return ProfileRequestService.selfProfile(code);
    if (op === 'fieldPermissions') return { status: 'ok', fields: ProfileRequestService.fieldPermissions().filter(p => p.visible) };
    if (op === 'photoUpload') return PhotoService.selfUpload(code, U.str(pl.base64));
    if (op === 'photoView') { const st = PhotoService.state(code); if (!emp || !U.str(emp.referencePhotoFileId)) return { status: 'error', message: 'no_reference_photo', state: st }; return { status: 'ok', file: FileStore.getBase64(U.str(emp.referencePhotoFileId)), state: st }; }
    if (op === 'submit') return ProfileRequestService.submit(code, pl);
    if (op === 'faceResult') return Object.assign({ status: 'ok' }, PhotoService.faceFail(code, !!pl.matched));
    if (op === 'documents') return DocumentService.selfDocs(code);
    if (op === 'report') { const from = U.dateStr(pl.from), to = U.dateStr(pl.to); if (!from || !to) return { status: 'error', message: 'missing_period' };
      try { const c = AttendanceCalc.buildContext(from, to); const full = AttendanceCalc.employeeReportWithDeductions(code, from, to, c);
        // عقد V1 (buildEmployeeSelfReport_): summary و days في الجذر، بترشيح حقول الموظف المرئية فقط
        return { status: 'ok', summary: pickFields_(full.summary, EMPLOYEE_VISIBLE_SUMMARY_), days: (full.days || []).map(d => pickFields_(d, EMPLOYEE_VISIBLE_DAY_)) };
      } catch (e) { return { status: 'error', message: String(e && e.message || e) }; } }
    if (op === 'notifications') return { status: 'ok', notifications: NotificationService.forEmployee(code) };
    if (op === 'notifBadge') return { status: 'ok', count: NotificationService.forEmployee(code).filter(n => !n.read).length };
    if (op === 'notificationRead') return NotificationService.markRead(code, U.str(pl.id));
    return { status: 'error', message: 'unknown_op' };
  }
};


/* ---------- DocumentService («المستندات» — OLD dynamic types, latest-row-wins, soft delete, computed status, 3 reports) ---------- */
const DocumentService = {
  KEY_NEAR_DAYS: 'عدد أيام "قريب الانتهاء" للمستندات',
  EXT: { 'application/pdf': 'pdf', 'image/jpeg': 'jpg', 'image/jpg': 'jpg', 'image/png': 'png', 'image/webp': 'webp' },
  _safe(s) { return U.str(s).replace(/[\\/:*?"<>|]+/g, '_').slice(0, 40) || 'x'; },
  types() { return Cache.memo('docTypes', CFG.CACHE_SHARED_SEC, () => DA.readMany('DocumentTypes').filter(r => U.str(r.name)).map(r => ({ name: U.str(r.name), hasNumber: U.yes(r.hasNumber), required: U.yes(r.required), hasExpiry: U.yes(r.hasExpiry), alertDays: Number(r.alertDays) || 30, active: U.yes(r.active) }))); },
  typesList() { return { types: this.types() }; },
  typeAdd(payload, ctx) { const name = U.str(payload.name); assert(name, 'اسم نوع المستند مطلوب.'); assert(!this.types().some(t => t.name === name), 'النوع ده موجود بالفعل: ' + name); DA.insert('DocumentTypes', { name, hasNumber: U.yn(payload.hasNumber), required: payload.required === false || U.str(payload.required) === NO ? NO : YES, hasExpiry: U.yn(payload.hasExpiry), alertDays: Number(payload.alertDays) || 30, active: YES }); Cache.remove('docTypes'); SystemService.audit(ctx, 'docType.add', name, ''); return { ok: true }; },
  typeUpdate(payload, ctx) { const name = U.str(payload.name); const ex = this.types().find(t => t.name === name); assert(ex, 'النوع مش موجود: ' + name); const patch = {}; if (payload.hasNumber !== undefined) patch.hasNumber = U.yn(payload.hasNumber); if (payload.required !== undefined) patch.required = U.yn(payload.required); if (payload.hasExpiry !== undefined) patch.hasExpiry = U.yn(payload.hasExpiry); if (payload.alertDays !== undefined) patch.alertDays = Number(payload.alertDays) || 30; DA.update('DocumentTypes', { name }, patch); Cache.remove('docTypes'); SystemService.audit(ctx, 'docType.update', name, JSON.stringify(patch)); return { ok: true }; },
  typeToggle(payload, ctx) { const name = U.str(payload.name); assert(this.types().some(t => t.name === name), 'النوع مش موجود: ' + name); DA.update('DocumentTypes', { name }, { active: U.yn(payload.active) }); Cache.remove('docTypes'); SystemService.audit(ctx, 'docType.toggle', name, U.yn(payload.active)); return { ok: true }; },
  nearDays() { return Number(SettingsService.get(this.KEY_NEAR_DAYS, 30)) || 30; },
  /** OLD getLatestDocumentsMap_: latest registered row per (code,type) — ONE read */
  latestMap(code) { const m = {}; DA.readMany('EmployeeDocuments').forEach(r => { const c = U.str(r.code), t = U.str(r.type); if (!c || !t || (code && c !== code)) return; const ts = Date.parse(r.registeredAt) || 0; const k = c + '|' + t; if (!m[k] || ts >= m[k]._ts) m[k] = { code: c, type: t, documentNumber: U.str(r.documentNumber), copyType: U.str(r.copyType), issueDate: U.dateStr(r.issueDate), expiryDate: U.dateStr(r.expiryDate), driveFileId: U.str(r.driveFileId), isDeleted: U.yes(r.isDeleted), user: U.str(r.user), registeredAt: U.stamp(r.registeredAt), _ts: ts }; }); return m; },
  computeStatus(expiry, alertDays, today) { if (!expiry) return 'ساري'; if (expiry < today) return 'منتهي'; const daysLeft = Math.round((new Date(expiry) - new Date(today)) / 86400000); return daysLeft <= alertDays ? 'قريب الانتهاء' : 'ساري'; },
  /** OLD getEmployeeDocumentsStatus_ (code=null → every employee that has any record) */
  statusFor(code, typesAll) { const types = (typesAll || this.types()).filter(t => t.active); const map = this.latestMap(code); const today = U.today(); const codes = code ? [code] : [...new Set(Object.values(map).map(d => d.code))]; const out = []; codes.forEach(c => types.forEach(t => { const rec = map[c + '|' + t.name]; if (!rec || rec.isDeleted) out.push({ code: c, type: t.name, status: t.required ? 'ناقص' : 'غير مطلوب', documentNumber: '', copyType: '', issueDate: '', expiryDate: '', hasFile: false, hasNumber: t.hasNumber, required: t.required, hasExpiry: t.hasExpiry }); else out.push({ code: c, type: t.name, status: t.hasExpiry ? this.computeStatus(rec.expiryDate, t.alertDays, today) : 'ساري', documentNumber: rec.documentNumber, copyType: rec.copyType, issueDate: rec.issueDate, expiryDate: rec.expiryDate, hasFile: !!rec.driveFileId, hasNumber: t.hasNumber, required: t.required, hasExpiry: t.hasExpiry, registeredAt: rec.registeredAt, user: rec.user }); })); return out; },
  employeeDocs(payload) { const code = U.str(payload.code); assert(code, 'كود الموظف مطلوب.'); EmployeeService._exists(code); return { code, documents: this.statusFor(code), types: this.types() }; },
  /** OLD uploadAndRegisterDocument_ + addEmployeeDocument_: type rules, number required, أصل/صورة, duplicate confirm, Drive file via FileStore */
  add(payload, ctx) {
    const code = U.str(payload.code), type = U.str(payload.type); assert(code, 'كود الموظف مطلوب.'); assert(type, 'نوع المستند مطلوب.'); const emp = EmployeeService._exists(code);
    const def = this.types().find(t => t.name === type); assert(def, 'نوع المستند ده مش موجود في قائمة الأنواع: ' + type); assert(def.active, 'نوع المستند ده متوقف حاليًا: ' + type);
    const documentNumber = U.str(payload.documentNumber); assert(!def.hasNumber || documentNumber, 'رقم المستند إلزامي لنوع "' + type + '".');
    const copyType = U.str(payload.copyType); assert(!copyType || copyType === 'أصل' || copyType === 'صورة', 'نوع النسخة لازم يكون "أصل" أو "صورة".');
    if (!payload.confirmDuplicate) { const ex = this.latestMap(code)[code + '|' + type]; if (ex && !ex.isDeleted) fail(ERR.VALIDATION_ERROR, 'DUPLICATE:الموظف ده عنده بالفعل مستند من نوع "' + type + '" مسجل قبل كده. أكّد لو عايز تضيف نسخة جديدة (القديمة هتفضل في السجل).', { duplicate: true }); }
    const issueDate = U.str(payload.issueDate) ? U.dateStr(payload.issueDate) : ''; assert(!U.str(payload.issueDate) || issueDate, 'تاريخ الإصدار غير صالح.');
    const expiryDate = def.hasExpiry && U.str(payload.expiryDate) ? U.dateStr(payload.expiryDate) : ''; assert(!(def.hasExpiry && U.str(payload.expiryDate)) || expiryDate, 'تاريخ الانتهاء غير صالح.');
    let driveFileId = ''; const du = U.dataUrl(payload.fileBase64); if (du) { const ext = this.EXT[du.mime] || 'dat'; assert(ext !== 'dat', 'الملف لازم يكون صورة أو PDF.'); driveFileId = FileStore.saveBase64(FileStore.FOLDER_DOCS, du.base64, du.mime, [this._safe(code), this._safe(emp.nameAr || 'موظف'), this._safe(type), U.today()].join('_') + '.' + ext).fileId; }
    DA.insert('EmployeeDocuments', { code, type, documentNumber, copyType, issueDate, expiryDate, driveFileId, isDeleted: NO, registeredAt: U.now(), user: ctx.session.username }); SystemService.audit(ctx, 'document.add', code, type);
    return Object.assign({ ok: true }, this.employeeDocs({ code }));
  },
  /** OLD deleteEmployeeDocument_: soft delete = append a row with محذوف؟=نعم; file kept (non-destructive) */
  remove(payload, ctx) { const code = U.str(payload.code), type = U.str(payload.type); const ex = this.latestMap(code)[code + '|' + type]; assert(ex && !ex.isDeleted, 'مفيش مستند نشط من النوع ده للموظف ده أصلاً.'); DA.insert('EmployeeDocuments', { code, type, documentNumber: ex.documentNumber, copyType: ex.copyType, issueDate: ex.issueDate, expiryDate: ex.expiryDate, driveFileId: ex.driveFileId, isDeleted: YES, registeredAt: U.now(), user: ctx.session.username }); SystemService.audit(ctx, 'document.delete', code, type); return Object.assign({ ok: true }, this.employeeDocs({ code })); },
  file(payload) { const code = U.str(payload.code), type = U.str(payload.type); const ex = this.latestMap(code)[code + '|' + type]; if (!ex || !ex.driveFileId) fail(ERR.NOT_FOUND, 'مفيش ملف مرفق لهذا المستند.'); return { file: FileStore.getBase64(ex.driveFileId) }; },
  _empMap() { const m = {}; DA.readMany('Employees').forEach(r => { const e = EmployeeService._shape(r); m[e.code] = e; }); return m; },
  _filt(list) { return (Array.isArray(list) ? list : U.str(list).split(',')).map(x => U.str(x)).filter(Boolean); },
  /** «الموظفون الناقص لهم مستندات» (OLD getMissingDocumentsReport_) */
  reportMissing(payload) { const wantD = this._filt(payload.dept), wantJ = this._filt(payload.jobTitle); const emps = this._empMap(); const types = this.types(); const all = this.statusFor(null, types); const missing = {}; all.forEach(d => { if (d.status === 'ناقص') (missing[d.code] = missing[d.code] || []).push(d.type); }); const required = types.filter(t => t.active && t.required).map(t => t.name); const withRec = new Set(all.map(d => d.code)); Object.values(emps).forEach(e => { if (!missing[e.code] && required.length && !withRec.has(e.code)) missing[e.code] = required.slice(); }); return { rows: Object.keys(missing).filter(c => { const e = emps[c]; if (wantD.length && (!e || !wantD.includes(e.dept))) return false; if (wantJ.length && (!e || !wantJ.includes(e.jobTitle))) return false; return true; }).map(c => ({ code: c, nameAr: emps[c] ? emps[c].nameAr : c, dept: emps[c] ? emps[c].dept : '', jobTitle: emps[c] ? emps[c].jobTitle : '', status: emps[c] ? emps[c].status : '-', missingTypes: missing[c], missingCount: missing[c].length })) }; },
  /** «المستندات قربت تنتهي» (OLD getNearExpiryDocumentsReport_) */
  reportNearExpiry(payload) { const wantD = this._filt(payload.dept), wantJ = this._filt(payload.jobTitle); const emps = this._empMap(); const today = U.today(); return { rows: this.statusFor(null).filter(d => d.status === 'قريب الانتهاء' || d.status === 'منتهي').filter(d => { const e = emps[d.code]; if (wantD.length && (!e || !wantD.includes(e.dept))) return false; if (wantJ.length && (!e || !wantJ.includes(e.jobTitle))) return false; return true; }).map(d => ({ code: d.code, nameAr: emps[d.code] ? emps[d.code].nameAr : d.code, dept: emps[d.code] ? emps[d.code].dept : '', jobTitle: emps[d.code] ? emps[d.code].jobTitle : '', type: d.type, documentNumber: d.documentNumber, copyType: d.copyType, issueDate: d.issueDate, expiryDate: d.expiryDate, daysLeft: d.expiryDate ? Math.round((new Date(d.expiryDate) - new Date(today)) / 86400000) : null, status: d.status })) }; },
  /** «تقرير حسب نوع المستند» (OLD getDocumentsByType_) */
  reportByType(payload) { const type = U.str(payload.type); assert(type, 'اختار نوع المستند.'); const wantD = this._filt(payload.dept), wantJ = this._filt(payload.jobTitle); const emps = this._empMap(); const byCode = {}; this.statusFor(null).filter(d => d.type === type).forEach(d => { byCode[d.code] = d; }); return { rows: Object.values(emps).filter(e => !wantD.length || wantD.includes(e.dept)).filter(e => !wantJ.length || wantJ.includes(e.jobTitle)).map(e => { const d = byCode[e.code]; return { code: e.code, nameAr: e.nameAr, dept: e.dept, jobTitle: e.jobTitle, status: d ? d.status : 'ناقص', documentNumber: d ? d.documentNumber : '', expiryDate: d ? d.expiryDate : '' }; }) }; },
  nearDaysGet() { return { days: this.nearDays() }; },
  nearDaysSet(payload, ctx) { const n = Number(payload.days); assert(n > 0, 'عدد الأيام لازم يكون رقم أكبر من صفر.'); SettingsService.set(this.KEY_NEAR_DAYS, n, SCHEMA.Settings.seed[1][2]); SystemService.audit(ctx, 'settings.docNearDays', '', String(n)); return { ok: true, days: n }; },
  selfDocs(code) { const emp = DA.readOne('Employees', { code }); if (!emp) return { status: 'error', message: 'not_found' }; return { status: 'ok', documents: this.statusFor(code).map(d => ({ type: d.type, status: d.status, documentNumber: d.documentNumber, expiryDate: d.expiryDate, required: d.required })) }; }
};

/* ---------- InvestigationService («التحقيقات» — OLD model: multi-employee, attachments, non-destructive cancel, NO open/closed) ---------- */
const InvestigationService = {
  _nextId(rows) { let max = 0; rows.forEach(r => { const m = /^INV-(\d+)$/.exec(U.str(r.id)); if (m) max = Math.max(max, Number(m[1])); }); return 'INV-' + String(max + 1).padStart(6, '0'); },
  /** OLD getInvestigations_: 3 reads + employees for names; filters from/to/employees(multi)/search */
  list(payload) {
    const f = payload || {}; const from = U.dateStr(f.from), to = U.dateStr(f.to); const emps = (Array.isArray(f.employees) ? f.employees : U.str(f.employees).split(',')).map(x => U.str(x)).filter(Boolean); const q = U.str(f.search).toLowerCase();
    const names = {}; DA.readMany('Employees').forEach(r => { names[U.str(r.code)] = U.str(r.nameAr) || U.str(r.nameEn) || U.str(r.code); });
    const empsById = {}; DA.readMany('InvestigationEmployees').forEach(r => { if (U.yes(r.cancelled)) return; const id = U.str(r.id), code = U.str(r.code); if (id && code) (empsById[id] = empsById[id] || []).push({ code, name: names[code] || code }); });
    const attsById = {}; DA.readMany('InvestigationAttachments').forEach(r => { if (U.yes(r.cancelled)) return; const id = U.str(r.id); if (id) (attsById[id] = attsById[id] || []).push({ fileId: U.str(r.fileId), fileName: U.str(r.fileName), uploadedAt: U.dateStr(r.uploadedAt), uploadedBy: U.str(r.uploadedBy) }); });
    const out = []; DA.readMany('Investigations').forEach(r => { const id = U.str(r.id); if (!id || U.yes(r.cancelled)) return; const date = U.dateStr(r.date); const e = empsById[id] || []; if (from && date && date < from) return; if (to && date && date > to) return; if (emps.length && !e.some(x => emps.includes(x.code))) return; if (q) { const hay = (id + ' ' + U.str(r.title) + ' ' + U.str(r.decision) + ' ' + U.str(r.notes) + ' ' + e.map(x => x.code + ' ' + x.name).join(' ')).toLowerCase(); if (!hay.includes(q)) return; } out.push({ id, date, title: U.str(r.title), decision: U.str(r.decision), notes: U.str(r.notes), employees: e, attachments: (attsById[id] || []).map(a => ({ fileId: a.fileId ? 'yes' : '', fileName: a.fileName, uploadedAt: a.uploadedAt, uploadedBy: a.uploadedBy })), createdBy: U.str(r.user), registeredAt: U.stamp(r.registeredAt) }); });
    return { investigations: out.sort((a, b) => (a.id < b.id ? 1 : a.id > b.id ? -1 : 0)) };
  },
  /** OLD saveInvestigation_: new → next INV id; edit → cancel current version + links, append new version (history preserved) */
  save(payload, ctx) {
    const d = payload || {}; const id = U.str(d.id), date = U.dateStr(d.date), title = U.str(d.title); assert(date, 'تاريخ التحقيق غير صحيح.'); assert(title, 'عنوان/موضوع التحقيق مطلوب.');
    const codes = [...new Set((Array.isArray(d.employees) ? d.employees : U.str(d.employees).split(',')).map(x => U.str(x)).filter(Boolean))]; assert(codes.length, 'لازم تختار موظف واحد على الأقل.');
    const known = new Set(DA.readMany('Employees').map(r => U.str(r.code))); codes.forEach(c => assert(known.has(c), 'كود الموظف مش موجود: ' + c));
    return U.withLock(() => { const rows = DA.readMany('Investigations'); let invId = id; const ops = []; const now = U.now();
      if (invId) { assert(rows.some(r => U.str(r.id) === invId && !U.yes(r.cancelled)), 'التحقيق «' + invId + '» مش موجود.'); ops.push({ op: 'update', table: 'Investigations', where: r => U.str(r.id) === invId && !U.yes(r.cancelled), patch: { cancelled: YES } }); ops.push({ op: 'update', table: 'InvestigationEmployees', where: r => U.str(r.id) === invId && !U.yes(r.cancelled), patch: { cancelled: YES } }); } else invId = this._nextId(rows);
      ops.push({ op: 'insert', table: 'Investigations', data: { id: invId, date, title, decision: U.str(d.decision), notes: U.str(d.notes), cancelled: NO, user: ctx.session.username, registeredAt: now } }); codes.forEach(c => ops.push({ op: 'insert', table: 'InvestigationEmployees', data: { id: invId, code: c, cancelled: NO, registeredAt: now } }));
      DA.batch(ops); SystemService.audit(ctx, id ? 'investigation.update' : 'investigation.create', invId, codes.join(',')); return { id: invId, employees: codes.length }; }, 30000);
  },
  cancel(payload, ctx) { const id = U.str(payload.id); assert(id, 'معرّف التحقيق مطلوب.'); const n = DA.update('Investigations', r => U.str(r.id) === id && !U.yes(r.cancelled), { cancelled: YES }); assert(n > 0, 'التحقيق «' + id + '» مش موجود أو ملغى بالفعل.'); DA.batch([{ op: 'update', table: 'InvestigationEmployees', where: r => U.str(r.id) === id && !U.yes(r.cancelled), patch: { cancelled: YES } }, { op: 'update', table: 'InvestigationAttachments', where: r => U.str(r.id) === id && !U.yes(r.cancelled), patch: { cancelled: YES } }]); SystemService.audit(ctx, 'investigation.cancel', id, U.str(payload.reason)); return { ok: true }; },
  attachmentAdd(payload, ctx) { const id = U.str(payload.id); assert(id, 'معرّف التحقيق مطلوب.'); assert(DA.readOne('Investigations', r => U.str(r.id) === id && !U.yes(r.cancelled)), 'التحقيق «' + id + '» مش موجود.'); const du = U.dataUrl(payload.fileBase64); assert(du, 'الملف مطلوب.'); const ext = DocumentService.EXT[du.mime]; assert(ext, 'الملف لازم يكون صورة أو PDF.'); const name = [DocumentService._safe(id), DocumentService._safe(payload.fileName || 'مرفق'), U.today()].join('_') + '.' + ext; const f = FileStore.saveBase64(FileStore.FOLDER_DOCS, du.base64, du.mime, name); DA.insert('InvestigationAttachments', { id, fileId: f.fileId, fileName: f.fileName, fileUrl: '', cancelled: NO, uploadedAt: U.now(), uploadedBy: ctx.session.username }); SystemService.audit(ctx, 'investigation.attachment.add', id, f.fileName); return { ok: true, fileName: f.fileName }; },
  attachmentRemove(payload, ctx) { const id = U.str(payload.id), fileName = U.str(payload.fileName); const n = DA.update('InvestigationAttachments', r => U.str(r.id) === id && U.str(r.fileName) === fileName && !U.yes(r.cancelled), { cancelled: YES }); assert(n > 0, 'المرفق مش موجود.'); SystemService.audit(ctx, 'investigation.attachment.remove', id, fileName); return { ok: true }; },
  attachmentFile(payload) { const id = U.str(payload.id), fileName = U.str(payload.fileName); const r = DA.readOne('InvestigationAttachments', x => U.str(x.id) === id && U.str(x.fileName) === fileName && !U.yes(x.cancelled)); if (!r || !U.str(r.fileId)) fail(ERR.NOT_FOUND, 'المرفق مش موجود.'); return { file: FileStore.getBase64(U.str(r.fileId)) }; }
};

/* ===================================================================== */
/* PHASE 3 — الحضور والإقفال                                              */
/* Ported from OLD GHIS without changing any business rule:              */
/*   classifyDay_ · buildEmployeeReport_ · summarizeDays_ ·              */
/*   buildEmployeeReportWithDeductions_ · buildMonthlyClosing_ ·         */
/*   close/reopen + snapshot (building → ready → superseded)             */
/* Attendance stays a separate system: GHIS only READS its monthly       */
/* YYYY-MM sheets through DA (EmployeeCode is the integration key).      */
/* ===================================================================== */

const WEEKDAY_NAMES_ = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
const SNAPSHOT_STATE = { BUILDING: 'building', READY: 'ready', SUPERSEDED: 'superseded' };
const LATE_WAIVER_PERM_ = 'attendance_adjust';   // OLD LATE_WAIVER_PERM_

/** OLD date/time helpers (same semantics, no Date mutation surprises). */
const AT = {
  key(d) { return Utilities.formatDate(d, CFG.TZ, 'yyyy-MM-dd'); },
  parse(k) { k = U.str(k); if (!/^\d{4}-\d{2}-\d{2}$/.test(k)) return null; const d = new Date(k + 'T00:00:00'); return isNaN(d) ? null : d; },
  monthKey(k) { return U.str(k).slice(0, 7); },
  addDays(k, n) { const d = new Date(k + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); },
  weekday(k) { return new Date(k + 'T00:00:00Z').getUTCDay(); },                 // 5=الجمعة 6=السبت (OLD rule)
  eachDay(from, to) { const out = []; let k = from; while (k <= to) { out.push(k); k = this.addDays(k, 1); } return out; },
  /** minutes since midnight from 'HH:mm' / Date / ISO — OLD timeToMinutes_ (no String() bug) */
  toMinutes(v) { if (v instanceof Date) return v.getHours() * 60 + v.getMinutes(); const t = U.str(v); let m = t.match(/^(\d{1,2}):(\d{2})/); if (m) return Number(m[1]) * 60 + Number(m[2]); const d = new Date(t); if (!isNaN(d)) { const s = Utilities.formatDate(d, CFG.TZ, 'HH:mm').split(':'); return Number(s[0]) * 60 + Number(s[1]); } return 0; },
  fromMinutes(min) { if (min === null || min === undefined) return '-'; const h = Math.floor(min / 60), m = min % 60; return ('0' + h).slice(-2) + ':' + ('0' + m).slice(-2); },
  timeText(v, dflt) { return U.timeText(v, dflt); },
  round2(n) { return Math.round((Number(n) || 0) * 100) / 100; },
  /** تقريب المبالغ النقدية لأقرب 5 جنيه — للمبالغ بس، مش للأيام/الدقائق/النِسَب/العدد. */
  round5(n) { return Math.round((Number(n) || 0) / 5) * 5; }
};

/* ---------- RulesService («القواعد»: قواعد المواعيد التاريخية + أرشيفها + قواعد الخصم + الإجازات الرسمية) ---------- */
const RulesService = {
  scheduleRules() { return DA.readMany('ScheduleRules').filter(r => U.dateStr(r.from) && U.dateStr(r.to)).map(r => ({ id: U.str(r.id), from: U.dateStr(r.from), to: U.dateStr(r.to), checkIn: AT.timeText(r.checkIn, '08:00'), checkOut: AT.timeText(r.checkOut, '15:00'), checkInMinutes: AT.toMinutes(r.checkIn), checkOutMinutes: AT.toMinutes(r.checkOut) })); },
  deductionRules() { return DA.readMany('DeductionRules').filter(r => U.dateStr(r.from) && U.dateStr(r.to)).map(r => ({ from: U.dateStr(r.from), to: U.dateStr(r.to), lateUnitMinutes: Number(r.lateUnitMinutes) || 25, lateUnitValue: Number(r.lateUnitValue) || 0.25, absenceValue: Number(r.absenceValue) || 1, missingCheckoutValue: Number(r.missingCheckoutValue) || 0.25, earlyLeaveEnabled: U.str(r.earlyLeaveEnabled) === YES, earlyLeaveUnitMinutes: Number(r.earlyLeaveUnitMinutes) || 25, earlyLeaveUnitValue: Number(r.earlyLeaveUnitValue) || 0.25, permissionLadder: [Number(r.permission1) || 0, Number(r.permission2) || 0, Number(r.permission3) || 0, Number(r.permission4) || 0, Number(r.permission5Plus) || 0] })); },
  ignoredDeductionRuleRows() { return DA.readMany('DeductionRules').filter(r => Object.keys(r).some(k => U.str(r[k]) !== '') && !(U.dateStr(r.from) && U.dateStr(r.to))).length; },
  officialHolidays() { const map = {}; DA.readMany('OfficialHolidays').forEach(r => { const k = U.dateStr(r.date); if (k) map[k] = U.str(r.reason) || 'إجازة رسمية'; }); return map; },
  /** OLD checkDateRangeOverlap_ — historical rules must never overlap */
  _overlap(list, from, to, exceptId) { for (const r of list) { if (exceptId && r.id === exceptId) continue; if (from <= r.to && to >= r.from) return 'الفترة الجديدة (' + from + ' إلى ' + to + ') بتتداخل مع قاعدة موجودة بالفعل (' + r.from + ' إلى ' + r.to + ').'; } return null; },
  list() { return { scheduleRules: this.scheduleRules().sort((a, b) => a.from.localeCompare(b.from)), deductionRules: this.deductionRules().sort((a, b) => a.from.localeCompare(b.from)), officialHolidays: Object.keys(this.officialHolidays()).sort().map(k => ({ date: k, reason: this.officialHolidays()[k] })), checkoutExceptions: AttendanceAdjustService.checkoutExceptions(), ignoredDeductionRuleRows: this.ignoredDeductionRuleRows() }; },
  scheduleRuleAdd(payload, ctx) {
    const from = U.dateStr(payload.from), to = U.dateStr(payload.to); assert(from && to, 'لازم تحدد من تاريخ ولحد تاريخ صحيحين.'); assert(from <= to, 'تاريخ البداية لازم يكون قبل تاريخ النهاية.');
    return U.withLock(() => { const all = this.scheduleRules(); const err = this._overlap(all, from, to); if (err) fail(ERR.VALIDATION_ERROR, err);
      let max = 0; all.forEach(r => { const m = /^SR-(\d+)$/.exec(r.id); if (m) max = Math.max(max, Number(m[1])); }); const id = 'SR-' + String(max + 1).padStart(4, '0');
      DA.insert('ScheduleRules', { from, to, checkIn: AT.timeText(payload.checkIn, '08:00'), checkOut: AT.timeText(payload.checkOut, '15:00'), id });
      SystemService.audit(ctx, 'scheduleRule.add', id, from + '→' + to); return { ok: true, id }; }, 30000);
  },
  /** OLD updateScheduleRule_: archive the old version first (historical, never silently overwritten) */
  scheduleRuleUpdate(payload, ctx) {
    const id = U.str(payload.id); assert(id, 'لازم تحدد القاعدة.');
    const from = U.dateStr(payload.from), to = U.dateStr(payload.to); assert(from && to, 'التواريخ غير صحيحة.'); assert(from <= to, 'تاريخ البداية لازم يكون قبل تاريخ النهاية.');
    return U.withLock(() => { const all = this.scheduleRules(); const old = all.find(r => r.id === id); assert(old, 'القاعدة غير موجودة.');
      const clash = this._overlap(all, from, to, id); if (clash) fail(ERR.VALIDATION_ERROR, 'النطاق بيتداخل مع قاعدة تانية.');
      const archiveId = 'SA-' + U.uuid().replace(/-/g, '').slice(0, 8).toUpperCase();
      DA.batch([{ op: 'insert', table: 'ScheduleRulesArchive', data: { archiveId, ruleId: id, opType: 'تعديل', from: old.from, to: old.to, checkIn: old.checkIn, checkOut: old.checkOut, user: ctx.session.username, timestamp: U.now(), reason: U.str(payload.reason) } },
        { op: 'update', table: 'ScheduleRules', where: { id }, patch: { from, to, checkIn: AT.timeText(payload.checkIn, '08:00'), checkOut: AT.timeText(payload.checkOut, '15:00') } }]);
      SystemService.audit(ctx, 'scheduleRule.update', id, U.str(payload.reason)); return { ok: true, id, archiveId }; }, 30000);
  },
  scheduleRuleArchive(payload) { const id = U.str(payload.id); return { items: DA.readMany('ScheduleRulesArchive').filter(r => !id || U.str(r.ruleId) === id).map(r => ({ archiveId: U.str(r.archiveId), ruleId: U.str(r.ruleId), opType: U.str(r.opType), from: U.dateStr(r.from), to: U.dateStr(r.to), checkIn: AT.timeText(r.checkIn, ''), checkOut: AT.timeText(r.checkOut, ''), user: U.str(r.user), timestamp: U.stamp(r.timestamp), reason: U.str(r.reason) })).reverse() }; },
  deductionRuleAdd(payload, ctx) {
    const from = U.dateStr(payload.from), to = U.dateStr(payload.to); assert(from && to, 'لازم تحدد من تاريخ ولحد تاريخ صحيحين.'); assert(from <= to, 'تاريخ البداية لازم يكون قبل تاريخ النهاية.');
    return U.withLock(() => { const err = this._overlap(this.deductionRules(), from, to); if (err) fail(ERR.VALIDATION_ERROR, err);
      DA.insert('DeductionRules', { from, to, lateUnitMinutes: Number(payload.lateUnitMinutes) || 25, lateUnitValue: Number(payload.lateUnitValue) || 0.25, absenceValue: Number(payload.absenceValue) || 1, missingCheckoutValue: Number(payload.missingCheckoutValue) || 0.25, earlyLeaveEnabled: payload.earlyLeaveEnabled ? YES : NO, earlyLeaveUnitMinutes: Number(payload.earlyLeaveUnitMinutes) || 25, earlyLeaveUnitValue: Number(payload.earlyLeaveUnitValue) || 0.25, permission1: Number(payload.permission1) || 0, permission2: Number(payload.permission2) || 0.25, permission3: Number(payload.permission3) || 0.5, permission4: Number(payload.permission4) || 0.75, permission5Plus: Number(payload.permission5Plus) || 1 });
      SystemService.audit(ctx, 'deductionRule.add', from + '→' + to, ''); return { ok: true }; }, 30000);
  },
  holidayAdd(payload, ctx) { const date = U.dateStr(payload.date); assert(date, 'التاريخ غير صحيح.'); assert(!DA.readOne('OfficialHolidays', r => U.dateStr(r.date) === date), 'التاريخ ده مسجّل كإجازة رسمية بالفعل.'); DA.insert('OfficialHolidays', { date, reason: U.str(payload.reason) || 'إجازة رسمية' }); SystemService.audit(ctx, 'holiday.add', date, U.str(payload.reason)); return { ok: true }; }
};

/* ---------- HolidayService («العطلات الرسمية» — مصدر الدولة الرسمي + snapshot محلي + مزامنة كسولة) ----------
   المصادر الرسمية فقط:
     • رئاسة الجمهورية — القائمة السنوية:  https://www.presidency.eg/ar/مصر/العطلات-الرسمية/
     • وزارة العمل / مجلس الوزراء — القرارات والأيام البديلة (الأعلى أولوية):  https://www.labour.gov.eg/ar/الأخبار/
   ممنوع أي Holiday API تجاري كمصدر حقيقة. محرك التقرير مابيعملش أي fetch — بيقرأ الـsnapshot المحلي بس. */
const HolidayService = {
  SOURCES: { PRESIDENCY: { name: 'رئاسة الجمهورية (القائمة السنوية)', url: 'https://www.presidency.eg/ar/مصر/العطلات-الرسمية/', priority: 1 },
             LABOUR:     { name: 'وزارة العمل / مجلس الوزراء', url: 'https://www.labour.gov.eg/ar/الأخبار/', priority: 2 } },
  TYPE_OFFICIAL: 'OFFICIAL_HOLIDAY', STATUS_ACTIVE: 'ACTIVE', STATUS_SUPERSEDED: 'SUPERSEDED', STATUS_CANCELLED: 'CANCELLED',
  PROP_LAST_SYNC: 'GHIS_V2_HOLIDAYS_LAST_SYNC',
  /** خريطة التواريخ الفعلية للإجازات (الـobserved) — الجمعة/السبت مابيتسجلوش كعطلة رسمية (تصنيفهم Weekend مستقل) */
  /** تواريخ المناسبة المُرحَّلة ما عدا يوم الإجازة الفعلي — أي سجل قديم/يدوي فيها ملغى بقرار الترحيل الأحدث */
  transferWindow() {
    return Cache.memo('publicHolidaysWindow', CFG.CACHE_SHARED_SEC, () => {
      const out = {};
      DA.readMany('PublicHolidays').forEach(r => {
        // قرار الترحيل نفسه قائم حتى لو المناسبة اتلغت — الإلغاء مابيرجّعش التاريخ القديم للحياة
        if (U.str(r.holidayType) !== this.TYPE_OFFICIAL || [this.STATUS_ACTIVE, this.STATUS_CANCELLED].indexOf(U.str(r.status)) === -1) return;
        const orig = U.dateStr(r.originalDate), obs = U.dateStr(r.observedDate) || U.dateStr(r.date);
        if (!orig || !obs || orig === obs) return;                                  // مفيش ترحيل = مفيش نافذة
        const from = orig < obs ? orig : obs, to = orig < obs ? obs : orig;
        let k = from; let guard = 0;
        while (k <= to && guard++ < 60) { if (k !== obs) out[k] = { holidayName: U.str(r.holidayName), observedDate: obs, originalDate: orig }; k = AT.addDays(k, 1); }
      });
      return out;
    });
  },
  /** دمج تقويم الدولة مع الإجازات اليدوية: اليدوي بيغلب، إلا لو تاريخه جوه نافذة ترحيل نشطة */
  effectiveMap() {
    const gov = this.map(), manual = RulesService.officialHolidays(), win = this.transferWindow();
    const out = Object.assign({}, gov);
    Object.keys(manual).forEach(k => { if (win[k]) return; out[k] = manual[k]; });                    // المتجاهَل: مكرر لمناسبة اتنقلت
    return out;
  },
  map() {
    return Cache.memo('publicHolidays', CFG.CACHE_SHARED_SEC, () => {
      const out = {};
      DA.readMany('PublicHolidays').forEach(r => {
        if (U.str(r.holidayType) !== this.TYPE_OFFICIAL || U.str(r.status) !== this.STATUS_ACTIVE) return;
        const key = U.dateStr(r.observedDate) || U.dateStr(r.date); if (!key) return;
        const w = AT.weekday(key); if (w === 5 || w === 6) return;      // إجازة واقعة في الويك إند تفضل Weekend
        out[key] = U.str(r.holidayName) || 'إجازة رسمية';
      });
      return out;
    });
  },
  /** للواجهة: التواريخ اللي الإدخال اليدوي فيها ملغى بقرار ترحيل نشط */
  suppressedManual() { const win = this.transferWindow(); const manual = RulesService.officialHolidays(); const out = {}; Object.keys(manual).forEach(k => { if (win[k]) out[k] = win[k]; }); return out; },
  /** تقويم سنة واحدة: صف لكل مناسبة — المناسبة · التاريخ الفعلي · التاريخ الرسمي · الحالة · (تفاصيل ثانوية) */
  calendar(payload) {
    const year = U.str((payload || {}).year) || U.today().slice(0, 4);
    const rows = DA.readMany('PublicHolidays');
    const byOccasion = {};
    rows.forEach(r => {
      const date = U.dateStr(r.date); if (!date) return;
      const official = U.dateStr(r.originalDate) || date;
      const effective = U.dateStr(r.observedDate) || date;
      if (official.slice(0, 4) !== year && effective.slice(0, 4) !== year) return;
      const key = official;                                                   // المناسبة الواحدة = تاريخ رسمي واحد مهما تعددت سجلاتها
      const cleanName = U.str(r.holidayName).replace(' — التاريخ الأصلي', '').replace(' — يوم الإجازة الفعلي', '');
      const e = byOccasion[key] || (byOccasion[key] = { key, holidayName: cleanName, officialDate: official, effectiveDate: effective, status: '', origin: 'synced', source: '', lastSyncedAt: '', note: '', transferred: official !== effective, rows: [] });
      e.rows.push({ date, holidayType: U.str(r.holidayType), status: U.str(r.status) });
      if (U.str(r.holidayType) === this.TYPE_OFFICIAL) {                       // صف المناسبة الفعّال/الملغى
        e.effectiveDate = effective; e.status = U.str(r.status); e.holidayName = cleanName; e.transferred = official !== effective;
        e.source = U.str(r.source); e.lastSyncedAt = U.stamp(r.lastSyncedAt); e.note = U.str(r.note);
        e.canEditEffective = true; e.canCancel = U.str(r.status) === this.STATUS_ACTIVE; e.canReactivate = U.str(r.status) === this.STATUS_CANCELLED;
      } else if (!e.status) { e.status = U.str(r.status); e.source = e.source || U.str(r.source); }
    });
    const out = Object.keys(byOccasion).map(k => byOccasion[k]);
    // الإجازات اليدوية القديمة: مصدر منفصل، وبتتعرض كما هي (ملغاة لو جوّه نافذة ترحيل نشطة)
    const win = this.transferWindow(); const manual = RulesService.officialHolidays();
    Object.keys(manual).forEach(d => { if (U.str(d).slice(0, 4) !== year) return;
      out.push({ key: 'manual|' + d, holidayName: manual[d], officialDate: d, effectiveDate: d, origin: 'manual', transferred: false,
        status: win[d] ? 'SUPPRESSED' : this.STATUS_ACTIVE, source: 'إدخال يدوي', lastSyncedAt: '', note: win[d] ? ('المناسبة اتنقلت رسميًا إلى ' + win[d].observedDate) : '',
        canEditEffective: false, canCancel: false, canReactivate: false }); });
    out.sort((a, b) => (a.effectiveDate || '').localeCompare(b.effectiveDate || ''));
    const years = {}; rows.forEach(r => { const d = U.dateStr(r.date); if (d) years[d.slice(0, 4)] = 1; }); Object.keys(manual).forEach(d => { years[U.str(d).slice(0, 4)] = 1; });
    const cy = Number(U.today().slice(0, 4)); years[String(cy)] = 1; years[String(cy + 1)] = 1; years[year] = 1;
    const syncedCount = out.filter(h => h.origin === 'synced').length;
    return { year, holidays: out, years: Object.keys(years).sort(),
      coverage: { syncedOccasions: syncedCount, manualOccasions: out.length - syncedCount, rangeFrom: year + '-01-01', rangeTo: year + '-12-31',
        note: 'القائمة بتعرض كل المناسبات المتاحة من المصادر الرسمية للسنة دي. المصادر بتنشر الإجازات على دفعات، فاحتمال تكون القائمة مش مكتملة لحد ما الدولة تعلن الباقي — مفيش أي تاريخ بيتخلق من عندنا.' },
      lastSync: U.str(PropertiesService.getScriptProperties().getProperty(this.PROP_LAST_SYNC)) };
  },
  /** إلغاء مناسبة: الحالة بس بتتغير — صفر حذف، والسجل بيفضل في التاريخ */
  cancel(payload, ctx) {
    const date = U.dateStr(payload.date); assert(date, 'لازم تحدد تاريخ المناسبة.');
    const r = DA.readOne('PublicHolidays', { date }); assert(r, 'المناسبة مش موجودة في التقويم.');
    assert(U.str(r.holidayType) === this.TYPE_OFFICIAL, 'ده سجل تاريخي (تاريخ أصلي مُرحَّل) — الإلغاء بيتم على يوم الإجازة الفعلي بس.');
    assert(U.str(r.status) === this.STATUS_ACTIVE, 'المناسبة مش نشطة أصلاً.');
    DA.update('PublicHolidays', { date }, { status: this.STATUS_CANCELLED, lastSyncedAt: U.now(), note: U.str(payload.reason) || 'ملغاة يدويًا' });
    Cache.remove('publicHolidays'); Cache.remove('publicHolidaysWindow');
    SystemService.audit(ctx, 'holiday.cancel', date, U.str(payload.reason));
    return Object.assign({ ok: true }, this.calendar({ year: date.slice(0, 4) }));
  },
  /** إعادة تفعيل مناسبة ملغاة — ممنوع على السجلات التاريخية (التاريخ الأصلي المُرحَّل) */
  reactivate(payload, ctx) {
    const date = U.dateStr(payload.date); assert(date, 'لازم تحدد تاريخ المناسبة.');
    const r = DA.readOne('PublicHolidays', { date }); assert(r, 'المناسبة مش موجودة في التقويم.');
    assert(U.str(r.holidayType) === this.TYPE_OFFICIAL, 'ده سجل تاريخي لتاريخ أصلي اتنقلت منه المناسبة — مينفعش يتفعّل كإجازة.');
    assert(U.str(r.status) === this.STATUS_CANCELLED, 'المناسبة مش ملغاة.');
    DA.update('PublicHolidays', { date }, { status: this.STATUS_ACTIVE, lastSyncedAt: U.now(), note: U.str(payload.reason) || 'أُعيد تفعيلها يدويًا' });
    Cache.remove('publicHolidays'); Cache.remove('publicHolidaysWindow');
    SystemService.audit(ctx, 'holiday.reactivate', date, U.str(payload.reason));
    return Object.assign({ ok: true }, this.calendar({ year: date.slice(0, 4) }));
  },
  list() { return { holidays: DA.readMany('PublicHolidays').map(r => ({ date: U.dateStr(r.date), holidayName: U.str(r.holidayName), holidayType: U.str(r.holidayType), source: U.str(r.source), sourceDate: U.dateStr(r.sourceDate), originalDate: U.dateStr(r.originalDate), observedDate: U.dateStr(r.observedDate), status: U.str(r.status), lastSyncedAt: U.stamp(r.lastSyncedAt), note: U.str(r.note) })).sort((a, b) => a.date.localeCompare(b.date)), lastSync: U.str(PropertiesService.getScriptProperties().getProperty(this.PROP_LAST_SYNC)) , suppressedManual: this.suppressedManual() }; },
  /** الطبقة الوحيدة اللي بتعمل اتصال خارجي — محرك الحساب مابيلمسهاش إطلاقًا */
  _fetch(url) { try { const res = UrlFetchApp.fetch(url, { muteHttpExceptions: true, followRedirects: true, validateHttpsCertificates: true }); return res.getResponseCode() === 200 ? res.getContentText() : null; } catch (e) { return null; } },
  /** استخراج (تاريخ, مناسبة) من صفحة عربية: yyyy-mm-dd أو dd/mm/yyyy أو "8 أكتوبر 2026" */
  AR_MONTHS: { 'يناير': 1, 'فبراير': 2, 'مارس': 3, 'أبريل': 4, 'إبريل': 4, 'مايو': 5, 'يونيو': 6, 'يونية': 6, 'يوليو': 7, 'يولية': 7, 'أغسطس': 8, 'سبتمبر': 9, 'أكتوبر': 10, 'نوفمبر': 11, 'ديسمبر': 12 },
  /** اسم المناسبة من النص المجاور: يشيل أي تاريخ ملتصق ويقصّ على آخر فاصل منطقي — صفر اختراع أسماء */
  _cleanName(around) {
    if (around && typeof around === 'object') {
      // المصادر الرسمية المصرية بتكتب «التاريخ — المناسبة»، فالنص اللي بعد التاريخ هو الاسم؛ ولو فاضي بنرجع للنص اللي قبله
      const after = this._cleanName(around.after); if (after) return after;
      return this._cleanName(around.before);
    }
    let t = U.str(around)
      .replace(/(\d{4})-(\d{1,2})-(\d{1,2})/g, ' ')
      .replace(/(\d{1,2})\s*[\/\-]\s*(\d{1,2})\s*[\/\-]\s*(\d{4})/g, ' ')
      .replace(new RegExp('(\\d{1,2})\\s*(' + Object.keys(this.AR_MONTHS).join('|') + ')\\s*(\\d{4})', 'g'), ' ')
      .replace(/\s+/g, ' ').trim();
    const cut = Math.max(t.lastIndexOf('·'), t.lastIndexOf('|'), t.lastIndexOf('.'), t.lastIndexOf('،'));
    if (cut > 0 && t.length - cut < 60) t = t.slice(cut + 1).trim();
    return t.slice(0, 60).trim();
  },
  _parse(html, years) {
    if (!html) return [];
    const text = String(html).replace(/<script[\s\S]*?<\/script>/gi, ' ').replace(/<style[\s\S]*?<\/style>/gi, ' ').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ');
    // كل التواريخ الأول بمواضعها، عشان اسم كل مناسبة يتقص عند حدود التاريخ اللي قبلها/بعدها — مفيش تداخل بين المناسبات
    const hits = []; let m;
    const reIso = /(\d{4})-(\d{1,2})-(\d{1,2})/g; while ((m = reIso.exec(text))) hits.push({ i: m.index, len: m[0].length, y: m[1], mo: Number(m[2]), d: Number(m[3]) });
    const reSlash = /(\d{1,2})\s*[\/\-]\s*(\d{1,2})\s*[\/\-]\s*(\d{4})/g; while ((m = reSlash.exec(text))) hits.push({ i: m.index, len: m[0].length, y: m[3], mo: Number(m[2]), d: Number(m[1]) });
    const reAr = new RegExp('(\\d{1,2})\\s*(' + Object.keys(this.AR_MONTHS).join('|') + ')\\s*(\\d{4})', 'g');
    while ((m = reAr.exec(text))) hits.push({ i: m.index, len: m[0].length, y: m[3], mo: this.AR_MONTHS[m[2]], d: Number(m[1]) });
    hits.sort((a, b) => a.i - b.i);
    const found = {};
    hits.forEach((h, k) => {
      if (years.indexOf(String(h.y)) === -1) return;
      const key = h.y + '-' + ('0' + h.mo).slice(-2) + '-' + ('0' + h.d).slice(-2); if (!U.isDate(key)) return;
      const prevEnd = k > 0 ? hits[k - 1].i + hits[k - 1].len : 0;
      const nextStart = k < hits.length - 1 ? hits[k + 1].i : text.length;
      const name = this._cleanName({ before: text.slice(Math.max(prevEnd, h.i - 70), h.i), after: text.slice(h.i + h.len, Math.min(nextStart, h.i + h.len + 70)) });
      if (!found[key] || (name && name.length > found[key].length)) found[key] = name;
    });
    return Object.keys(found).map(k => ({ date: k, name: found[k] || 'إجازة رسمية' }));
  },

  /**
   * المزامنة: بتجيب سنة دلوقتي والسنة الجاية من المصدرين، وبتحفظ snapshot محلي.
   * • فشل الاتصال = صفر حذف — الـsnapshot القديم بيفضل زي ما هو والتقرير بيكمّل شغله.
   * • قرار وزارة العمل/مجلس الوزراء (priority 2) بيغلب القائمة السنوية عند التعارض في نفس التاريخ.
   * • الترحيل (يوم بديل) بيتسجّل بـoriginalDate/observedDate والتاريخ الأصلي بيتعلّم SUPERSEDED.
   */
  syncOfficialHolidays_(opts) {
    opts = opts || {}; const now = U.now();
    const y = Number((opts.year || U.today()).slice(0, 4)); const years = opts.singleYear ? [String(y)] : [String(y), String(y + 1)];
    const report = { years, fetched: {}, added: 0, updated: 0, skipped: 0, failures: [], usedSnapshot: false };
    const incoming = {};
    [this.SOURCES.PRESIDENCY, this.SOURCES.LABOUR].forEach(src => {
      const html = this._fetch(src.url);
      if (!html) { report.failures.push(src.name); report.fetched[src.name] = 0; return; }
      const rows = this._parse(html, years); report.fetched[src.name] = rows.length;
      rows.forEach(r => { const cur = incoming[r.date]; if (!cur || src.priority >= cur.priority) incoming[r.date] = { name: r.name, source: src.name, priority: src.priority }; });
    });
    if (!Object.keys(incoming).length) { report.ok = false; report.usedSnapshot = true; report.note = 'تعذّر الوصول للمصادر الرسمية — تم الإبقاء على آخر snapshot صالح بالكامل (صفر حذف).'; Logger.log(JSON.stringify(report, null, 2)); return report; }
    const existing = {}; DA.readMany('PublicHolidays').forEach(r => { existing[U.dateStr(r.date)] = r; });
    const ops = [];
    Object.keys(incoming).forEach(date => {
      const inc = incoming[date]; const cur = existing[date];
      const data = { date, holidayName: inc.name, holidayType: this.TYPE_OFFICIAL, source: inc.source, sourceDate: U.today(), originalDate: '', observedDate: date, status: this.STATUS_ACTIVE, lastSyncedAt: now, note: '' };
      if (!cur) { ops.push({ op: 'insert', table: 'PublicHolidays', data }); report.added++; return; }
      // قرار يدوي/ترحيل محفوظ محليًا ماينمسحش بمزامنة قائمة سنوية أقل أولوية
      if (U.str(cur.status) === this.STATUS_CANCELLED) { DA.update('PublicHolidays', { date }, { lastSyncedAt: now }); report.skipped++; return; }   // إلغاء يدوي: المزامنة ماتفعّلهوش تاني
      if (U.str(cur.status) === this.STATUS_SUPERSEDED && inc.priority < this.SOURCES.LABOUR.priority) { report.skipped++; return; }
      if (U.str(cur.holidayName) === data.holidayName && U.str(cur.status) === data.status && U.str(cur.holidayType) === data.holidayType) { DA.update('PublicHolidays', { date }, { lastSyncedAt: now, source: inc.source }); report.skipped++; return; }
      ops.push({ op: 'update', table: 'PublicHolidays', where: { date }, patch: { holidayName: data.holidayName, holidayType: data.holidayType, source: inc.source, sourceDate: data.sourceDate, status: this.STATUS_ACTIVE, lastSyncedAt: now } }); report.updated++;
    });
    if (ops.length) DA.batch(ops);
    report.ok = report.failures.length < 2;                                  // مصدر واحد ناجح على الأقل
    if (report.ok) PropertiesService.getScriptProperties().setProperty(this.PROP_LAST_SYNC, now);   // آخر مزامنة ناجحة بس
    else report.usedSnapshot = true;                                          // الفشل مابيمسحش أي سجل — الـsnapshot زي ما هو
    Cache.remove('publicHolidays'); Cache.remove('publicHolidaysWindow');
    Logger.log(JSON.stringify(report, null, 2)); return report;
  },
  /** lazy refresh آمن: لو آخر مزامنة أقدم من 24 ساعة. أي فشل مابيوقفش التقرير إطلاقًا. */
  ensureFresh_() {
    try {
      const last = U.str(PropertiesService.getScriptProperties().getProperty(this.PROP_LAST_SYNC));
      if (last && (Date.now() - Date.parse(last)) < 24 * 3600 * 1000) return false;
      this.syncOfficialHolidays_({}); return true;
    } catch (e) { return false; }
  },
  /** تسجيل/تعديل يوم بديل أو قرار جديد يدويًا (الأحدث يفوز) */
  setObserved(payload, ctx) {
    const original = U.dateStr(payload.originalDate), observed = U.dateStr(payload.observedDate);
    assert(original && observed, 'لازم تحدد التاريخ الأصلي ويوم الإجازة الفعلي.');
    const name = U.str(payload.holidayName) || 'إجازة رسمية'; const now = U.now();
    const src = U.str(payload.source) || this.SOURCES.LABOUR.name; const sourceDate = U.dateStr(payload.sourceDate) || U.today();
    const put = (date, data) => { if (DA.readOne('PublicHolidays', { date })) DA.update('PublicHolidays', { date }, data); else DA.insert('PublicHolidays', Object.assign({ date }, data)); };
    // قرار ترحيل أحدث لنفس المناسبة: أي يوم إجازة فعلي قديم لنفس التاريخ الأصلي بيتحوّل SUPERSEDED — القرار الأحدث يفوز
    DA.update('PublicHolidays', r => U.dateStr(r.originalDate) === original && U.dateStr(r.date) !== observed && U.dateStr(r.date) !== original && U.str(r.status) === this.STATUS_ACTIVE,
      { holidayType: 'OBSERVED_DATE_SUPERSEDED', status: this.STATUS_SUPERSEDED, lastSyncedAt: now, note: 'يوم إجازة سابق — اتنقلت إلى ' + observed });
    put(observed, { holidayName: name, holidayType: this.TYPE_OFFICIAL, source: src, sourceDate, originalDate: original, observedDate: observed, status: this.STATUS_ACTIVE, lastSyncedAt: now, note: U.str(payload.note) });
    if (original !== observed) put(original, { holidayName: name + ' — التاريخ الأصلي', holidayType: 'ORIGINAL_DATE_SUPERSEDED', source: src, sourceDate, originalDate: original, observedDate: observed, status: this.STATUS_SUPERSEDED, lastSyncedAt: now, note: 'اتنقلت إلى ' + observed });
    Cache.remove('publicHolidays'); Cache.remove('publicHolidaysWindow'); SystemService.audit(ctx, 'holiday.setObserved', original + '→' + observed, name);
    return Object.assign({ ok: true }, this.list());
  },
  /** Force Manual Sync — نفس syncOfficialHolidays_ بالظبط (صفر تنفيذ تاني)، بسنة الخادم (Africa/Cairo) مهما أرسلت الواجهة، وبقفل يمنع التشغيل المتوازي */
  sync(payload, ctx) {
    const serverYear = U.today().slice(0, 4);
    const asked = U.str((payload || {}).year);                                // السنة المختارة من محدِّد السنة
    const year = /^\d{4}$/.test(asked) && Number(asked) >= 2000 && Number(asked) <= Number(serverYear) + 5 ? asked : serverYear;
    return U.withLock(() => {
      const r = this.syncOfficialHolidays_({ year: year + '-01-01', singleYear: true });   // السنة المختارة كاملة 1/1 → 31/12، Force بدون شرط الـ24 ساعة
      if (ctx) SystemService.audit(ctx, 'holiday.sync', (r.years || []).join(','), 'added=' + r.added + ' updated=' + r.updated + ' failures=' + (r.failures || []).length);
      const out = Object.assign({ year: year }, r, this.list());   // الاستجابة بشكلها الأصلي؛ الواجهة بتعيد تحميل تقويم السنة بعدها
      out.count = (out.holidays || []).filter(h => U.str(h.date).slice(0, 4) === year).length;
      return out;
    }, 60000);
  }
};

/* ---------- AttendanceAdjustService («إعفاءات التأخير والخصومات»: الأذونات · نسيان التسجيل · الجزاءات · استثناءات الانصراف · الإجازات · تعديلات الحضور) ---------- */
const AttendanceAdjustService = {
  approvedLeaves() { const map = {}; DA.readMany('ApprovedLeaves').forEach(r => { const code = U.str(r.code), from = U.dateStr(r.from), to = U.dateStr(r.to); if (!code || !from || !to) return; (map[code] = map[code] || []).push({ from, to, reason: U.str(r.reason) || 'إجازة معتمدة' }); }); return map; },
  /** OLD getAdjustmentsMap_ — latest row per (code|date, field) wins */
  adjustmentsMap() {
    const map = {};
    DA.readMany('Adjustments').forEach(r => { const code = U.str(r.code), date = U.dateStr(r.date); if (!code || !date) return; const key = code + '|' + date; const ts = Date.parse(r.timestamp) || 0; const entry = (map[key] = map[key] || {});
      const keepLatest = (field, val) => { if (!entry[field] || ts >= entry[field]._ts) entry[field] = { value: val, _ts: ts }; };
      const type = U.str(r.type), value = r.value;
      if (type === 'تعديل وقت الحضور') keepLatest('checkinEdit', value);
      else if (type === 'تعديل وقت الانصراف') keepLatest('checkoutEdit', value);
      else if (type === 'تسجيل حضور يدوي') keepLatest('forcePresent', value);
      else if (type === 'تسجيل غياب يدوي') keepLatest('forceAbsent', true);
      else if (type === 'استثناء اليوم من الحساب') keepLatest('exclude', true); });
    Object.keys(map).forEach(k => { const e = map[k]; ['forceAbsent', 'exclude'].forEach(f => { if (e[f]) e[f] = e[f].value; }); });
    return map;
  },
  ADJ_TYPES: ['تعديل وقت الحضور', 'تعديل وقت الانصراف', 'تسجيل حضور يدوي', 'تسجيل غياب يدوي', 'استثناء اليوم من الحساب'],
  checkoutExceptions() { return DA.readMany('CheckoutExceptions').filter(r => U.str(r.scopeType) && U.dateStr(r.from) && U.dateStr(r.to)).map(r => ({ scopeType: U.str(r.scopeType), scopeValue: U.str(r.scopeValue), from: U.dateStr(r.from), to: U.dateStr(r.to), user: U.str(r.user), timestamp: U.stamp(r.timestamp) })); },
  /** OLD isCheckoutOptionalForEmployeeDate_ */
  checkoutOptional(exceptions, emp, dateKey) {
    if (exceptions.some(ex => ex.scopeType === 'Employee' && ex.scopeValue === emp.code && dateKey >= ex.from && dateKey <= ex.to)) return true;
    if (exceptions.some(ex => ex.scopeType === 'Department' && ex.scopeValue === emp.dept && dateKey >= ex.from && dateKey <= ex.to)) return true;
    return exceptions.some(ex => ex.scopeType === 'All' && dateKey >= ex.from && dateKey <= ex.to);
  },
  _byCode(table) { const idx = {}; DA.readMany(table).forEach(r => { const c = U.str(r.code); if (c) (idx[c] = idx[c] || []).push(r); }); return idx; },
  permissionAdd(payload, ctx) { const code = U.str(payload.code), date = U.dateStr(payload.date); assert(code, 'كود الموظف مطلوب.'); EmployeeService._exists(code); assert(date, 'تاريخ غير صحيح.'); DA.insert('PermissionsLog', { code, date, user: ctx.session.username, timestamp: U.now() }); SystemService.audit(ctx, 'permission.add', code, date); return { ok: true }; },
  forgotCheckinAdd(payload, ctx) { const code = U.str(payload.code), date = U.dateStr(payload.date); assert(code, 'كود الموظف مطلوب.'); EmployeeService._exists(code); assert(date, 'تاريخ غير صحيح.'); DA.insert('ForgotCheckinLog', { code, date, user: ctx.session.username, timestamp: U.now() }); SystemService.audit(ctx, 'forgotCheckin.add', code, date); return { ok: true }; },
  extraPenaltyAdd(payload, ctx) { const code = U.str(payload.code), date = U.dateStr(payload.date); assert(code, 'كود الموظف مطلوب.'); EmployeeService._exists(code); assert(date, 'تاريخ غير صحيح.'); const days = Number(payload.days); assert(days > 0, 'عدد الأيام لازم يكون رقم أكبر من صفر.'); DA.insert('ExtraPenaltiesLog', { code, date, reason: U.str(payload.reason), days, user: ctx.session.username, timestamp: U.now() }); SystemService.audit(ctx, 'extraPenalty.add', code, date + ' · ' + days + ' يوم'); return { ok: true }; },
  checkoutExceptionAdd(payload, ctx) { const type = U.str(payload.scopeType); assert(['All', 'Department', 'Employee'].includes(type), 'نطاق الاستثناء لازم يكون "All" أو "Department" أو "Employee".'); const value = U.str(payload.scopeValue); assert(type === 'All' || value, 'قيمة النطاق مطلوبة لـ"' + type + '".'); const from = U.dateStr(payload.from), to = U.dateStr(payload.to); assert(from && to, 'لازم تحدد من تاريخ ولحد تاريخ صحيحين.'); assert(from <= to, 'تاريخ البداية لازم يكون قبل أو يساوي تاريخ النهاية.'); if (type === 'Employee') EmployeeService._exists(value); DA.insert('CheckoutExceptions', { scopeType: type, scopeValue: value, from, to, user: ctx.session.username, timestamp: U.now() }); SystemService.audit(ctx, 'checkoutException.add', type + ':' + value, from + '→' + to); return { ok: true }; },
  leaveAdd(payload, ctx) { const code = U.str(payload.code); assert(code, 'كود الموظف مطلوب.'); EmployeeService._exists(code); const from = U.dateStr(payload.from), to = U.dateStr(payload.to); assert(from && to && from <= to, 'لازم تحدد من تاريخ ولحد تاريخ صحيحين.'); DA.insert('ApprovedLeaves', { code, from, to, reason: U.str(payload.reason) || 'إجازة معتمدة' }); SystemService.audit(ctx, 'leave.add', code, from + '→' + to); return { ok: true }; },
  adjustmentAdd(payload, ctx) { const code = U.str(payload.code), date = U.dateStr(payload.date), type = U.str(payload.type); assert(code, 'كود الموظف مطلوب.'); EmployeeService._exists(code); assert(date, 'تاريخ غير صحيح.'); assert(this.ADJ_TYPES.includes(type), 'نوع التعديل غير معروف.'); let value = U.str(payload.value); if (type === 'تعديل وقت الحضور' || type === 'تعديل وقت الانصراف' || type === 'تسجيل حضور يدوي') { value = AT.timeText(value, ''); assert(value, 'لازم تحدد وقت صحيح (HH:mm).'); } else value = ''; DA.insert('Adjustments', { code, date, type, value, reason: U.str(payload.reason), user: ctx.session.username, timestamp: U.now() }); SystemService.audit(ctx, 'adjustment.add', code, date + ' · ' + type); return { ok: true }; },
  /** one request → everything the «إعفاءات التأخير والخصومات» screen lists */
  list(payload) {
    const from = U.dateStr(payload.from), to = U.dateStr(payload.to); const inRange = k => (!from || k >= from) && (!to || k <= to);
    const names = {}; DA.readMany('Employees').forEach(r => { names[U.str(r.code)] = U.str(r.nameAr); });
    const nm = c => names[c] || c;
    return {
      permissions: DA.readMany('PermissionsLog').map(r => ({ code: U.str(r.code), nameAr: nm(U.str(r.code)), date: U.dateStr(r.date), user: U.str(r.user), timestamp: U.stamp(r.timestamp) })).filter(x => inRange(x.date)),
      forgotCheckins: DA.readMany('ForgotCheckinLog').map(r => ({ code: U.str(r.code), nameAr: nm(U.str(r.code)), date: U.dateStr(r.date), user: U.str(r.user), timestamp: U.stamp(r.timestamp) })).filter(x => inRange(x.date)),
      extraPenalties: DA.readMany('ExtraPenaltiesLog').map(r => ({ code: U.str(r.code), nameAr: nm(U.str(r.code)), date: U.dateStr(r.date), reason: U.str(r.reason), days: Number(r.days) || 0, user: U.str(r.user), timestamp: U.stamp(r.timestamp) })).filter(x => inRange(x.date)),
      checkoutExceptions: this.checkoutExceptions(),
      leaves: DA.readMany('ApprovedLeaves').map(r => ({ code: U.str(r.code), nameAr: nm(U.str(r.code)), from: U.dateStr(r.from), to: U.dateStr(r.to), reason: U.str(r.reason) })),
      adjustments: DA.readMany('Adjustments').map(r => ({ code: U.str(r.code), nameAr: nm(U.str(r.code)), date: U.dateStr(r.date), type: U.str(r.type), value: U.str(r.value), reason: U.str(r.reason), user: U.str(r.user), timestamp: U.stamp(r.timestamp) })).filter(x => inRange(x.date)),
      adjustmentTypes: this.ADJ_TYPES
    };
  }
};

/* ---------- LateWaiverService («إعفاءات التأخير المعتمدة» — طبقة مستقلة فوق البصمة: مابتعدّلش ولا بتحذف أي تأخير أصلي) ---------- */
const LateWaiverService = {
  map() { const map = {}; DA.readMany('LateWaivers').forEach(r => { const code = U.str(r.code), date = U.dateStr(r.date); const mins = Number(r.minutes) || 0; if (!code || !date || mins <= 0) return; const k = code + '|' + date; map[k] = (map[k] || 0) + mins; }); return map; },
  _validate(cfg) { const code = U.str(cfg.code); assert(code, 'لازم تختار الموظف.'); const date = U.dateStr(cfg.date); assert(date, 'لازم تحدد تاريخ صحيح.'); const raw = U.str(cfg.minutes); assert(/^\d+$/.test(raw), 'دقائق الإعفاء لازم تكون رقم صحيح موجب.'); const minutes = Number(raw); assert(minutes > 0, 'دقائق الإعفاء لازم تكون أكبر من صفر.'); assert(minutes <= 1440, 'دقائق الإعفاء مش منطقية (أكتر من يوم كامل).'); const reason = U.str(cfg.reason); assert(reason, 'لازم تكتب سبب الإعفاء.'); const approvedBy = U.str(cfg.approvedBy); assert(approvedBy, 'لازم تحدد جهة الموافقة.'); return { code, date, minutes, reason, approvedBy }; },
  list(payload) { const from = U.dateStr(payload.from), to = U.dateStr(payload.to); const names = {}; DA.readMany('Employees').forEach(r => { names[U.str(r.code)] = U.str(r.nameAr); }); return { items: DA.readMany('LateWaivers').map(r => ({ id: U.str(r.id), code: U.str(r.code), nameAr: names[U.str(r.code)] || U.str(r.code), date: U.dateStr(r.date), minutes: Number(r.minutes) || 0, reason: U.str(r.reason), approvedBy: U.str(r.approvedBy), createdBy: U.str(r.createdBy), createdAt: U.stamp(r.createdAt) })).filter(x => x.code && x.date && (!from || x.date >= from) && (!to || x.date <= to)) }; },
  add(payload, ctx) { const v = this._validate(payload); EmployeeService._exists(v.code); const id = U.uuid(); DA.insert('LateWaivers', { code: v.code, date: v.date, minutes: v.minutes, reason: v.reason, approvedBy: v.approvedBy, createdBy: ctx.session.username, createdAt: U.now(), id }); SystemService.audit(ctx, 'lateWaiver.add', v.code, v.date + ' · ' + v.minutes + ' د'); return { ok: true, id }; },
  update(payload, ctx) { const id = U.str(payload.id); assert(id, 'سجل الإعفاء مش موجود.'); const v = this._validate(payload); assert(DA.update('LateWaivers', { id }, { code: v.code, date: v.date, minutes: v.minutes, reason: v.reason, approvedBy: v.approvedBy, createdBy: ctx.session.username, createdAt: U.now() }) > 0, 'سجل الإعفاء مش موجود.'); SystemService.audit(ctx, 'lateWaiver.update', v.code, id); return { ok: true }; },
  remove(payload, ctx) { const id = U.str(payload.id); assert(DA.remove('LateWaivers', { id }) > 0, 'سجل الإعفاء مش موجود.'); SystemService.audit(ctx, 'lateWaiver.delete', id, ''); return { ok: true }; },
  /** OLD getDayLateInfo_ — one day, live, to help the user fill the waiver form */
  dayInfo(payload) { const code = U.str(payload.code), date = U.dateStr(payload.date); assert(code, 'لازم تختار الموظف.'); assert(date, 'لازم تحدد تاريخ صحيح (yyyy-MM-dd).'); const ctx = AttendanceCalc.buildContext(date, date); const rep = AttendanceCalc.employeeReport(code, date, date, ctx); const day = (rep.days || [])[0] || {}; const sm = rep.summary || {}; return { info: { date, status: day.status || '', checkInActual: day.checkInActual || '-', checkInOfficial: day.checkInOfficial || '-', lateMinutes: Number(day.lateMinutes) || 0, lateWaiverMinutes: Number(day.lateWaiverMinutes) || 0, lateMinutesFinal: (day.lateMinutesFinal !== undefined ? day.lateMinutesFinal : (Number(day.lateMinutes) || 0)), monthLateMinutes: sm.lateMinutesTotal || 0 } }; }
};

/* ---------- AttendanceCalc — the OLD calculation engine (classifyDay_ / buildEmployeeReport_ / summarizeDays_ / withDeductions) ---------- */
const AttendanceCalc = {
  /** Reads the attendance file's YYYY-MM sheets ONCE per request (through DA only). EmployeeCode is the key. */
  pullAttendance(fromKey, toKey) {
    const id = AttendanceService.attId(); if (!id) fail(ERR.SERVER_ERROR, 'تعذّر الوصول لشيت نظام البصمة — ATTENDANCE_SPREADSHEET_ID غير مضبوط.');
    const result = {}; AT.eachDay(fromKey, toKey).forEach(k => { result[k] = {}; });
    const months = [...new Set(AT.eachDay(fromKey, toKey).map(k => AT.monthKey(k)))];
    const C = CFG.ATT_PUNCH_COL;
    months.forEach(mk => {
      let values; try { values = DA.extReadColumns(id, mk, C.CHECKOUT_DISTANCE); } catch (e) { return; }   // a month with no sheet is simply absent (OLD behavior)
      values.forEach(row => {
        const code = U.str(row[C.CODE - 1]); if (!code) return;
        const dv = row[C.DATE - 1]; const key = (dv instanceof Date) ? AT.key(dv) : U.dateStr(dv); if (!key || !result[key]) return;
        const ci = row[C.CHECKIN_TIME - 1], co = row[C.CHECKOUT_TIME - 1];
        result[key][code] = { checkIn: ci instanceof Date ? ci : (U.str(ci) ? new Date(key + 'T' + AT.timeText(ci, '00:00') + ':00') : null), checkOut: co instanceof Date ? co : (U.str(co) ? new Date(key + 'T' + AT.timeText(co, '00:00') + ':00') : null), checkInLocation: U.str(row[C.CHECKIN_LOCATION - 1]), checkInDistance: row[C.CHECKIN_DISTANCE - 1], checkOutLocation: U.str(row[C.CHECKOUT_LOCATION - 1]), checkOutDistance: row[C.CHECKOUT_DISTANCE - 1] };
      });
    });
    return result;
  },
  /** OLD buildContext_ — every reference table read ONCE per request (no per-employee reads). */
  buildContext(fromKey, toKey) {
    const exempt = ExemptDepartmentService.activeSet();
    const employees = DA.readMany('Employees').map(r => EmployeeService._shape(r, exempt));
    const employeesByCode = {}; employees.forEach(e => { employeesByCode[e.code] = e; });
    const overrides = DA.readMany('ScheduleOverrides').map(r => ({ code: U.str(r.code), from: U.dateStr(r.from), to: U.dateStr(r.to), checkInMinutes: AT.toMinutes(r.checkIn), checkOutMinutes: AT.toMinutes(r.checkOut) })).filter(o => o.from && o.to);
    return { today: U.today(), nowMinutes: AT.toMinutes(Utilities.formatDate(new Date(), CFG.TZ, 'HH:mm')), employees, employeesByCode, scheduleRules: RulesService.scheduleRules(), deductionRules: RulesService.deductionRules(), lateWaivers: LateWaiverService.map(), officialHolidays: HolidayService.effectiveMap(), /* عطلات الدولة + الإجازات اليدوية (اليدوي بيغلب، إلا داخل نافذة ترحيل نشطة) */ approvedLeaves: AttendanceAdjustService.approvedLeaves(), adjustmentsMap: AttendanceAdjustService.adjustmentsMap(), scheduleOverrides: overrides, checkoutExceptions: AttendanceAdjustService.checkoutExceptions(), permissionsLog: AttendanceAdjustService._byCode('PermissionsLog'), forgotCheckinLog: AttendanceAdjustService._byCode('ForgotCheckinLog'), extraPenaltiesLog: AttendanceAdjustService._byCode('ExtraPenaltiesLog'), attendanceIndex: fromKey && toKey ? this.pullAttendance(fromKey, toKey) : {} };
  },
  _ruleForDate(rules, dateKey) { for (const r of rules) if (dateKey >= r.from && dateKey <= r.to) return r; return null; },
  _overrideFor(overrides, code, dateKey) { const o = overrides.find(x => x.code === code && dateKey >= x.from && dateKey <= x.to); return o || null; },
  _base(dateKey, status, scheduleRule, reason) { const w = AT.weekday(dateKey); return { dateKey, weekdayName: WEEKDAY_NAMES_[w], status, label: reason || status, checkInActual: '-', checkOutActual: '-', checkInOfficial: scheduleRule ? AT.fromMinutes(scheduleRule.checkInMinutes) : '-', checkOutOfficial: scheduleRule ? AT.fromMinutes(scheduleRule.checkOutMinutes) : '-', lateMinutes: 0, earlyLeaveMinutes: 0, missingCheckout: false, holidayReason: reason || '', deduction: 0 }; },
  /** OLD classifyDay_ — identical order of rules */
  classifyDay(emp, dateKey, rawPunch, ctx) {
    const adj = ctx.adjustmentsMap[emp.code + '|' + dateKey] || {};
    // أولوية 1: إجازة رسمية للدولة — قبل الويك إند وقبل قاعدة المستقبل وقبل قاعدة «قبل موعد الحضور».
    // بتخرج اليوم من أيام العمل والغياب والتأخير وكل الخصومات، ومابتفسدش أي تسجيل حضور موجود.
    if (ctx.officialHolidays[dateKey] && !adj.exclude && !(emp.hireDate && dateKey < emp.hireDate) && !(emp.endDate && dateKey > emp.endDate)) { const h = this._base(dateKey, 'اجازة رسمية', null, ctx.officialHolidays[dateKey]);
      // عرض فقط: لو الموظف سجّل في الإجازة الرسمية، التسجيل بيفضل ظاهر زي ما هو — صفر تأثير على أي إجمالي أو خصم
      if (rawPunch && rawPunch.checkIn) h.checkInActual = AT.timeText(rawPunch.checkIn, '-');
      if (rawPunch && rawPunch.checkOut) h.checkOutActual = AT.timeText(rawPunch.checkOut, '-');
      return h; }
    // يوم في المستقبل (بعد تاريخ النهاردة بتوقيت القاهرة) = محايد تمامًا: مفيش غياب/تأخير/انصراف مبكر/خصم،
    // ومابيدخلش في أيام العمل المطلوبة ولا نسبة الحضور ولا أي إجمالي.
    if (dateKey > (ctx.today || U.today())) return this._base(dateKey, '-', null);
    if (adj.exclude) return this._base(dateKey, 'مستثنى (تعديل يدوي)', null);
    if (emp.hireDate && dateKey < emp.hireDate) return this._base(dateKey, '-', null);
    if (emp.endDate && dateKey > emp.endDate) return this._base(dateKey, '-', null);
    if (emp.exempt) return this._base(dateKey, 'معفى', null);
    if (ctx.officialHolidays[dateKey]) return this._base(dateKey, 'اجازة رسمية', null, ctx.officialHolidays[dateKey]);
    const weekday = AT.weekday(dateKey);
    if (weekday === 5 || weekday === 6) return this._base(dateKey, 'اجازة أسبوعية', null);
    const leaves = ctx.approvedLeaves[emp.code] || [];
    const activeLeave = leaves.find(l => dateKey >= l.from && dateKey <= l.to);
    if (activeLeave) return this._base(dateKey, 'اجازة معتمدة', null, activeLeave.reason);
    let checkIn = rawPunch.checkIn, checkOut = rawPunch.checkOut;
    if (adj.forceAbsent) { checkIn = null; checkOut = null; }
    else {
      if (adj.checkinEdit) checkIn = new Date(dateKey + 'T' + AT.timeText(adj.checkinEdit.value, '00:00') + ':00');
      if (adj.checkoutEdit) checkOut = new Date(dateKey + 'T' + AT.timeText(adj.checkoutEdit.value, '00:00') + ':00');
      if (adj.forcePresent && !checkIn) checkIn = new Date(dateKey + 'T' + AT.timeText(adj.forcePresent.value, '00:00') + ':00');
    }
    const ovr = this._overrideFor(ctx.scheduleOverrides, emp.code, dateKey);
    const scheduleRule = ovr || this._ruleForDate(ctx.scheduleRules, dateKey);
    const deductionRule = this._ruleForDate(ctx.deductionRules, dateKey);
    // اليوم الحالي قبل موعد الحضور الرسمي لنفس الموظف/اليوم (قاعدة خاصة إن وُجدت وإلا القاعدة العامة) = محايد،
    // مش غياب: مابيدخلش في أيام العمل المطلوبة ولا الغياب ولا أي خصم ولا نسبة الحضور. بعد الموعد يرجع المنطق كما هو.
    if (!checkIn && dateKey === (ctx.today || U.today()) && scheduleRule && ctx.nowMinutes !== undefined && ctx.nowMinutes < scheduleRule.checkInMinutes) return this._base(dateKey, '-', scheduleRule);
    if (!checkIn) { const result = this._base(dateKey, 'غياب', scheduleRule); result.deduction = deductionRule ? deductionRule.absenceValue : 0; return result; }
    const actualInMinutes = checkIn.getHours() * 60 + checkIn.getMinutes();
    const lateMinutes = scheduleRule ? Math.max(0, actualInMinutes - scheduleRule.checkInMinutes) : 0;
    let earlyLeaveMinutes = 0;
    const checkoutOptional = AttendanceAdjustService.checkoutOptional(ctx.checkoutExceptions, emp, dateKey);
    const missingCheckout = checkoutOptional ? false : !checkOut;
    if (checkOut && scheduleRule) { const actualOutMinutes = checkOut.getHours() * 60 + checkOut.getMinutes(); earlyLeaveMinutes = Math.max(0, scheduleRule.checkOutMinutes - actualOutMinutes); }
    return { dateKey, weekdayName: WEEKDAY_NAMES_[weekday], status: 'حاضر', label: lateMinutes > 0 ? (lateMinutes + ' د تأخير') : 'حضور', checkInActual: AT.timeText(checkIn, '-'), checkOutActual: checkOut ? AT.timeText(checkOut, '-') : '-', checkInOfficial: scheduleRule ? AT.fromMinutes(scheduleRule.checkInMinutes) : '-', checkOutOfficial: scheduleRule ? AT.fromMinutes(scheduleRule.checkOutMinutes) : '-', lateMinutes, earlyLeaveMinutes, missingCheckout, holidayReason: '', deduction: null };
  },
  /** OLD summarizeDays_ — untouched formulas */
  summarize(days, lateDeductionTotal, earlyDeductionTotal, lateWaiverMinutesTotal, lateDeductionFinal) {
    lateWaiverMinutesTotal = Number(lateWaiverMinutesTotal) || 0;
    if (lateDeductionFinal === undefined || lateDeductionFinal === null) lateDeductionFinal = lateDeductionTotal;
    let workDaysRequired = 0, presentDays = 0, absentDays = 0, absentDaysNoRule = 0, leaveDays = 0, lateCount = 0, lateMinutesTotal = 0, earlyCount = 0, earlyMinutesTotal = 0, missingCheckoutDays = 0, absenceDeductionTotal = 0, missingCheckoutDeductionTotal = 0;
    days.forEach(d => {
      if (d.status === 'حاضر' || d.status === 'غياب') workDaysRequired++;
      if (d.status === 'حاضر') { presentDays++; if (d.lateMinutes > 0) { lateCount++; lateMinutesTotal += d.lateMinutes; } if (d.earlyLeaveMinutes > 0) { earlyCount++; earlyMinutesTotal += d.earlyLeaveMinutes; } if (d.missingCheckout) { missingCheckoutDays++; missingCheckoutDeductionTotal += (d.deduction || 0); } }
      if (d.status === 'غياب') { absentDays++; absenceDeductionTotal += (d.deduction || 0); if (!(Number(d.deduction) > 0)) absentDaysNoRule++; }
      if (d.status === 'اجازة رسمية' || d.status === 'اجازة أسبوعية' || d.status === 'اجازة معتمدة') leaveDays++;
    });
    const totalDeductionOriginal = absenceDeductionTotal + missingCheckoutDeductionTotal + lateDeductionTotal + earlyDeductionTotal;
    const totalDeduction = absenceDeductionTotal + missingCheckoutDeductionTotal + lateDeductionFinal + earlyDeductionTotal;
    const lateMinutesFinalTotal = Math.max(0, lateMinutesTotal - lateWaiverMinutesTotal);
    const attendanceRate = workDaysRequired > 0 ? Math.round((presentDays / workDaysRequired) * 1000) / 10 : null;
    return { workDaysRequired, presentDays, absentDays, leaveDays, absentDaysNoRule, lateCount, lateMinutesTotal, earlyCount, earlyMinutesTotal, missingCheckoutDays, lateMinutesOriginal: lateMinutesTotal, lateWaiverMinutes: lateWaiverMinutesTotal, lateMinutesFinal: lateMinutesFinalTotal, lateDeductionOriginal: AT.round2(lateDeductionTotal), lateDeductionFinal: AT.round2(lateDeductionFinal), totalDeductionOriginal: AT.round2(totalDeductionOriginal), lateDeductionTotal: AT.round2(lateDeductionFinal), earlyDeductionTotal: AT.round2(earlyDeductionTotal), absenceDeductionTotal: AT.round2(absenceDeductionTotal), missingCheckoutDeductionTotal: AT.round2(missingCheckoutDeductionTotal), totalDeduction: AT.round2(totalDeduction), attendanceRate };
  },
  /** OLD buildEmployeeReport_ — cumulative late/early per deduction-rule period, waivers subtracted from the same rule */
  employeeReport(employeeCode, fromKey, toKey, ctx) {
    const emp = ctx.employeesByCode[employeeCode]; if (!emp) fail(ERR.NOT_FOUND, 'كود الموظف مش موجود: ' + employeeCode);
    const days = [], lateMinutesByRule = {}, earlyMinutesByRule = {};
    AT.eachDay(fromKey, toKey).forEach(key => {
      const rawPunch = (ctx.attendanceIndex[key] || {})[employeeCode] || { checkIn: null, checkOut: null };
      const day = this.classifyDay(emp, key, rawPunch, ctx);
      day.checkInLocation = rawPunch.checkInLocation || ''; day.checkInDistance = (rawPunch.checkInDistance === 0 || rawPunch.checkInDistance) ? rawPunch.checkInDistance : null;
      day.checkOutLocation = rawPunch.checkOutLocation || ''; day.checkOutDistance = (rawPunch.checkOutDistance === 0 || rawPunch.checkOutDistance) ? rawPunch.checkOutDistance : null;
      if (day.status === 'حاضر') {
        const rule = this._ruleForDate(ctx.deductionRules, key); const ruleKey = rule ? (rule.from + '_' + rule.to) : 'none'; day.ruleKey = ruleKey;
        if (day.lateMinutes > 0) lateMinutesByRule[ruleKey] = (lateMinutesByRule[ruleKey] || 0) + day.lateMinutes;
        if (rule && rule.earlyLeaveEnabled && day.earlyLeaveMinutes > 0) earlyMinutesByRule[ruleKey] = (earlyMinutesByRule[ruleKey] || 0) + day.earlyLeaveMinutes;
        day.deduction = (day.missingCheckout && rule) ? rule.missingCheckoutValue : 0;
      }
      days.push(day);
    });
    let lateDeductionTotal = 0;
    Object.keys(lateMinutesByRule).forEach(k => { const [from, to] = k.split('_'); const rule = ctx.deductionRules.find(r => r.from === from && r.to === to); if (rule) lateDeductionTotal += Math.floor(lateMinutesByRule[k] / rule.lateUnitMinutes) * rule.lateUnitValue; });
    const waiverMap = ctx.lateWaivers || {}; const lateMinutesByRuleFinal = {}; let lateWaiverMinutesTotal = 0;
    Object.keys(lateMinutesByRule).forEach(k => { lateMinutesByRuleFinal[k] = lateMinutesByRule[k]; });
    days.forEach(day => { if (day.status !== 'حاضر' || !(day.lateMinutes > 0)) return; const w = Number(waiverMap[emp.code + '|' + day.dateKey] || 0); if (w <= 0) return; const applied = Math.min(w, day.lateMinutes); day.lateWaiverMinutes = applied; day.lateMinutesFinal = day.lateMinutes - applied; lateWaiverMinutesTotal += applied; const rk = day.ruleKey; if (rk && lateMinutesByRuleFinal[rk] !== undefined) lateMinutesByRuleFinal[rk] = Math.max(0, lateMinutesByRuleFinal[rk] - applied); });
    let lateDeductionFinal = 0;
    Object.keys(lateMinutesByRuleFinal).forEach(k => { const [from, to] = k.split('_'); const rule = ctx.deductionRules.find(r => r.from === from && r.to === to); if (rule) lateDeductionFinal += Math.floor(lateMinutesByRuleFinal[k] / rule.lateUnitMinutes) * rule.lateUnitValue; });
    let earlyDeductionTotal = 0;
    Object.keys(earlyMinutesByRule).forEach(k => { const [from, to] = k.split('_'); const rule = ctx.deductionRules.find(r => r.from === from && r.to === to); if (rule) earlyDeductionTotal += Math.floor(earlyMinutesByRule[k] / rule.earlyLeaveUnitMinutes) * rule.earlyLeaveUnitValue; });
    const summary = this.summarize(days, lateDeductionTotal, earlyDeductionTotal, lateWaiverMinutesTotal, lateDeductionFinal);
    summary.lateMinutesNoRule = lateMinutesByRule['none'] || 0; summary.earlyMinutesNoRule = earlyMinutesByRule['none'] || 0;
    return { employee: { code: emp.code, nameAr: emp.nameAr, dept: emp.dept }, fromDate: fromKey, toDate: toKey, days, summary };
  },
  /** OLD ladders — permissions capped at 1 day, forgotten check-in linear (first one free) */
  permissionDeduction(count) { if (count <= 1) return 0; if (count === 2) return 0.25; if (count === 3) return 0.5; if (count === 4) return 0.75; return 1; },
  forgotCheckinDeduction(count) { return Math.max(0, count - 1) * 0.25; },
  _countInRange(index, code, fromKey, toKey) { return (index[code] || []).filter(r => { const k = U.dateStr(r.date); return k >= fromKey && k <= toKey; }).length; },
  _penaltyDays(index, code, fromKey, toKey) { let total = 0; (index[code] || []).forEach(r => { const k = U.dateStr(r.date); if (k >= fromKey && k <= toKey) total += Number(r.days) || 0; }); return AT.round2(total); },
  /** OLD buildEmployeeReportWithDeductions_ */
  employeeReportWithDeductions(employeeCode, fromKey, toKey, ctx) {
    const report = this.employeeReport(employeeCode, fromKey, toKey, ctx);
    const permissionsCount = this._countInRange(ctx.permissionsLog, employeeCode, fromKey, toKey);
    const permissionDeduction = this.permissionDeduction(permissionsCount);
    const forgotCheckinsCount = this._countInRange(ctx.forgotCheckinLog, employeeCode, fromKey, toKey);
    const forgotCheckinDeduction = this.forgotCheckinDeduction(forgotCheckinsCount);
    const extraPenaltyDays = this._penaltyDays(ctx.extraPenaltiesLog, employeeCode, fromKey, toKey);
    const summary = Object.assign({}, report.summary, { permissionsCount, permissionDeduction, forgotCheckinsCount, forgotCheckinDeduction, extraPenaltyDays,
      totalDeduction: AT.round2(report.summary.totalDeduction + permissionDeduction + forgotCheckinDeduction + extraPenaltyDays),
      totalDeductionOriginal: AT.round2((report.summary.totalDeductionOriginal || 0) + permissionDeduction + forgotCheckinDeduction + extraPenaltyDays) });
    return Object.assign({}, report, { summary });
  },
  /** OLD buildMonthlyClosing_ — one context + one attendance pull for ALL employees (no N+1) */
  monthlyClosing(fromKey, toKey) {
    const ctx = this.buildContext(fromKey, toKey);
    const dayColumns = AT.eachDay(fromKey, toKey).map((key, i) => ({ dateKey: key, dayNumber: i + 1, dateLabel: key.slice(8) + '/' + key.slice(5, 7), weekdayName: WEEKDAY_NAMES_[AT.weekday(key)] }));
    const rows = ctx.employees.map(emp => {
      const report = this.employeeReportWithDeductions(emp.code, fromKey, toKey, ctx); const s = report.summary; const byDate = {};
      report.days.forEach(d => { byDate[d.dateKey] = d; });
      const cells = dayColumns.map(col => { const d = byDate[col.dateKey]; if (!d) return { text: '-', status: '', lateMinutes: 0 };
        return { text: d.status === 'حاضر' ? (d.lateMinutes > 0 ? String(d.lateMinutes) : '0') : d.status, status: d.status, lateMinutes: d.lateMinutes, earlyLeaveMinutes: d.earlyLeaveMinutes, missingCheckout: d.missingCheckout, holidayReason: d.holidayReason || '', checkInActual: d.checkInActual, checkOutActual: d.checkOutActual }; });
      return { employee: { code: emp.code, nameAr: emp.nameAr, dept: emp.dept, status: emp.status }, cells,
        totals: { lateMinutesTotal: s.lateMinutesTotal, lateWaiverMinutes: s.lateWaiverMinutes || 0, lateMinutesFinal: (s.lateMinutesFinal !== undefined ? s.lateMinutesFinal : s.lateMinutesTotal), lateDeductionOriginal: s.lateDeductionOriginal, lateDeductionTotal: s.lateDeductionTotal, earlyMinutesTotal: s.earlyMinutesTotal, earlyDeductionTotal: s.earlyDeductionTotal, absentDays: s.absentDays, absenceDeductionTotal: s.absenceDeductionTotal, missingCheckoutDays: s.missingCheckoutDays, missingCheckoutDeductionTotal: s.missingCheckoutDeductionTotal, permissionsCount: s.permissionsCount, permissionDeduction: s.permissionDeduction, forgotCheckinsCount: s.forgotCheckinsCount, forgotCheckinDeduction: s.forgotCheckinDeduction, extraPenaltyDays: s.extraPenaltyDays, totalDeductionOriginal: s.totalDeductionOriginal, totalDeduction: s.totalDeduction, absentDaysNoRule: s.absentDaysNoRule || 0, lateMinutesNoRule: s.lateMinutesNoRule || 0, earlyMinutesNoRule: s.earlyMinutesNoRule || 0 } };
    });
    return { fromDate: fromKey, toDate: toKey, dayColumns, rows, diagnostics: { ignoredDeductionRuleRows: RulesService.ignoredDeductionRuleRows(), absentDaysNoRule: rows.reduce((a, r) => a + (r.totals.absentDaysNoRule || 0), 0), lateMinutesNoRule: rows.reduce((a, r) => a + (r.totals.lateMinutesNoRule || 0), 0), earlyMinutesNoRule: rows.reduce((a, r) => a + (r.totals.earlyMinutesNoRule || 0), 0) } };
  }
};

/* ---------- ClosingService («اقفال شهري» + فترات المرتبات + نسخ الإقفال) ---------- */
const ClosingService = {
  periods() { return DA.readMany('Periods').filter(r => U.str(r.id)).map(r => ({ id: Number(r.id), name: U.str(r.name), from: U.dateStr(r.from), to: U.dateStr(r.to), status: U.str(r.status) || 'مفتوحة', createdBy: U.str(r.createdBy), createdAt: U.stamp(r.createdAt), closedBy: U.str(r.closedBy), closedAt: U.stamp(r.closedAt) })).sort((a, b) => a.id - b.id); },
  /** OLD findPeriodGaps_ — warns about uncovered days between official periods */
  gaps(list) { const periods = (list || this.periods()).filter(p => p.from && p.to).sort((a, b) => a.from.localeCompare(b.from)); const gaps = [];
    for (let i = 0; i < periods.length - 1; i++) { const end = periods[i].to, next = periods[i + 1].from; const diff = Math.round((new Date(next + 'T00:00:00Z') - new Date(end + 'T00:00:00Z')) / 86400000); if (diff > 1) gaps.push({ from: AT.addDays(end, 1), to: AT.addDays(next, -1), days: diff - 1 }); } return gaps; },
  list() { const periods = this.periods(); return { periods, gaps: this.gaps(periods) }; },
  periodAdd(payload, ctx) {
    const from = U.dateStr(payload.from), to = U.dateStr(payload.to); assert(from && to, 'تاريخ البداية أو النهاية غير صالح.'); assert(from <= to, 'تاريخ البداية لازم يكون قبل أو يساوي تاريخ النهاية.');
    return U.withLock(() => { const periods = this.periods(); const overlap = periods.find(p => from <= p.to && to >= p.from);
      if (overlap) fail(ERR.VALIDATION_ERROR, 'الفترة الجديدة (' + from + ' → ' + to + ') بتتداخل مع فترة رسمية موجودة: ' + (overlap.name || ('#' + overlap.id)) + ' (' + overlap.from + ' → ' + overlap.to + '). عدّل التواريخ عشان مفيش تداخل.');
      const id = periods.reduce((m, p) => Math.max(m, p.id), 0) + 1;
      DA.insert('Periods', { id, name: U.str(payload.name), from, to, status: 'مفتوحة', createdBy: ctx.session.username, createdAt: U.now(), closedBy: '', closedAt: '' });
      SystemService.audit(ctx, 'period.add', String(id), from + '→' + to); return { ok: true, id }; }, 15000);
  },
  _find(periodId) { const id = Number(periodId); return this.periods().find(p => p.id === id) || null; },
  _logAction(period, action, user, reason, gaps) { DA.insert('PeriodCloseLog', { periodId: period.id, periodName: period.name, action, user, timestamp: U.now(), reason: U.str(reason), hasGaps: (gaps && gaps.length) ? YES : NO, gaps: (gaps && gaps.length) ? gaps.map(g => g.from + ' → ' + g.to + ' (' + g.days + ' يوم)').join(' · ') : '' }); },
  snapshot(periodId) {
    const head = DA.readMany('ClosingSnapshots').filter(h => Number(h.periodId) === Number(periodId) && U.str(h.state) === SNAPSHOT_STATE.READY)[0];
    if (!head) return null;
    const snapshotId = U.str(head.snapshotId);
    const rows = DA.readMany('ClosingSnapshotRows').filter(r => U.str(r.snapshotId) === snapshotId).sort((a, b) => Number(a.snapshotOrder) - Number(b.snapshotOrder))
      .map(r => ({ employee: { code: U.str(r.code), nameAr: U.str(r.nameAr), dept: U.str(r.dept), status: U.str(r.status) }, cells: U.parseJson(U.str(r.cells), []), totals: U.parseJson(U.str(r.totals), {}) }));
    return { fromDate: U.str(head.fromDate), toDate: U.str(head.toDate), dayColumns: U.parseJson(U.str(head.dayColumns), []), rows, diagnostics: U.parseJson(U.str(head.diagnostics), {}),
      snapshot: { snapshotId, periodId: Number(head.periodId), periodName: U.str(head.periodName), closedBy: U.str(head.closedBy), closedAt: U.stamp(head.closedAt) } };
  },
  _supersede(periodId, exceptId) { let n = 0; DA.readMany('ClosingSnapshots').forEach(h => { if (Number(h.periodId) !== Number(periodId)) return; const sid = U.str(h.snapshotId); if (exceptId && sid === String(exceptId)) return; if (U.str(h.state) !== SNAPSHOT_STATE.READY) return; DA.update('ClosingSnapshots', { snapshotId: sid }, { state: SNAPSHOT_STATE.SUPERSEDED }); n++; }); return n; },
  /** OLD saveMonthlyClosingSnapshot_ + validateSnapshot_ + markSnapshotReady_ (building → verify → ready) */
  _saveSnapshot(ctxSession, period, closing) {
    const snapshotId = 'S-' + U.uuid().replace(/-/g, '').slice(0, 8).toUpperCase();
    const rows = closing.rows || [];
    const ops = [{ op: 'insert', table: 'ClosingSnapshots', data: { snapshotId, periodId: period.id, periodName: period.name, fromDate: closing.fromDate, toDate: closing.toDate, state: SNAPSHOT_STATE.BUILDING, dayColumns: JSON.stringify(closing.dayColumns || []), diagnostics: JSON.stringify(closing.diagnostics || {}), rowCount: rows.length, closedBy: ctxSession.username, closedAt: U.now() } }];
    rows.forEach((r, i) => ops.push({ op: 'insert', table: 'ClosingSnapshotRows', data: { snapshotId, snapshotOrder: i, code: U.str(r.employee.code), nameAr: U.str(r.employee.nameAr), dept: U.str(r.employee.dept), status: U.str(r.employee.status), cells: JSON.stringify(r.cells || []), totals: JSON.stringify(r.totals || {}) } }));
    DA.batch(ops);
    // verify what was actually persisted BEFORE the period is marked closed
    const fail_ = m => fail(ERR.SERVER_ERROR, 'فشل التحقق من نسخة الإقفال — ' + m + '. الفترة فضلت مفتوحة ومفيش نسخة معتمدة.');
    const head = DA.readOne('ClosingSnapshots', { snapshotId }); if (!head) fail_('رأس النسخة مش موجود');
    if (Number(head.periodId) !== Number(period.id)) fail_('Period ID مش مطابق');
    try { JSON.parse(U.str(head.dayColumns)); JSON.parse(U.str(head.diagnostics)); } catch (e) { fail_('dayColumns أو diagnostics مش قابلين للقراءة'); }
    if (Number(head.rowCount) !== rows.length) fail_('عدد الموظفين المتوقَّع في الرأس مش مطابق للحساب');
    const saved = DA.readMany('ClosingSnapshotRows').filter(r => U.str(r.snapshotId) === snapshotId);
    if (saved.length !== rows.length) fail_('عدد الصفوف المحفوظة (' + saved.length + ') مش مطابق للمتوقَّع (' + rows.length + ')');
    const savedCodes = {};
    saved.forEach(r => { const cells = U.str(r.cells), totals = U.str(r.totals); if (!cells || !totals) fail_('صف الموظف ' + U.str(r.code) + ' ناقص cells أو totals'); try { JSON.parse(cells); JSON.parse(totals); } catch (e) { fail_('صف الموظف ' + U.str(r.code) + ' مش قابل للقراءة كـJSON'); } savedCodes[U.str(r.code)] = true; });
    rows.forEach(r => { if (!savedCodes[U.str(r.employee.code)]) fail_('الموظف ' + U.str(r.employee.code) + ' مش موجود في الصفوف المحفوظة'); });
    DA.update('ClosingSnapshots', { snapshotId }, { state: SNAPSHOT_STATE.READY });
    return snapshotId;
  },
  close(payload, ctx) {
    const period = this._find(payload.periodId); if (!period) fail(ERR.NOT_FOUND, 'الفترة مش موجودة.');
    if (period.status === 'مقفولة') fail(ERR.VALIDATION_ERROR, 'الفترة "' + (period.name || period.id) + '" مقفولة بالفعل.');
    const gaps = this.gaps(); if (gaps.length && payload.ackGap !== true) return { ok: false, needsGapAck: true, gaps, period };
    assert(period.from && period.to, 'تواريخ الفترة غير صالحة.');
    return U.withLock(() => {
      const closing = AttendanceCalc.monthlyClosing(period.from, period.to);
      const snapshotId = this._saveSnapshot(ctx.session, period, closing);
      this._supersede(period.id, snapshotId);
      DA.update('Periods', { id: period.id }, { status: 'مقفولة', closedBy: ctx.session.username, closedAt: U.now() });
      this._logAction(period, 'إقفال', ctx.session.username, '', gaps);
      SystemService.audit(ctx, 'period.close', String(period.id), snapshotId);
      return { ok: true, id: period.id, gaps, snapshotId };
    }, 30000);
  },
  reopen(payload, ctx) {
    const reason = U.str(payload.reason); assert(reason, 'لازم تكتب سبب إعادة الفتح.');
    const period = this._find(payload.periodId); if (!period) fail(ERR.NOT_FOUND, 'الفترة مش موجودة.');
    if (period.status !== 'مقفولة') fail(ERR.VALIDATION_ERROR, 'الفترة "' + (period.name || period.id) + '" مفتوحة بالفعل.');
    return U.withLock(() => { const superseded = this._supersede(period.id, null); DA.update('Periods', { id: period.id }, { status: 'مفتوحة' }); this._logAction(period, 'إعادة فتح', ctx.session.username, reason, null); SystemService.audit(ctx, 'period.reopen', String(period.id), reason); return { ok: true, id: period.id, supersededSnapshots: superseded }; }, 30000);
  },
  /** OLD monthlyClosing action — Period ID is the ONLY key; a closed period NEVER recalculates live */
  closing(payload) {
    const pid = U.str(payload.periodId);
    if (pid) {
      const period = this._find(pid); if (!period) fail(ERR.NOT_FOUND, 'الفترة الرسمية مش موجودة (Period ID: ' + pid + ').');
      if (period.status === 'مقفولة') {
        const snap = this.snapshot(period.id);
        if (!snap) fail(ERR.VALIDATION_ERROR, 'الفترة "' + (period.name || period.id) + '" مقفولة، لكن مفيش نسخة إقفال محفوظة ليها. الأرقام مش هتتعرض عشان متبانش كأنها محمية وهي لسه بتتحسب حيًّا. الحل: افتح الفترة تاني (إعادة فتح) ثم أقفلها من جديد.');
        if (period.from !== snap.fromDate || period.to !== snap.toDate) snap.periodWarning = { type: 'dateMismatch', message: 'تواريخ الفترة اتغيّرت بعد الإقفال. النسخة دي محفوظة بـ' + snap.fromDate + ' → ' + snap.toDate + ' بينما الفترة دلوقتي ' + period.from + ' → ' + period.to + '. الأرقام المعروضة بتخص تواريخ النسخة.', snapshotFrom: snap.fromDate, snapshotTo: snap.toDate, periodFrom: period.from, periodTo: period.to };
        return { closing: snap, source: 'snapshot', period };
      }
      return { closing: AttendanceCalc.monthlyClosing(period.from, period.to), source: 'live', period };
    }
    const from = U.dateStr(payload.from), to = U.dateStr(payload.to); assert(from && to, 'لازم تحدد from و to (yyyy-MM-dd)'); assert(from <= to, 'تاريخ البداية لازم يكون قبل أو يساوي تاريخ النهاية.');
    return { closing: AttendanceCalc.monthlyClosing(from, to), source: 'live', period: null };
  },
  history(payload) { const pid = U.str(payload.periodId); return { items: DA.readMany('PeriodCloseLog').filter(r => !pid || String(r.periodId) === pid).map(r => ({ periodId: Number(r.periodId), periodName: U.str(r.periodName), action: U.str(r.action), user: U.str(r.user), timestamp: U.stamp(r.timestamp), reason: U.str(r.reason), hasGaps: U.yes(r.hasGaps), gaps: U.str(r.gaps) })).reverse(),
    snapshots: DA.readMany('ClosingSnapshots').filter(h => !pid || String(h.periodId) === pid).map(h => ({ snapshotId: U.str(h.snapshotId), periodId: Number(h.periodId), periodName: U.str(h.periodName), fromDate: U.str(h.fromDate), toDate: U.str(h.toDate), state: U.str(h.state), rowCount: Number(h.rowCount) || 0, closedBy: U.str(h.closedBy), closedAt: U.stamp(h.closedAt) })).reverse() }; }
};

/* ===================================================================== */
/* PHASE 4 — باقي وحدات GHIS القديم                                       */
/*   التقارير · مركز التقارير · الرواتب · حركات المرتب وأنواعها ·          */
/*   خصم الصيف · رسائل واتساب · التنبيهات والإشعارات · نسخ احتياطي        */
/* كل الحسابات تعيد استخدام AttendanceCalc — صفر تكرار لمنطق الحساب.      */
/* ===================================================================== */

const TX_CATEGORY_VALUES_ = ['basic', 'earning', 'deduction', 'excluded', 'summer'];   // OLD
const SUMMER_REFUNDABLE_TYPES_ = ['ربع', 'ربعين'];                                      // OLD
const SALARY_RATE_ALLOWANCE_PREFIX_ = 'نسبة العلاوة — ';                                 // OLD (stored in الإعدادات العامة)
const SALARY_RATE_QUARTER_PREFIX_ = 'نسبة خصم الربع — ';                                 // OLD
const NOTIF_STATUS = { SCHEDULED: 'مجدول', CANCELLED: 'ملغي' };

/** OLD addMonthsLastValid_ — keeps the day, clamped to the month's last day. */
function addMonthsKey_(key, months) {
  const y = Number(key.slice(0, 4)), m = Number(key.slice(5, 7)) - 1, d = Number(key.slice(8, 10));
  const total = m + months; const ty = y + Math.floor(total / 12); const tm = ((total % 12) + 12) % 12;
  const last = new Date(Date.UTC(ty, tm + 1, 0)).getUTCDate();
  return ty + '-' + ('0' + (tm + 1)).slice(-2) + '-' + ('0' + Math.min(d, last)).slice(-2);
}
/** OLD isWithinFirstYear_ */
function isWithinFirstYear_(hireKey, monthKey) { if (!hireKey) return false; const hireM = hireKey.slice(0, 7); const endM = addMonthsKey_(hireKey, 12).slice(0, 7); const m = U.str(monthKey).slice(0, 7); return m >= hireM && m < endM; }
function multiFilter_(v) { return (Array.isArray(v) ? v : U.str(v).split(',')).map(x => U.str(x)).filter(Boolean); }

/* ---------- AttendanceReportService («التقارير» — تقرير حضور موظف واحد / كل الموظفين) ---------- */
const AttendanceReportService = {
  /** OLD action "report": ONE context + ONE attendance pull, single or all employees */
  report(payload) {
    const from = U.dateStr(payload.from), to = U.dateStr(payload.to);
    assert(from && to, 'لازم تحدد from و to (yyyy-MM-dd)'); assert(from <= to, 'تاريخ البداية لازم يكون قبل أو يساوي تاريخ النهاية.');
    const code = U.str(payload.employee);
    const ctx = AttendanceCalc.buildContext(from, to);
    if (code && code !== 'all') return { mode: 'single', report: AttendanceCalc.employeeReportWithDeductions(code, from, to, ctx) };
    const wantD = multiFilter_(payload.dept), wantJ = multiFilter_(payload.jobTitle);
    const rows = ctx.employees.filter(e => (!wantD.length || wantD.includes(e.dept)) && (!wantJ.length || wantJ.includes(e.jobTitle)))
      .map(emp => { const r = AttendanceCalc.employeeReportWithDeductions(emp.code, from, to, ctx); return { employee: { code: emp.code, nameAr: emp.nameAr, dept: emp.dept, jobTitle: emp.jobTitle, status: emp.status }, summary: r.summary }; });
    return { mode: 'all', fromDate: from, toDate: to, rows };
  }
};

/* ---------- SalaryService (Phase 4 extension: أنواع الحركات · الحركات · السلف · الرواتب · العلاوة) ---------- */
SalaryService.CATEGORIES = TX_CATEGORY_VALUES_;
SalaryService.typesList = function () { return { types: this.types(), categories: TX_CATEGORY_VALUES_ }; };
/** OLD addSalaryTxType_ — category is mandatory (fail-closed in payroll) */
SalaryService.typeAdd = function (payload, ctx) {
  const name = U.str(payload.name); assert(name, 'اسم نوع الحركة مطلوب.');
  const direction = U.str(payload.direction); assert(direction === 'إضافة' || direction === 'خصم', 'النوع لازم يكون "إضافة" أو "خصم".');
  const category = U.str(payload.category); assert(TX_CATEGORY_VALUES_.includes(category), 'التصنيف (Category) لازم يكون واحد من: ' + TX_CATEGORY_VALUES_.join(', '));
  assert(!this.types().some(t => t.name === name), 'النوع موجود بالفعل: ' + name);
  DA.insert('SalaryTxTypes', { name, kind: direction, active: YES, category }); Cache.remove('salaryTxTypes');
  SystemService.audit(ctx, 'salaryTxType.add', name, direction + '/' + category); return { ok: true };
};
SalaryService.typeToggle = function (payload, ctx) { const name = U.str(payload.name); assert(this.types().some(t => t.name === name), 'النوع مش موجود: ' + name); DA.update('SalaryTxTypes', { name }, { active: U.yn(payload.active) }); Cache.remove('salaryTxTypes'); SystemService.audit(ctx, 'salaryTxType.toggle', name, U.yn(payload.active)); return { ok: true }; };
SalaryService.allTransactions = function () { return DA.readMany('SalaryTransactions').map(this._shape); };
/** OLD getBasicSalaryAtPeriodStart_ — latest effective basic salary at the period start */
SalaryService.basicAt = function (code, periodStartKey, preloaded) {
  const txs = (preloaded || this.transactionsForEmployee({ code }).transactions).filter(t => t.type === this.BASIC && t.from && t.from <= periodStartKey);
  if (!txs.length) fail(ERR.VALIDATION_ERROR, 'مفيش راتب أساسي مسجَّل للموظف ده وقت بداية الفترة (' + periodStartKey + ') — لازم تسجّله الأول.');
  let pick = txs[0];
  for (let i = 1; i < txs.length; i++) { const t = txs[i]; if (t.from > pick.from) { pick = t; continue; } if (t.from === pick.from && String(t.createdAt || '') >= String(pick.createdAt || '')) pick = t; }
  return pick.value;
};
SalaryService.classifyCategory = function (typeDef) { const cat = U.str(typeDef.category); if (!TX_CATEGORY_VALUES_.includes(cat)) fail(ERR.VALIDATION_ERROR, 'نوع الحركة "' + typeDef.name + '" لسه مش مصنَّف (Category) بشكل صحيح — لازم تحدده الأول.'); return cat; };
/* --- السلف --- */
SalaryService.splitInstallments = function (total, count) { const cents = Math.round(Number(total) * 100), n = Number(count); const base = Math.floor(cents / n); const parts = []; for (let i = 0; i < n; i++) parts.push(base); parts[n - 1] += cents - base * n; return parts.map(c => c / 100); };
SalaryService.advances = function (code) { return DA.readMany('Advances').filter(r => U.str(r.id) && (!code || U.str(r.code) === code)).map(r => ({ id: U.str(r.id), code: U.str(r.code), advanceDate: U.dateStr(r.advanceDate), total: Number(r.total) || 0, count: Number(r.count) || 0, startDate: U.dateStr(r.startDate), installmentValue: Number(r.installmentValue) || 0, status: U.str(r.status), notes: U.str(r.notes), createdBy: U.str(r.createdBy), createdAt: U.stamp(r.createdAt) })); };
SalaryService.installments = function (adv) { if (!adv.startDate) return []; return this.splitInstallments(adv.total, adv.count).map((v, i) => ({ no: i + 1, dueDate: addMonthsKey_(adv.startDate, i), value: v })); };
SalaryService.advanceDueInPeriod = function (code, fromKey, toKey, preloaded) { let cents = 0; (preloaded || this.advances()).forEach(a => { if (a.code !== code || a.status === 'ملغاة') return; this.installments(a).forEach(i => { if (i.dueDate >= fromKey && i.dueDate <= toKey) cents += Math.round(i.value * 100); }); }); return cents / 100; };
SalaryService.advanceCreate = function (payload, ctx) {
  const d = payload.data || payload; const code = U.str(d.code); assert(code, 'اختر الموظف.'); EmployeeService._exists(code);
  const total = Number(d.total); assert(total > 0, 'مبلغ السلفة غير صحيح.');
  const count = Number(d.count); assert(Number.isInteger(count) && count > 0, 'عدد الشهور غير صحيح.');
  const advanceDate = U.dateStr(d.advanceDate); assert(advanceDate, 'تاريخ السلفة غير صحيح.');
  const startDate = U.dateStr(d.startDate); assert(startDate, 'تاريخ بداية الخصم غير صحيح.');
  const parts = this.splitInstallments(total, count);
  assert(parts.reduce((a, v) => a + Math.round(v * 100), 0) === Math.round(total * 100), 'خطأ في تقسيم الأقساط.');
  return U.withLock(() => { const id = 'ADV-' + U.uuid().replace(/-/g, '').slice(0, 8).toUpperCase();
    DA.insert('Advances', { id, code, advanceDate, total, count, startDate, installmentValue: parts[0], status: 'نشطة', notes: U.str(d.notes), createdBy: ctx.session.username, createdAt: U.now() });
    SystemService.audit(ctx, 'advance.create', code, id + ' · ' + total + ' على ' + count + ' شهر'); return { ok: true, id, installments: parts }; }, 30000);
};
SalaryService.advanceCancel = function (payload, ctx) {
  const id = U.str(payload.id); assert(id, 'حدد السلفة.');
  return U.withLock(() => { const a = DA.readOne('Advances', { id }); if (!a) fail(ERR.NOT_FOUND, 'السلفة غير موجودة.');
    DA.update('Advances', { id }, { status: 'ملغاة' });
    DA.insert('EmployeeHistory', { code: U.str(a.code), field: 'Advance Cancel', oldValue: 'نشطة', newValue: 'ملغاة', reason: U.str(payload.reason), user: ctx.session.username, timestamp: U.now(), operationId: U.uuid() });
    SystemService.audit(ctx, 'advance.cancel', U.str(a.code), id); return { ok: true, id }; }, 30000);
};
/** OLD getAdvancesReport_ — collected = installments already due (<= today) */
SalaryService.advancesReport = function (payload) {
  const from = U.dateStr(payload.from), to = U.dateStr(payload.to), today = U.today();
  const names = {}; DA.readMany('Employees').forEach(r => { names[U.str(r.code)] = U.str(r.nameAr); });
  const rows = this.advances().filter(a => (!from || !a.advanceDate || a.advanceDate >= from) && (!to || !a.advanceDate || a.advanceDate <= to)).map(a => {
    const inst = this.installments(a); let collectedCents = 0, collectedCount = 0;
    inst.forEach(x => { if (x.dueDate > today) return; collectedCents += Math.round(x.value * 100); collectedCount++; });
    const cancelled = a.status === 'ملغاة'; const collected = cancelled ? 0 : collectedCents / 100;
    return Object.assign({}, a, { nameAr: names[a.code] || a.code, collected: AT.round2(collected), collectedCount: cancelled ? 0 : collectedCount, remaining: AT.round2(cancelled ? 0 : a.total - collected), installments: inst });
  });
  return { rows };
};
/* --- إعدادات المرتبات (نِسَب الأقسام في «الإعدادات العامة» — نفس مفاتيح القديم) --- */
SalaryService.rates = function () { const out = { allowance: {}, quarter: {} }; DA.readMany('Settings').forEach(r => { const k = U.str(r.key), v = Number(r.value); if (!k || isNaN(v)) return; if (k.indexOf(SALARY_RATE_ALLOWANCE_PREFIX_) === 0) out.allowance[k.slice(SALARY_RATE_ALLOWANCE_PREFIX_.length).trim()] = v; else if (k.indexOf(SALARY_RATE_QUARTER_PREFIX_) === 0) out.quarter[k.slice(SALARY_RATE_QUARTER_PREFIX_.length).trim()] = v; }); return out; };
SalaryService.ratesGet = function () { return { rates: this.rates(), departments: (NameListService.all().departments || []).map(d => d.name) }; };
SalaryService.ratesSet = function (payload, ctx) {
  const list = Array.isArray(payload.rates) ? payload.rates : []; let n = 0;
  return U.withLock(() => { list.forEach(r => { const dept = U.str(r.dept); if (!dept) return; const a = Number(r.allowanceRate), q = Number(r.quarterRate);
      // حفظ القيمة المتغيّرة بس — إعادة إرسال نفس القيم (ضغط مزدوج) مابتكتبش ومابتعملش إشعار تاني
      if (!isNaN(a) && U.str(SettingsService.get(SALARY_RATE_ALLOWANCE_PREFIX_ + dept, '')) !== U.str(a)) { SettingsService.set(SALARY_RATE_ALLOWANCE_PREFIX_ + dept, a, 'نسبة العلاوة السنوية لقسم ' + dept); n++; }
      if (!isNaN(q) && U.str(SettingsService.get(SALARY_RATE_QUARTER_PREFIX_ + dept, '')) !== U.str(q)) { SettingsService.set(SALARY_RATE_QUARTER_PREFIX_ + dept, q, 'نسبة خصم الربع لقسم ' + dept); n++; } });
    if (n > 0) SystemService.audit(ctx, 'salaryRates.set', '', n + ' قيمة');   // إشعار واحد لكل حفظ فيه تغيير فعلي
    return { ok: true, saved: n }; }, 30000);
};
SalaryService.yearSettingsGet = function (payload) { const yearId = U.str(payload.yearId); const r = DA.readOne('PayrollYearSettings', { yearId }); return { settings: r ? { yearId, raiseMonth: U.str(r.raiseMonth).slice(0, 7), quarterDeductMonths: U.str(r.quarterDeductMonths).split(',').map(x => U.str(x)).filter(Boolean), quarterRefundMonth: U.str(r.quarterRefundMonth).slice(0, 7), departments: U.str(r.departments).split(',').map(x => U.str(x)).filter(Boolean), savedBy: U.str(r.savedBy), savedAt: U.stamp(r.savedAt) } : null, years: AcademicYearService.list().years }; };
SalaryService.yearSettingsSet = function (payload, ctx) {
  const yearId = U.str(payload.yearId); assert(yearId, 'لازم تختار السنة الدراسية.'); assert(DA.readOne('AcademicYears', { id: yearId }), 'السنة الدراسية مش موجودة.');
  const data = { yearId, raiseMonth: U.str(payload.raiseMonth).slice(0, 7), quarterDeductMonths: multiFilter_(payload.quarterDeductMonths).join(','), quarterRefundMonth: U.str(payload.quarterRefundMonth).slice(0, 7), departments: multiFilter_(payload.departments).join(','), savedBy: ctx.session.username, savedAt: U.now() };
  if (DA.readOne('PayrollYearSettings', { yearId })) DA.update('PayrollYearSettings', { yearId }, data); else DA.insert('PayrollYearSettings', data);
  SystemService.audit(ctx, 'payrollYearSettings.set', yearId, data.raiseMonth); return { ok: true };
};
SalaryService.quarterPolicyGet = function (payload) { const yearId = U.str(payload.yearId); const map = {}; DA.readMany('QuarterDeductionPolicy').forEach(r => { const y = U.str(r.yearId), dept = U.str(r.dept), month = U.str(r.month); if (!y || !dept || !month || (yearId && y !== yearId)) return; map[dept + '|' + month] = U.str(r.applied) === YES; }); const y = DA.readOne('AcademicYears', { id: yearId }); const months = []; if (y) { let m = U.dateStr(y.from).slice(0, 7); const end = U.dateStr(y.to).slice(0, 7); while (m && m <= end) { months.push(m); m = addMonthsKey_(m + '-01', 1).slice(0, 7); } } return { policy: map, months, departments: (NameListService.all().departments || []).map(d => d.name) }; };
SalaryService.quarterPolicySet = function (payload, ctx) {
  const yearId = U.str(payload.yearId); assert(yearId, 'لازم تختار السنة الدراسية.'); assert(DA.readOne('AcademicYears', { id: yearId }), 'السنة الدراسية مش موجودة.');
  const rows = Array.isArray(payload.rows) ? payload.rows : []; const ops = [];
  rows.forEach(r => { const dept = U.str(r.dept), month = U.str(r.month); if (!dept || !month) return; const data = { yearId, dept, month, applied: U.yn(r.applied), user: ctx.session.username, timestamp: U.now() };
    if (DA.readOne('QuarterDeductionPolicy', { yearId, dept, month })) ops.push({ op: 'update', table: 'QuarterDeductionPolicy', where: { yearId, dept, month }, patch: data }); else ops.push({ op: 'insert', table: 'QuarterDeductionPolicy', data }); });
  if (ops.length) DA.batch(ops); SystemService.audit(ctx, 'quarterPolicy.set', yearId, ops.length + ' صف'); return { ok: true, saved: ops.length };
};
/** OLD approveSalaryAllowance_ — approving a raise records a new basic salary transaction */
SalaryService.allowanceApprove = function (payload, ctx) {
  const code = U.str(payload.code); assert(code, 'لازم تختار الموظف.'); EmployeeService._exists(code);
  const allowance = Number(payload.allowance); assert(allowance > 0, 'قيمة العلاوة غير صحيحة.');
  const effectiveFrom = U.dateStr(payload.effectiveFrom); assert(effectiveFrom, 'تاريخ السريان غير صحيح.');
  const txs = this.transactionsForEmployee({ code }).transactions;
  const current = this.basicAt(code, effectiveFrom, txs);
  const newBasic = AT.round2(current + allowance);
  const r = this.add({ data: { code, type: this.BASIC, from: effectiveFrom, value: newBasic, reason: U.str(payload.reason) || 'اعتماد العلاوة السنوية (+' + allowance + ')' } }, ctx);
  SystemService.audit(ctx, 'salary.allowanceApprove', code, current + ' → ' + newBasic);
  return Object.assign({ ok: true, previousBasic: current, newBasic }, r);
};
/* --- تقرير الرواتب الأساسية --- */
SalaryService.basicSalariesReport = function (payload) {
  const today = U.today(); const wantD = multiFilter_(payload.dept), wantJ = multiFilter_(payload.jobTitle);
  const txsByCode = {}; this.allTransactions().forEach(t => { (txsByCode[t.code] = txsByCode[t.code] || []).push(t); });
  const exempt = ExemptDepartmentService.activeSet();
  const rows = [];
  DA.readMany('Employees').map(r => EmployeeService._shape(r, exempt)).forEach(e => {
    if (e.status !== 'نشط') return; if (wantD.length && !wantD.includes(e.dept)) return; if (wantJ.length && !wantJ.includes(e.jobTitle)) return;
    let basic = 0, error = '', effectiveFrom = '';
    try { const list = (txsByCode[e.code] || []).filter(t => t.type === this.BASIC && t.from && t.from <= today); basic = this.basicAt(e.code, today, txsByCode[e.code] || []); const pick = list.sort((a, b) => a.from.localeCompare(b.from))[list.length - 1]; effectiveFrom = pick ? pick.from : ''; }
    catch (err) { error = err.message; }
    rows.push({ code: e.code, nameAr: e.nameAr, dept: e.dept, jobTitle: e.jobTitle, basicSalary: AT.round2(basic), effectiveFrom, error });
  });
  return { rows };
};
SalaryService.basicSalariesSave = function (payload, ctx) {
  const rows = Array.isArray(payload.rows) ? payload.rows : []; assert(rows.length, 'مفيش صفوف للحفظ.');
  const from = U.dateStr(payload.effectiveFrom); assert(from, 'تاريخ السريان مطلوب.');
  let saved = 0, skipped = 0; const report = [];
  rows.forEach(r => { const code = U.str(r.code); const value = Number(r.basicSalary); if (!code || !(value > 0)) { skipped++; report.push({ code, ok: false, error: 'قيمة غير صحيحة' }); return; }
    try { this.add({ data: { code, type: this.BASIC, from, value, reason: U.str(payload.reason) || 'حفظ جماعي للرواتب الأساسية' } }, ctx); saved++; report.push({ code, ok: true }); }
    catch (e) { skipped++; report.push({ code, ok: false, error: e.message }); } });
  return { ok: true, saved, skipped, report };
};
/** OLD getSalaryTable_ — the «الرواتب» screen. ONE context + ONE attendance pull for all employees. */
SalaryService.salaryTable = function (payload) {
  const m = U.str(payload.monthKey).slice(0, 7) || U.today().slice(0, 7);
  const periods = ClosingService.periods().filter(p => p.from && p.to && p.to.slice(0, 7) === m).sort((a, b) => a.from.localeCompare(b.from));
  const period = periods.length ? periods[periods.length - 1] : null;
  if (!period) fail(ERR.VALIDATION_ERROR, 'مفيش فترة مرتبات رسمية لشهر ' + m + '. ضيف الفترة من (اقفال شهري ← إضافة فترة رسمية) الأول.');
  const fromKey = period.from, toKey = period.to;
  const ctx = AttendanceCalc.buildContext(fromKey, toKey);
  const txsByCode = {}; this.allTransactions().forEach(t => { (txsByCode[t.code] = txsByCode[t.code] || []).push(t); });
  const typesByName = {}; this.types().forEach(t => { typesByName[t.name] = t; });
  const rates = this.rates(); const advances = this.advances();
  const deptRuleMap = {}; this.summerDepartments().filter(d => d.active).forEach(d => { deptRuleMap[d.dept] = d.rule; });
  const activeYear = DA.readMany('AcademicYears').map(AcademicYearService._shape).find(y => y.active);
  const ys = activeYear ? this.yearSettingsGet({ yearId: activeYear.id }).settings : null;
  const raiseMonth = ys ? U.str(ys.raiseMonth) : ''; const isRaiseMonth = !!raiseMonth && raiseMonth === m; const monthNum = m.slice(5, 7);
  const wantD = multiFilter_(payload.dept), wantJ = multiFilter_(payload.jobTitle);
  const departments = {}; ctx.employees.forEach(e => { if (e.dept) departments[e.dept] = 1; });
  const rows = [];
  ctx.employees.forEach(emp => {
    if (emp.status !== 'نشط') return;
    if (wantD.length && !wantD.includes(emp.dept)) return; if (wantJ.length && !wantJ.includes(emp.jobTitle)) return;
    const empTxs = txsByCode[emp.code] || []; let basic = 0, basicError = '';
    try { basic = this.basicAt(emp.code, fromKey, empTxs); } catch (e) { basicError = e.message; }
    const dailyValue = AT.round2(basic / 30);
    const sm = AttendanceCalc.employeeReportWithDeductions(emp.code, fromKey, toKey, ctx).summary;
    const attendanceDeductionAmount = AT.round2(sm.totalDeduction * dailyValue);
    let earnings = 0, cashDeductions = 0, txError = '';
    empTxs.forEach(t => { const toK = t.to || '9999-12-31'; if (!(t.from <= toKey && toK >= fromKey)) return; const def = typesByName[t.type];
      if (!def) { txError = 'نوع حركة غير معروف: ' + t.type; return; }
      let category; try { category = this.classifyCategory(def); } catch (e) { txError = e.message; return; }
      if (category === 'summer' || category === 'excluded' || category === 'basic') return;
      if (category === 'earning') earnings += t.value; if (category === 'deduction') cashDeductions += Math.abs(t.value); });
    const advanceDeduction = this.advanceDueInPeriod(emp.code, fromKey, toKey, advances);
    const own = U.str(emp.summerDeduction), rule = deptRuleMap[emp.dept];
    const firstYear = isWithinFirstYear_(emp.hireDate, m);
    const fyRule = firstYear ? U.str(emp.firstYearDeduction) : ''; const exOpt = firstYear ? U.str(emp.firstYearSummerDeduction) : '';
    const halfRule = (exOpt === '1' || exOpt === '2'), exQuarter = (exOpt === '3' || exOpt === '4');
    let months = [];
    if (halfRule) months = (exOpt === '2') ? ['07', '08'] : ['07'];
    else if (exQuarter) months = (exOpt === '4') ? ['07', '08'] : ['07'];
    else if (fyRule) months = (fyRule === 'شهرين') ? ['07', '08'] : (fyRule === 'شهر واحد') ? ['07'] : [];
    else if (own) months = (own === 'ربعين') ? ['07', '08'] : ['07'];
    else if (rule) months = (rule === 'Rule 1') ? ['07', '08'] : ['07'];
    const qRate = (rates.quarter[emp.dept] === undefined) ? 25 : Number(rates.quarter[emp.dept]);
    const inMonth = months.indexOf(monthNum) !== -1;
    const quarterDeduction = !inMonth ? 0 : halfRule ? AT.round2(basic * 0.5) : AT.round2(basic * (qRate / 100));
    const aRate = Number(rates.allowance[emp.dept]) || 0; const fr = U.str(emp.firstRaiseMonth);
    const raiseDue = !fr ? isRaiseMonth : (m < fr) ? false : (m === fr) ? true : isRaiseMonth;
    const allowance = raiseDue ? AT.round2(basic * (aRate / 100)) : 0;
    rows.push({ code: emp.code, nameAr: emp.nameAr, dept: emp.dept || '', jobTitle: emp.jobTitle || '',
      basicSalary: AT.round2(basic), basicError, txError, dailyValue,
      allowanceRate: aRate, allowance, quarterRate: qRate, quarterDeduction,
      summerRule: halfRule ? ((exOpt === '2' ? 'نص يوليو + نص أغسطس' : 'نص يوليو') + ' بلا رد (استثناء سنة أولى)') : exQuarter ? ((exOpt === '4' ? 'ربعين' : 'ربع') + ' (استثناء سنة أولى)') : fyRule ? (fyRule + ' (سنة أولى)') : own || (rule === 'Rule 1' ? 'ربعين (قسم)' : rule ? 'ربع (قسم)' : ''),
      firstRaiseMonth: fr, raiseDue,
      absentDays: sm.absentDays, absenceDeductionTotal: sm.absenceDeductionTotal, absenceDeductionNetAmount: AT.round2(sm.absenceDeductionTotal * dailyValue),
      lateMinutesTotal: sm.lateMinutesTotal, lateWaiverMinutes: sm.lateWaiverMinutes || 0, lateMinutesFinal: (sm.lateMinutesFinal !== undefined ? sm.lateMinutesFinal : sm.lateMinutesTotal),
      lateDeductionOriginal: sm.lateDeductionOriginal, lateDeductionTotal: sm.lateDeductionTotal, lateDeductionNetAmount: AT.round2(sm.lateDeductionTotal * dailyValue),
      earlyMinutesTotal: sm.earlyMinutesTotal, earlyDeductionTotal: sm.earlyDeductionTotal,
      missingCheckoutDays: sm.missingCheckoutDays, missingCheckoutDeductionTotal: sm.missingCheckoutDeductionTotal,
      permissionsCount: sm.permissionsCount, permissionDeduction: sm.permissionDeduction,
      forgotCheckinsCount: sm.forgotCheckinsCount, forgotCheckinDeduction: sm.forgotCheckinDeduction,
      extraPenaltyDays: sm.extraPenaltyDays, totalDeductionDays: sm.totalDeduction, attendanceDeductionAmount,
      earnings: AT.round2(earnings), cashDeductions: AT.round2(cashDeductions), advanceDeduction: AT.round2(advanceDeduction),
      grossSalary: AT.round2(basic + allowance + earnings),
        totalDeductionsAmount: AT.round2(attendanceDeductionAmount + cashDeductions + advanceDeduction + quarterDeduction),
        totalDeductionsAmountFinal: AT.round5(attendanceDeductionAmount + cashDeductions + advanceDeduction + quarterDeduction),
        netSalary: AT.round2(basic + allowance + earnings - attendanceDeductionAmount - cashDeductions - advanceDeduction - quarterDeduction),
        netSalaryFinal: AT.round5(basic + allowance + earnings - attendanceDeductionAmount - cashDeductions - advanceDeduction - quarterDeduction) });
  });
  return { monthKey: m, periodId: period.id, periodName: period.name, from: fromKey, to: toKey, rows, departments: Object.keys(departments).sort(), rates, raiseMonth, isRaiseMonth };
};
/** OLD computePayrollForPeriod_ — one employee (used by WhatsApp payroll message and the employee payroll detail) */
SalaryService.payrollForPeriod = function (code, fromKey, toKey, ctx) {
  const txs = this.transactionsForEmployee({ code }).transactions;
  const basicSalary = this.basicAt(code, fromKey, txs); const dailyValue = AT.round2(basicSalary / 30);
  const summary = AttendanceCalc.employeeReportWithDeductions(code, fromKey, toKey, ctx).summary;
  const attendanceDeductionAmount = AT.round2(summary.totalDeduction * dailyValue);
  const typesByName = {}; this.types().forEach(t => { typesByName[t.name] = t; });
  let earnings = 0, cashDeductions = 0;
  txs.filter(t => { const toK = t.to || '9999-12-31'; return t.from <= toKey && toK >= fromKey; }).forEach(t => {
    const def = typesByName[t.type]; if (!def) fail(ERR.VALIDATION_ERROR, 'نوع حركة غير معروف: ' + t.type);
    const category = this.classifyCategory(def); if (category === 'summer' || category === 'excluded' || category === 'basic') return;
    if (category === 'earning') earnings += t.value; if (category === 'deduction') cashDeductions += Math.abs(t.value); });
  const advanceDeduction = this.advanceDueInPeriod(code, fromKey, toKey);
  return { employeeCode: code, from: fromKey, to: toKey, basicSalary, dailyValue, attendanceDeductionDetails: summary, attendanceDeductionAmount, earnings: AT.round2(earnings), cashDeductions: AT.round2(cashDeductions), advanceDeduction: AT.round2(advanceDeduction), totalDeductionsAmount: AT.round2(attendanceDeductionAmount + cashDeductions + advanceDeduction), totalDeductionsAmountFinal: AT.round5(attendanceDeductionAmount + cashDeductions + advanceDeduction), netSalary: AT.round2(basicSalary + earnings - attendanceDeductionAmount - cashDeductions - advanceDeduction), netSalaryFinal: AT.round5(basicSalary + earnings - attendanceDeductionAmount - cashDeductions - advanceDeduction) };
};
SalaryService.employeePayroll = function (payload) { const code = U.str(payload.code); assert(code, 'كود الموظف مطلوب.'); EmployeeService._exists(code); const from = U.dateStr(payload.from), to = U.dateStr(payload.to); assert(from && to, 'لازم تحدد الفترة.'); const ctx = AttendanceCalc.buildContext(from, to); return { payroll: this.payrollForPeriod(code, from, to, ctx), transactions: this.transactionsForEmployee({ code }).transactions, advances: this.advances(code) }; };
/* --- خصم الصيف --- */
SalaryService.summerDepartments = function () { const map = {}; DA.readMany('SummerDeductionDepartments').forEach(r => { const dept = U.str(r.dept); if (!dept) return; map[dept] = { dept, rule: U.str(r.rule), active: U.str(r.active) === YES }; }); return Object.values(map); };
SalaryService.summerDepartmentsGet = function () { return { departments: this.summerDepartments(), allDepartments: (NameListService.all().departments || []).map(d => d.name) }; };
SalaryService.summerDepartmentSet = function (payload, ctx) { const dept = U.str(payload.dept); assert(dept, 'اسم القسم مطلوب.'); const rule = U.str(payload.rule); assert(rule === 'Rule 1' || rule === 'Rule 2', 'القاعدة لازم تكون "Rule 1" أو "Rule 2".');
  if (DA.readOne('SummerDeductionDepartments', { dept })) DA.update('SummerDeductionDepartments', { dept }, { rule, active: YES }); else DA.insert('SummerDeductionDepartments', { dept, rule, active: YES });
  SystemService.audit(ctx, 'summerDept.set', dept, rule); return { ok: true }; };
SalaryService.summerLog = function (code, year) { return DA.readMany('SummerDeductionLog').map(r => ({ code: U.str(r.code), month: U.str(r.month), year: Number(r.year) || 0, basicSalaryAtTime: Number(r.basicSalaryAtTime) || 0, amount: Number(r.amount) || 0, refundStatus: U.str(r.refundStatus) || 'لسه', refundDate: U.dateStr(r.refundDate), user: U.str(r.user), registeredAt: U.stamp(r.registeredAt), type: U.str(r.type) })).filter(r => r.code && (!code || r.code === code) && (!year || r.year === Number(year))); };
/** OLD calculateSummerDeduction_ — idempotent per (code, month, year); own field > department rule; first-year exception = 50% non-refundable */
SalaryService.summerCalculate = function (payload, ctx) {
  const year = Number(payload.year); assert(year > 2000, 'السنة غير صحيحة.');
  const deptRuleMap = {}; this.summerDepartments().filter(d => d.active).forEach(d => { deptRuleMap[d.dept] = d.rule; });
  const log = this.summerLog(null, year); const exempt = ExemptDepartmentService.activeSet();
  const employees = DA.readMany('Employees').map(r => EmployeeService._shape(r, exempt));
  const txsByCode = {}; this.allTransactions().forEach(t => { (txsByCode[t.code] = txsByCode[t.code] || []).push(t); });
  const results = { calculated: [], skippedAlreadyLogged: [], skippedUnclassified: [], errors: [] }; const ops = [];
  employees.forEach(emp => {
    const own = U.str(emp.summerDeduction), rule = deptRuleMap[emp.dept], exOpt = U.str(emp.firstYearSummerDeduction);
    const push = (monthName, monthNum, factor, type, refundStatus) => {
      if (log.some(r => r.code === emp.code && r.month === monthName && r.year === year)) { results.skippedAlreadyLogged.push(emp.code + '-' + monthName); return; }
      let salary; try { salary = this.basicAt(emp.code, year + '-' + monthNum + '-01', txsByCode[emp.code] || []); } catch (e) { results.errors.push({ code: emp.code, month: monthName, error: e.message }); return; }
      const amount = AT.round2(salary * factor);
      ops.push({ op: 'insert', table: 'SummerDeductionLog', data: { code: emp.code, month: monthName, year, basicSalaryAtTime: salary, amount, refundStatus: refundStatus || 'لسه', refundDate: '', user: ctx.session.username, registeredAt: U.now(), type } });
      results.calculated.push({ code: emp.code, month: monthName, amount });
    };
    if ((exOpt === '1' || exOpt === '2') && isWithinFirstYear_(emp.hireDate, year + '-07')) {
      const halfMonths = (exOpt === '2') ? [['يوليو', '07'], ['أغسطس', '08']] : [['يوليو', '07']];
      const halfType = (exOpt === '2') ? 'نصفين' : 'نصف';
      halfMonths.forEach(([mn, mnum]) => push(mn, mnum, 0.5, halfType, 'غير قابل للرد')); return;
    }
    if (!own && !rule) { results.skippedUnclassified.push(emp.code); return; }
    const monthsToCheck = own ? (own === 'ربعين' ? [['يوليو', '07'], ['أغسطس', '08']] : [['يوليو', '07']]) : (rule === 'Rule 1' ? [['يوليو', '07'], ['أغسطس', '08']] : [['يوليو', '07']]);
    const dedType = (monthsToCheck.length === 2) ? 'ربعين' : 'ربع';
    monthsToCheck.forEach(([mn, mnum]) => push(mn, mnum, 0.25, dedType));
  });
  if (ops.length) DA.batch(ops);
  SystemService.audit(ctx, 'summer.calculate', String(year), results.calculated.length + ' صف');
  return results;
};
/** OLD refundSummerDeduction_ — only «ربع»/«ربعين» are refundable; half-month entries never are */
SalaryService.summerRefund = function (payload, ctx) {
  const code = U.str(payload.code), year = Number(payload.year), wantType = U.str(payload.type);
  const rows = this.summerLog(code || null, year || null);
  if (!rows.length) fail(ERR.VALIDATION_ERROR, 'مفيش أي خصم صيفي مسجَّل لهذا الموظف في السنة دي أصلاً.');
  const pending = rows.filter(r => r.refundStatus === 'لسه' && SUMMER_REFUNDABLE_TYPES_.includes(r.type) && (!wantType || r.type === wantType));
  if (!pending.length) fail(ERR.VALIDATION_ERROR, wantType ? ('مفيش خصم «' + wantType + '» معلّق للموظف ده في السنة دي — يمكن اتردّ قبل كده.') : 'كل الخصومات الصيفية لهذا الموظف/السنة اتردّت بالفعل — صفر خصم معلّق.');
  const refundDate = U.today(); let total = 0;
  const ops = pending.map(r => { total += r.amount; return { op: 'update', table: 'SummerDeductionLog', where: x => U.str(x.code) === r.code && U.str(x.month) === r.month && Number(x.year) === r.year && U.str(x.refundStatus) !== 'تم', patch: { refundStatus: 'تم', refundDate } }; });
  DA.batch(ops); SystemService.audit(ctx, 'summer.refund', code || 'الكل', year + ' · ' + AT.round2(total));
  return { ok: true, refundedAmount: AT.round2(total), rowsRefunded: pending.length, type: wantType };
};
/** OLD getSummerLogReport_ / refundSummerByType_ */
SalaryService.summerLogReport = function (payload) {
  const year = Number(payload.year) || 0; const wantType = U.str(payload.type); const wantD = multiFilter_(payload.dept), wantJ = multiFilter_(payload.jobTitle);
  const emps = {}; DA.readMany('Employees').forEach(r => { const e = EmployeeService._shape(r); emps[e.code] = e; });
  const rows = this.summerLog(null, year).filter(r => (!wantType || r.type === wantType)).filter(r => { const e = emps[r.code]; if (wantD.length && (!e || !wantD.includes(e.dept))) return false; if (wantJ.length && (!e || !wantJ.includes(e.jobTitle))) return false; return true; })
    .map(r => Object.assign({}, r, { nameAr: emps[r.code] ? emps[r.code].nameAr : r.code, dept: emps[r.code] ? emps[r.code].dept : '', jobTitle: emps[r.code] ? emps[r.code].jobTitle : '' }));
  return { rows, totals: { amount: AT.round2(rows.reduce((a, r) => a + r.amount, 0)), refunded: AT.round2(rows.filter(r => r.refundStatus === 'تم').reduce((a, r) => a + r.amount, 0)) } };
};
SalaryService.summerRefundByType = function (payload, ctx) {
  const year = Number(payload.year); assert(year > 2000, 'السنة غير صحيحة.'); const type = U.str(payload.type); assert(SUMMER_REFUNDABLE_TYPES_.includes(type), 'النوع لازم يكون «ربع» أو «ربعين».');
  const rows = this.summerLog(null, year).filter(r => r.refundStatus === 'لسه' && r.type === type);
  if (!rows.length) fail(ERR.VALIDATION_ERROR, 'مفيش خصم «' + type + '» معلّق في السنة دي.');
  const refundDate = U.today(); let total = 0;
  const ops = rows.map(r => { total += r.amount; return { op: 'update', table: 'SummerDeductionLog', where: x => U.str(x.code) === r.code && U.str(x.month) === r.month && Number(x.year) === year && U.str(x.type) === type && U.str(x.refundStatus) !== 'تم', patch: { refundStatus: 'تم', refundDate } }; });
  DA.batch(ops); SystemService.audit(ctx, 'summer.refundByType', type, year + ' · ' + AT.round2(total));
  return { ok: true, refundedAmount: AT.round2(total), rowsRefunded: rows.length };
};

/* ---------- ReportService («مركز التقارير» — يعيد استخدام الحسابات الموجودة) ---------- */
const ReportService = {
  CATALOG: [
    { id: 'attendanceSingle', name: 'تقرير حضور موظف واحد', description: 'عرض تفصيلي لحضور وانصراف وخصومات موظف معيّن خلال فترة محددة', section: 'reports' },
    { id: 'attendanceAll', name: 'تقرير حضور كل الموظفين', description: 'ملخص حضور وخصومات كل الموظفين خلال فترة محددة', section: 'reports' },
    { id: 'employees', name: 'بيانات الموظفين', description: 'قائمة كاملة ببيانات كل الموظفين النشطين والمنتهية خدمتهم', section: 'employees' },
    { id: 'newHires', name: 'الموظفين الجدد', description: 'قائمة الموظفين اللي تم تعيينهم خلال فترة محددة', section: 'employees' },
    { id: 'endOfService', name: 'نهاية الخدمة', description: 'قائمة الموظفين اللي انتهت خدمتهم خلال فترة محددة، مع حالة استلام المستندات والملاحظات', section: 'end_of_service_reports' },
    { id: 'employeeDocuments', name: 'تقرير مستندات موظف', description: 'كل المستندات المسجّلة لموظف معيّن وحالتها', section: 'docs_reports' },
    { id: 'missingDocuments', name: 'الموظفين الناقص لهم مستندات', description: 'قائمة الموظفين اللي لسه ناقصهم مستندات مطلوبة', section: 'docs_reports' },
    { id: 'nearExpiryDocuments', name: 'مستندات قربت تنتهي', description: 'المستندات اللي قربت تنتهي صلاحيتها', section: 'docs_reports' },
    { id: 'documentsByType', name: 'مستندات حسب النوع', description: 'حالة كل الموظفين بالنسبة لنوع مستند معيّن', section: 'docs_reports' },
    { id: 'advances', name: 'تقرير السلف', description: 'السلف وأقساطها والمحصّل والمتبقي', section: 'salary' },
    { id: 'basicSalaries', name: 'تقرير الرواتب الأساسية', description: 'الراتب الأساسي الحالي لكل موظف نشط', section: 'salary' },
    { id: 'investigations', name: 'تقرير التحقيقات', description: 'التحقيقات وموظفيها وقراراتها', section: 'legal' },
    { id: 'summerLog', name: 'سجل خصم الصيف', description: 'خصومات الصيف وحالة ردّها', section: 'payroll' },
    { id: 'monthlyClosing', name: 'اقفال شهري', description: 'جدول الإقفال الشهري أو نسخته المحفوظة', section: 'reports' }
  ],
  list(payload, ctx) { return { reports: this.CATALOG.filter(r => AuthService.can(ctx.session, r.section)) }; },
  /** OLD getNewHiresReport_ */
  newHires(payload) { const from = U.dateStr(payload.from), to = U.dateStr(payload.to); assert(from && to, 'لازم تحدد الفترة.'); const wantD = multiFilter_(payload.dept), wantJ = multiFilter_(payload.jobTitle);
    return { rows: DA.readMany('Employees').map(r => EmployeeService._shape(r)).filter(e => e.hireDate && e.hireDate >= from && e.hireDate <= to).filter(e => !wantD.length || wantD.includes(e.dept)).filter(e => !wantJ.length || wantJ.includes(e.jobTitle)).map(e => ({ code: e.code, nameAr: e.nameAr, hireDate: e.hireDate, dept: e.dept, jobTitle: e.jobTitle })) }; },
  /** OLD getEndOfServiceReport_ (مع حالة استلام المستندات) */
  endOfService(payload) { const from = U.dateStr(payload.from), to = U.dateStr(payload.to); assert(from && to, 'لازم تحدد الفترة.'); const wantD = multiFilter_(payload.dept), wantJ = multiFilter_(payload.jobTitle);
    return { rows: DA.readMany('Employees').map(r => EmployeeService._shape(r)).filter(e => e.endDate && e.endDate >= from && e.endDate <= to).filter(e => !wantD.length || wantD.includes(e.dept)).filter(e => !wantJ.length || wantJ.includes(e.jobTitle))
      .map(e => ({ code: e.code, nameAr: e.nameAr, dept: e.dept, jobTitle: e.jobTitle, hireDate: e.hireDate, endDate: e.endDate, receivedDocumentsAfterEnd: e.receivedDocumentsAfterEnd ? YES : NO })) }; },
  /** «بيانات الموظفين» في مركز التقارير */
  employees(payload) { const wantD = multiFilter_(payload.dept), wantJ = multiFilter_(payload.jobTitle); const exempt = ExemptDepartmentService.activeSet();
    return { rows: DA.readMany('Employees').map(r => EmployeeService._shape(r, exempt)).filter(e => !wantD.length || wantD.includes(e.dept)).filter(e => !wantJ.length || wantJ.includes(e.jobTitle)) }; },
  investigations(payload) { return InvestigationService.list(payload || {}); }
};

/* ---------- WhatsAppService («رسائل واتساب» — تحضير/معاينة فقط، الإرسال Click-to-Chat من المتصفح) ---------- */
const WhatsAppService = {
  TYPES: ['payroll', 'attendance', 'attendanceBrief'],
  /** OLD normalizeEgyptianPhoneForWhatsApp_ */
  normalizePhone(raw) { let p = U.str(raw).replace(/[^\d+]/g, ''); if (!p) return null; if (p.indexOf('+') === 0) p = p.slice(1); if (p.indexOf('00') === 0) p = p.slice(2);
    if (/^20\d{10}$/.test(p)) return p; if (/^0\d{10}$/.test(p)) return '20' + p.slice(1); if (/^1\d{9}$/.test(p)) return '20' + p; return null; },
  minutesText(min) { min = Number(min) || 0; const h = Math.floor(min / 60), m = min % 60; if (!h) return m + ' دقيقة'; return h + ' ساعة' + (m ? ' و' + m + ' دقيقة' : ''); },
  daysText(n) { n = Number(n) || 0; return n + ' يوم'; },
  fractionText(v) { v = Number(v) || 0; const whole = Math.floor(v), frac = AT.round2(v - whole); const fr = frac === 0.25 ? 'ربع يوم' : frac === 0.5 ? 'نص يوم' : frac === 0.75 ? 'ثلاثة أرباع يوم' : ''; if (!whole && fr) return fr; if (whole && fr) return whole + ' يوم و' + fr; return whole + ' يوم'; },
  /** OLD whatsappPreviewMessages_ — ONE context + ONE attendance pull for the whole batch */
  preview(payload) {
    const messageType = U.str(payload.messageType); const from = U.dateStr(payload.from), to = U.dateStr(payload.to);
    const codes = Array.isArray(payload.employeeCodes) ? payload.employeeCodes.map(c => U.str(c)).filter(Boolean) : [];
    assert(this.TYPES.includes(messageType) && from && to && codes.length, 'لازم تحدد نوع الرسالة، الفترة، وموظف واحد على الأقل.');
    const ctx = AttendanceCalc.buildContext(from, to);
    const messages = codes.map(code => {
      const emp = ctx.employeesByCode[code];
      if (!emp) return { code, nameAr: '', phone: '', phoneNormalized: null, messageText: '', error: 'موظف مش موجود' };
      let messageText;
      try {
        if (messageType === 'payroll') { const p = SalaryService.payrollForPeriod(code, from, to, ctx); const totalDeductions = AT.round2(p.attendanceDeductionAmount + p.cashDeductions);
          messageText = 'مرحبًا أستاذ ' + emp.nameAr + '،\n\nكشف مرتبك للفترة من ' + from + ' إلى ' + to + ':\n\nالراتب الأساسي: ' + p.basicSalary + ' جنيه\nالاستحقاقات: ' + p.earnings + ' جنيه\nإجمالي الخصومات: ' + totalDeductions + ' جنيه\nصافي المرتب: ' + p.netSalary + ' جنيه\n\nهذه رسالة آلية من نظام GHIS — لا ترد على هذه الرسالة.'; }
        else { const s = AttendanceCalc.employeeReportWithDeductions(code, from, to, ctx).summary;
          messageText = messageType === 'attendanceBrief'
            ? 'الأستاذ/ ' + emp.nameAr + '\n\nمن يوم ' + from + ' وحتى الآن:\n\nإجمالي التأخيرات: ' + s.lateMinutesTotal + ' دقيقة\nإجمالي الغيابات: ' + s.absentDays + ' يوم\nإجمالي الخصومات: ' + s.totalDeduction + ' يوم'
            : 'أستاذ ' + emp.nameAr + '،\n\nحتى الآن:\n\n• إجمالي التأخير: ' + this.minutesText(s.lateMinutesTotal) + '\n• أيام الغياب: ' + this.daysText(s.absentDays) + '\n• نسيان تسجيل الحضور/الانصراف: ' + this.daysText(s.forgotCheckinsCount) + '\n• إجمالي الخصم: ' + this.fractionText(s.totalDeduction) + '\n\nهذه رسالة آلية من نظام GHIS — لا ترد على هذه الرسالة.'; }
      } catch (err) { return { code, nameAr: emp.nameAr, phone: emp.phone || '', phoneNormalized: null, messageText: '', error: String(err && err.message || err) }; }
      return { code, nameAr: emp.nameAr, phone: emp.phone || '', phoneNormalized: this.normalizePhone(emp.phone), messageText };
    });
    return { messages };
  },
  /** The message itself is sent by the browser through wa.me — the server never sends anything externally.
      This records that the user actually sent it (audit trail, OLD sequential-send workflow). */
  logSent(payload, ctx) { const code = U.str(payload.code); assert(code, 'كود الموظف مطلوب.'); EmployeeService._exists(code); SystemService.audit(ctx, 'whatsapp.sent', code, U.str(payload.messageType)); return { ok: true }; }
};

/* ---------- NotificationService («التنبيهات والإشعارات») ---------- */
const NotificationService = {
  TYPES: ['عام', 'تقرير حضور', 'كشف مرتب'],
  SCOPES: ['كل الموظفين', 'قسم', 'موظف'],
  _shape(r) { return { id: U.str(r.id), type: U.str(r.type), period: U.str(r.period), title: U.str(r.title), message: U.str(r.message), contents: U.str(r.contents).split(',').map(x => U.str(x)).filter(Boolean), showDate: U.dateStr(r.showDate), showTime: U.timeText(r.showTime, ''), scope: U.str(r.scope), scopeValue: U.str(r.scopeValue), status: U.str(r.status), createdBy: U.str(r.createdBy), createdAt: U.stamp(r.createdAt), updatedAt: U.stamp(r.updatedAt), durationMinutes: Number(r.durationMinutes) || 0, reportFrom: U.dateStr(r.reportFrom), reportTo: U.dateStr(r.reportTo) }; },
  _validate(cfg) {
    const type = U.str(cfg.type); assert(this.TYPES.includes(type), 'نوع الإشعار غير معروف.');
    const title = U.str(cfg.title); assert(title, 'عنوان الإشعار مطلوب.');
    const showDate = U.dateStr(cfg.showDate); assert(showDate, 'تاريخ الإظهار غير صحيح.');
    const showTime = U.timeText(cfg.showTime, ''); assert(showTime, 'وقت الإظهار غير صحيح.');
    const scope = U.str(cfg.scope); assert(this.SCOPES.includes(scope), 'نطاق الإشعار غير معروف.');
    const scopeValue = U.str(cfg.scopeValue); assert(scope === 'كل الموظفين' || scopeValue, 'قيمة النطاق مطلوبة.');
    const durationMinutes = Number(cfg.durationMinutes) || 0; assert(durationMinutes >= 0, 'مدة الظهور غير صحيحة.');
    return { type, period: U.str(cfg.period), title, message: U.str(cfg.message), contents: multiFilter_(cfg.contents).join(','), showDate, showTime, scope, scopeValue, durationMinutes, reportFrom: U.str(cfg.reportFrom) ? U.dateStr(cfg.reportFrom) : '', reportTo: U.str(cfg.reportTo) ? U.dateStr(cfg.reportTo) : '' };
  },
  /** OLD resolveNotificationTargets_ */
  targets(scope, scopeValue) { const exempt = ExemptDepartmentService.activeSet(); const emps = DA.readMany('Employees').map(r => EmployeeService._shape(r, exempt)).filter(e => e.status === 'نشط');
    if (scope === 'كل الموظفين') return emps.map(e => e.code);
    if (scope === 'قسم') return emps.filter(e => e.dept === scopeValue).map(e => e.code);
    return emps.filter(e => e.code === scopeValue).map(e => e.code); },
  list(payload) { const rows = DA.readMany('Notifications').map(this._shape); const counts = {}; DA.readMany('NotificationTargets').forEach(t => { const id = U.str(t.id); counts[id] = (counts[id] || 0) + 1; }); return { notifications: rows.map(n => Object.assign({}, n, { targets: counts[n.id] || 0 })).reverse(), types: this.TYPES, scopes: this.SCOPES }; },
  create(payload, ctx) {
    const v = this._validate(payload); const codes = this.targets(v.scope, v.scopeValue); assert(codes.length, 'مفيش موظفين مطابقين للنطاق المحدد.');
    const id = U.uuid(); const showAt = v.showDate + ' ' + v.showTime; const hideAt = U.notifHideAt(v.showDate, v.showTime, v.durationMinutes);   // V1 buildNotifHideAt_
    const ops = [{ op: 'insert', table: 'Notifications', data: Object.assign({ id, status: NOTIF_STATUS.SCHEDULED, createdBy: ctx.session.username, createdAt: U.now(), updatedAt: U.now() }, v) }];
    codes.forEach(c => ops.push({ op: 'insert', table: 'NotificationTargets', data: { id, code: c, showAt, readAt: '', hideAt } }));
    DA.batch(ops); SystemService.audit(ctx, 'notification.create', id, v.title + ' · ' + codes.length + ' مستهدف');
    return { ok: true, id, targets: codes.length };
  },
  update(payload, ctx) {
    const id = U.str(payload.id); const cur = DA.readOne('Notifications', { id }); if (!cur) fail(ERR.NOT_FOUND, 'الإشعار مش موجود.');
    assert(U.str(cur.status) !== NOTIF_STATUS.CANCELLED, 'الإشعار ملغي — مينفعش يتعدّل.');
    const v = this._validate(payload); const codes = this.targets(v.scope, v.scopeValue); assert(codes.length, 'مفيش موظفين مطابقين للنطاق المحدد.');
    const showAt = v.showDate + ' ' + v.showTime; const hideAt = U.notifHideAt(v.showDate, v.showTime, v.durationMinutes);   // V1 buildNotifHideAt_
    DA.update('Notifications', { id }, Object.assign({ updatedAt: U.now() }, v));
    DA.remove('NotificationTargets', { id });
    DA.batch(codes.map(c => ({ op: 'insert', table: 'NotificationTargets', data: { id, code: c, showAt, readAt: '', hideAt } })));
    SystemService.audit(ctx, 'notification.update', id, v.title); return { ok: true, targets: codes.length };
  },
  cancel(payload, ctx) { const id = U.str(payload.id); assert(DA.update('Notifications', { id }, { status: NOTIF_STATUS.CANCELLED, updatedAt: U.now() }) > 0, 'الإشعار مش موجود.'); SystemService.audit(ctx, 'notification.cancel', id, U.str(payload.reason)); return { ok: true }; },
  remove(payload, ctx) { const id = U.str(payload.id); const n = DA.remove('Notifications', { id }); assert(n > 0, 'الإشعار مش موجود.'); DA.remove('NotificationTargets', { id }); SystemService.audit(ctx, 'notification.delete', id, ''); return { ok: true }; },
  /** for the employee app (bridge op "notifications") — read-only */
  forEmployee(code) { const now = U.notifNow(); const ids = {};
    DA.readMany('NotificationTargets').forEach(t => { if (U.str(t.code) !== code) return;
      const showAt = U.notifStamp(t.showAt); if (!showAt || showAt > now) return;          // V1: تطبيع إلزامي قبل المقارنة
      const hideAt = U.notifStamp(t.hideAt); if (hideAt && now >= hideAt) return;          // V1: نافذة الظهور
      ids[U.str(t.id)] = U.str(t.readAt); });
    return DA.readMany('Notifications').map(this._shape).filter(n => n.id in ids && n.status !== NOTIF_STATUS.CANCELLED).map(n => ({ id: n.id, type: n.type, title: n.title, message: n.message, showDate: n.showDate, contents: n.contents, reportFrom: n.reportFrom, reportTo: n.reportTo, read: !!ids[n.id] })); },
  markRead(code, id) { const n = DA.update('NotificationTargets', { id, code }, { readAt: U.now() }); return { status: n ? 'ok' : 'error' }; }
};

/* ---------- BackupService («نسخ احتياطي» — JSON snapshot of the V2 sheets into Drive via FileStore) ---------- */
const BackupService = {
  /** Explicit, permission-protected. Never touches the attendance file or the old GHIS spreadsheet. */
  create(payload, ctx) {
    const description = U.str(payload.description) || 'نسخة احتياطية يدوية';
    const data = { backupVersion: 'v2', generatedAt: U.now(), version: CFG.VERSION, spreadsheetId: PropertiesService.getScriptProperties().getProperty(CFG.PROP_SSID), sheets: {} };
    Object.keys(SCHEMA).forEach(name => { data.sheets[SCHEMA[name].sheet] = { key: name, headers: SCHEMA[name].headers, rows: DA.readMany(name) }; });
    const json = JSON.stringify(data);
    const stored = FileStore.saveBase64(FileStore.FOLDER_BACKUPS, Utilities.base64Encode(Utilities.newBlob(json, 'application/json', 'x').getBytes()), 'application/json', 'GHIS_V2_backup_' + U.now().slice(0, 19).replace(/[:T]/g, '-') + '.json');
    const id = 'BK-' + U.uuid().replace(/-/g, '').slice(0, 8).toUpperCase();
    DA.insert('BackupRegistry', { id, timestamp: U.now(), description, source: 'GHIS V2', size: json.length, fileId: stored.fileId, user: ctx.session.username });
    SystemService.audit(ctx, 'backup.create', id, description);
    return { ok: true, id, fileId: stored.fileId, size: json.length, sheets: Object.keys(data.sheets).length };
  },
  list() { return { backups: DA.readMany('BackupRegistry').map(r => ({ id: U.str(r.id), timestamp: U.stamp(r.timestamp), description: U.str(r.description), source: U.str(r.source), size: Number(r.size) || 0, fileId: U.str(r.fileId), user: U.str(r.user) })).reverse() }; },
  download(payload) { const r = DA.readOne('BackupRegistry', { id: U.str(payload.id) }); if (!r || !U.str(r.fileId)) fail(ERR.NOT_FOUND, 'النسخة مش موجودة.'); return { file: FileStore.getBase64(U.str(r.fileId)) }; },
  /** Restore is explicit, confirmed and scoped: it only APPENDS missing rows of the chosen sheet — never wipes a sheet. */
  restore(payload, ctx) {
    assert(ctx.session.isAdmin, 'الاسترجاع للأدمن بس.');
    assert(U.str(payload.confirm) === 'استرجاع', 'لتأكيد الاسترجاع اكتب كلمة «استرجاع» بالضبط.');
    const sheetKey = U.str(payload.sheetKey); assert(SCHEMA[sheetKey], 'الشيت مش معروف.');
    const r = DA.readOne('BackupRegistry', { id: U.str(payload.id) }); if (!r || !U.str(r.fileId)) fail(ERR.NOT_FOUND, 'النسخة مش موجودة.');
    const file = FileStore.getBase64(U.str(r.fileId));
    const json = U.parseJson(Utilities.newBlob(Utilities.base64Decode(file.base64)).getDataAsString(), null); assert(json && json.sheets, 'ملف النسخة غير صالح.');
    const entry = json.sheets[SCHEMA[sheetKey].sheet]; assert(entry, 'الشيت مش موجود في النسخة دي.');
    const keys = SCHEMA[sheetKey].keys; const current = DA.readMany(sheetKey);
    const sig = row => keys.map(k => U.str(row[k])).join('\u0001');
    const have = {}; current.forEach(row => { have[sig(row)] = true; });
    const missing = (entry.rows || []).filter(row => !have[sig(row)]);
    if (!missing.length) return { ok: true, restored: 0, note: 'كل صفوف النسخة موجودة بالفعل — مفيش أي كتابة.' };
    DA.batch(missing.map(row => ({ op: 'insert', table: sheetKey, data: row })));
    SystemService.audit(ctx, 'backup.restore', sheetKey, U.str(payload.id) + ' · ' + missing.length + ' صف');
    return { ok: true, restored: missing.length, sheet: SCHEMA[sheetKey].sheet, note: 'تمت إضافة الصفوف الناقصة فقط — مفيش أي صف اتمسح أو اتعدّل.' };
  }
};

/* ---------- SystemService ---------- */
/** OLD RESET_GROUPS_ — the ONLY sheets «إعادة ضبط بيانات النظام» may clear (security/config sheets are not listed, so they can never be cleared). */
const RESET_GROUPS_ = {
  employees:         { label: 'بيانات الموظفين', tables: ['Employees'] },
  employeeHistory:   { label: 'سجل تعديلات بيانات الموظفين', tables: ['EmployeeHistory'] },
  employeeDocs:      { label: 'مستندات الموظفين', tables: ['EmployeeDocuments'] },
  investigations:    { label: 'التحقيقات ومرفقاتها', tables: ['Investigations', 'InvestigationEmployees', 'InvestigationAttachments'] },
  enrollment:        { label: 'طلبات استكمال البيانات', tables: ['EnrollmentStatus', 'ProfileRequests'] },
  attendanceAdj:     { label: 'تعديلات الحضور والإجازات', tables: ['Adjustments', 'ApprovedLeaves'] },
  forgotCheckin:     { label: 'سجل نسيان تسجيل الحضور/الانصراف', tables: ['ForgotCheckinLog'] },
  lateWaivers:       { label: 'إعفاءات التأخير والأذونات', tables: ['LateWaivers', 'PermissionsLog'] },
  checkoutExc:       { label: 'استثناءات إلزام الانصراف', tables: ['CheckoutExceptions'] },
  scheduleOverrides: { label: 'مواعيد خاصة لموظفين', tables: ['ScheduleOverrides'] },
  salaryTx:          { label: 'حركات المرتب', tables: ['SalaryTransactions'] },
  advances:          { label: 'السلف وأقساطها', tables: ['Advances'] },
  extraPenalties:    { label: 'الخصومات الإضافية والجزاءات', tables: ['ExtraPenaltiesLog'] },
  summerDeduction:   { label: 'خصم الصيف وتصنيف الأقسام', tables: ['SummerDeductionLog', 'SummerDeductionDepartments'] },
  closingPeriods:    { label: 'فترات المرتبات وسجل الإقفال', tables: ['Periods', 'PeriodCloseLog'] },
  closingSnapshots:  { label: 'نسخ الإقفال الشهري', tables: ['ClosingSnapshots', 'ClosingSnapshotRows'] },
  notifications:     { label: 'التنبيهات والإشعارات', tables: ['Notifications', 'NotificationTargets'] },
  syncLog:           { label: 'سجل مزامنة الموظفين', tables: ['SyncLog'] },
  photoReview:       { label: 'مراجعة الصور ومطابقة الوجه', tables: ['PhotoReview', 'FaceReview'] }
};

const SystemService = {
  audit(ctx, action, target, details) { DA.insert('AuditLog', { timestamp: U.now(), username: ctx.session.username, action, target: U.str(target), details: U.str(details) }); },
  ping() { return { version: CFG.VERSION, serverTime: U.now() }; },
  /** Read-only: the whitelist the reset screen may offer, with the current row count of each group. */
  resetGroups(ctx) { assert(ctx.session.isAdmin, 'إعادة ضبط البيانات للأدمن بس.');
    return { groups: Object.keys(RESET_GROUPS_).map(k => ({ key: k, label: RESET_GROUPS_[k].label, sheets: RESET_GROUPS_[k].tables.map(t => SCHEMA[t].sheet), rows: RESET_GROUPS_[k].tables.reduce((a, t) => a + DA.readMany(t).length, 0) })) }; },
  /** OLD resetProductionData_: admin only → whitelist → password re-check → mandatory backup → clear data rows only (headers kept). */
  resetData(payload, ctx) {
    assert(ctx.session.isAdmin, 'إعادة ضبط البيانات للأدمن بس.');
    const list = (Array.isArray(payload.groups) ? payload.groups : []).map(x => U.str(x)).filter(Boolean);
    assert(list.length, 'اختر عنصراً واحداً على الأقل.');
    const unknown = list.filter(k => !RESET_GROUPS_[k]); assert(!unknown.length, 'عنصر غير معروف: ' + unknown.join(', '));
    const u = DA.readOne('Users', { username: ctx.session.username });
    if (!u || U.hash(U.str(payload.password), U.str(u.salt)) !== U.str(u.passwordHash)) fail(ERR.AUTH_ERROR, 'كلمة المرور غير صحيحة — ماتمّ حذف أي شيء.');
    assert(U.str(payload.confirm) === 'إعادة ضبط', 'للتأكيد اكتب «إعادة ضبط» بالظبط — ماتمّ حذف أي شيء.');
    return U.withLock(() => {
      let backup; try { backup = BackupService.create({ description: 'قبل إعادة ضبط البيانات' }, ctx); } catch (e) { fail(ERR.SERVER_ERROR, 'فشل إنشاء النسخة الاحتياطية — ماتمّ حذف أي شيء. ' + (e && e.message || e)); }
      assert(backup && backup.ok, 'النسخة الاحتياطية ماتمتش — ماتمّ حذف أي شيء.');
      const cleared = [], failed = [], seen = {}; let total = 0;
      list.forEach(key => RESET_GROUPS_[key].tables.forEach(t => { if (seen[t]) return; seen[t] = true;
        try { const n = DA.remove(t, () => true); cleared.push({ sheet: SCHEMA[t].sheet, rows: n }); total += n; } catch (e) { failed.push({ sheet: SCHEMA[t].sheet, error: String(e && e.message || e) }); } }));
      SystemService.audit(ctx, 'system.resetData', list.join(','), total + ' صف · نسخة v' + backup.version);
      return { ok: failed.length === 0, backupVersion: backup.version, cleared, failed, totalRows: total };
    }, 60000);
  },
  /** يحوّل نفس بيانات التصدير (headers/rows) لملف PDF — نفس الأعمدة والإجماليات، صفر حسابات جديدة. */
  pdf(payload) {
    const title = U.str(payload.title) || 'تقرير';
    const headers = Array.isArray(payload.headers) ? payload.headers : []; const rows = Array.isArray(payload.rows) ? payload.rows : [];
    assert(headers.length, 'مفيش أعمدة للتصدير.');
    const esc = v => U.str(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const pgEmp = payload.employee || null, pgPeriod = payload.period || null;
    let chips = '';
    if (pgPeriod && pgPeriod.from && pgPeriod.to) chips += '<span class="chip">📅 من ' + esc(pgPeriod.from) + ' إلى ' + esc(pgPeriod.to) + '</span>';
    if (pgEmp) chips += '<span class="chip">👤 ' + esc(pgEmp.nameAr) + ' (' + esc(pgEmp.code) + ')</span>';
    chips = chips ? '<div class="chips">' + chips + '</div>' : '';
    const totals = Array.isArray(payload.totals) ? payload.totals : [];
    const pg = payload.page || {};
    const size = (U.str(pg.size).toUpperCase() === 'A3') ? 'A3' : 'A4';
    const orientation = (U.str(pg.orientation) === 'landscape') ? 'landscape' : 'portrait';
    const fontPt = Math.min(12, Math.max(6, Number(pg.fontPt) || 9));
    const html = '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="UTF-8"><style>'
      + '@page{size:' + size + ' ' + orientation + ';margin:7mm}'
      + 'body{font-family:Arial,"Times New Roman",sans-serif;direction:rtl;margin:0;color:#1f2937}'
      + '.report-head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2.5px solid #123166;padding-bottom:6px;margin-bottom:8px}'
      + '.report-brand strong{display:block;font-size:12pt;color:#123166;font-weight:bold}.report-brand span{font-size:7pt;color:#555}'
      + '.report-title{font-size:13pt;font-weight:bold;color:#123166;margin:0 0 5px}'
      + '.chips{margin-bottom:7px}.chip{display:inline-block;background:#eef3fb;color:#123166;border:1px solid rgba(18,49,102,.14);border-radius:20px;padding:2px 9px;font-size:7.5pt;margin-left:4px}'
      /* V1 table presentation: table-layout fixed + رأس ملوّن + حدود + تظليل الصفوف الزوجية + تكرار الرأس */
      + 'table{width:100%;border-collapse:collapse;font-size:' + fontPt + 'pt;table-layout:fixed}'
      + 'thead{display:table-header-group}tr{page-break-inside:avoid;break-inside:avoid}'
      + 'th{background:#123166;color:#fff;font-weight:bold;padding:4px 3px;text-align:center;border:1px solid #0d2650;white-space:normal;word-break:normal;overflow-wrap:break-word;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
      + 'td{padding:3px;text-align:center;border:1px solid #c9ced8;white-space:normal;overflow-wrap:break-word}'
      + 'tbody tr:nth-child(even) td{background:#f7f8fb}td.name{text-align:right}'
      + '.totals{margin-top:9px;padding:7px 9px;background:#eef3fb;border:1px solid rgba(18,49,102,.12);border-radius:6px;page-break-inside:avoid}'
      + '.totals-item{display:inline-block;min-width:170px;font-size:8pt;margin:2px 10px 2px 0}.totals-item b{color:#123166}'
      + '.foot{margin-top:10px;padding-top:5px;border-top:1px solid #d5dae3;display:flex;justify-content:space-between;font-size:7pt;color:#666}</style></head><body>'
      + '<div class="report-head"><div class="report-brand"><strong>Green Heights International School</strong><span>نظام شئون العاملين — GHIS</span></div>'
      + '<div style="font-size:7pt;color:#555;text-align:left">تاريخ الاستخراج<br><b>' + esc(U.stamp(U.now())) + '</b></div></div>'
      + '<div class="report-title">' + esc(title) + '</div>' + chips
      + '<table><thead><tr>' + headers.map(h => '<th>' + esc(h) + '</th>').join('') + '</tr></thead><tbody>'
      + rows.map(r => '<tr>' + (Array.isArray(r) ? r : []).map((c, i) => '<td' + (i === 1 ? ' class="name"' : '') + '>' + esc(c) + '</td>').join('') + '</tr>').join('')
      + '</tbody></table>'
      + (totals.length ? '<div class="totals">' + totals.map(t => '<span class="totals-item">' + esc(t.label) + ': <b>' + esc(String(t.value)) + '</b></span>').join('') + '</div>' : '')
      + '<div class="foot"><span>Green Heights International School · نظام شئون العاملين (GHIS)</span><span>' + size + ' ' + (orientation === 'landscape' ? 'أفقي' : 'رأسي') + ' · ' + headers.length + ' عمود · ' + rows.length + ' صف · مُستخرج تلقائيًا</span></div></body></html>';
    const blob = Utilities.newBlob(html, 'text/html', (U.str(payload.fileName) || title) + '.html').getAs('application/pdf');
    return { file: { name: (U.str(payload.fileName) || title) + '.pdf', mimeType: 'application/pdf', base64: Utilities.base64Encode(blob.getBytes()) } };
  },
  schemaInfo() { return { spreadsheetId: PropertiesService.getScriptProperties().getProperty(CFG.PROP_SSID), attendanceConfigured: !!AttendanceService.attId(), bridgeConfigured: !!U.str(PropertiesService.getScriptProperties().getProperty(CFG.PROP_BRIDGE_SECRET)), sheets: Object.keys(SCHEMA).map(n => ({ key: n, sheet: SCHEMA[n].sheet, owner: SCHEMA[n].owner, columns: SCHEMA[n].headers })) }; },
  perf() { DA.reset(); const out = { sheets: {} }; Object.keys(SCHEMA).forEach(n => { const t = Date.now(); const rows = DA.readMany(n).length; out.sheets[n] = { ms: Date.now() - t, rows }; }); return out; }
};

/* ===================================================================== */
/* 7. ROUTER — action → { fn, auth, section }  (section = OLD permission key) */
/* ===================================================================== */

const ROUTES = {
  'system.ping': { auth: false, fn: () => SystemService.ping() },
  'system.loginAsset': { auth: false, fn: () => { const i = loginAssetInfo_(); return { dataUri: i.dataUri, diag: { file: i.file, error: i.error, rawLength: i.rawLength, method: i.method, extractedLength: i.extractedLength } }; } },
  'auth.login': { auth: false, fn: (p) => AuthService.login(p) },
  'auth.logout': { fn: (p, c) => AuthService.logout(p, c) },
  'auth.changePassword': { fn: (p, c) => UserService.changeOwnPassword(p, c) },
  'app.bootstrap': { fn: (p, c) => AppService.bootstrap(c.session) },
  // بيانات الموظفين
  'employees.list': { section: 'employees', fn: () => EmployeeService.list() },
  'employees.get': { section: 'employees', fn: (p) => EmployeeService.get(p) },
  'employees.add': { section: 'employees', fn: (p, c) => EmployeeService.add(p, c) },
  'employees.update': { section: 'employees', fn: (p, c) => EmployeeService.update(p, c) },
  'employees.bulkAdd': { section: 'employees', fn: (p, c) => EmployeeService.bulkAdd(p, c) },
  'employees.history': { section: 'employees', fn: (p) => EmployeeService.history(p) },
  'employees.exportPermit': { section: 'employees_export', fn: () => ({ ok: true }) },
  'employees.syncStatus': { section: 'employees', fn: () => AttendanceService.statuses() },
  'employees.syncRetry': { section: 'employees', fn: (p, c) => AttendanceService.retry(p, c) },
  'employees.resetPassword': { section: 'employees', fn: (p, c) => AttendanceService.resetEmployeePassword(p, c) },
  'employees.photo.view': { section: 'employees', fn: (p) => PhotoService.view(p) },
  'employees.photo.upload': { section: 'employees', fn: (p, c) => PhotoService.upload(p, c) },
  'employees.photo.delete': { section: 'employees', fn: (p, c) => PhotoService.remove(p, c) },
  'employees.scheduleOverrides': { section: 'employees', fn: (p) => ScheduleOverrideService.listForEmployee(p) },
  'employees.scheduleOverrideAdd': { section: 'rules', fn: (p, c) => ScheduleOverrideService.add(p, c) },
  'employees.salaryTransactions': { section: 'salary', fn: (p) => SalaryService.transactionsForEmployee(p) },
  'employees.salaryTransactionAdd': { section: 'salary', fn: (p, c) => SalaryService.add(p, c) },
  // طلبات استكمال البيانات
  'photos.reviewList': { section: 'employees', fn: () => PhotoService.reviewList() },
  'photos.pendingView': { section: 'employees', fn: (p) => PhotoService.pendingView(p) },
  'photos.approve': { section: 'employees', fn: (p, c) => PhotoService.approve(p, c) },
  'photos.reject': { section: 'employees', fn: (p, c) => PhotoService.reject(p, c) },
  'photos.adminReplace': { section: 'employees', fn: (p, c) => PhotoService.adminReplace(p, c) },
  'photos.faceReviewList': { section: 'employees', fn: () => PhotoService.faceList() },
  'photos.faceReviewClear': { section: 'employees', fn: (p, c) => PhotoService.faceClear(p, c) },
  'profileRequests.pending': { section: 'employees', fn: () => ProfileRequestService.pending() },
  'profileRequests.approve': { section: 'employees', fn: (p, c) => ProfileRequestService.approve(p, c) },
  'profileRequests.reject': { section: 'employees', fn: (p, c) => ProfileRequestService.reject(p, c) },
  // الإعدادات
  'settings.nameLists': { section: 'settings', fn: () => NameListService.all() },
  'settings.nameListManage': { section: 'settings', fn: (p, c) => NameListService.manage(p, c) },
  'settings.employeeCode.get': { section: 'employees', fn: () => ({ autoGenerate: SettingsService.autoCodeEnabled() }) },
  'settings.employeeCode.set': { section: 'employees', fn: (p, c) => SettingsService.setAutoCode(p, c) },
  'settings.exemptDepartments': { section: 'employees', fn: () => ExemptDepartmentService.list() },
  'settings.exemptDepartmentAdd': { section: 'employees', fn: (p, c) => ExemptDepartmentService.add(p, c) },
  'settings.exemptDepartmentRemove': { section: 'employees', fn: (p, c) => ExemptDepartmentService.remove(p, c) },
  'settings.academicYears': { section: 'settings', fn: () => AcademicYearService.list() },
  'settings.academicYearAdd': { section: 'settings', fn: (p, c) => AcademicYearService.add(p, c) },
  'settings.academicYearUpdate': { section: 'settings', fn: (p, c) => AcademicYearService.update(p, c) },
  'settings.academicYearSetActive': { section: 'settings', fn: (p, c) => AcademicYearService.setActive(p, c) },
  // المستخدمين والصلاحيات
  'users.list': { section: 'users', fn: () => UserService.list() },
  'users.add': { section: 'users', fn: (p, c) => UserService.add(p, c) },
  'users.toggle': { section: 'users', fn: (p, c) => UserService.toggle(p, c) },
  'users.resetPassword': { section: 'users', fn: (p, c) => UserService.adminResetPassword(p, c) },
  'roles.list': { section: 'users', fn: () => RoleService.list() },
  'roles.save': { section: 'users', fn: (p, c) => RoleService.save(p, c) },
  'roles.toggle': { section: 'users', fn: (p, c) => RoleService.toggle(p, c) },
  'roles.matrixGet': { section: 'users', fn: (p) => RoleService.matrixGet(p) },
  'roles.matrixSave': { section: 'users', fn: (p, c) => RoleService.matrixSave(p, c) },
  // الشئون القانونية — المستندات (OLD keys: docs_view / docs_add / docs_delete / docs_types_manage / docs_reports / documents_export)
  'documents.types': { section: 'docs_view', fn: () => DocumentService.typesList() },
  'documents.typeAdd': { section: 'docs_types_manage', fn: (p, c) => DocumentService.typeAdd(p, c) },
  'documents.typeUpdate': { section: 'docs_types_manage', fn: (p, c) => DocumentService.typeUpdate(p, c) },
  'documents.typeToggle': { section: 'docs_types_manage', fn: (p, c) => DocumentService.typeToggle(p, c) },
  'documents.employee': { section: 'docs_view', fn: (p) => DocumentService.employeeDocs(p) },
  'documents.add': { section: 'docs_add', fn: (p, c) => DocumentService.add(p, c) },
  'documents.delete': { section: 'docs_delete', fn: (p, c) => DocumentService.remove(p, c) },
  'documents.file': { section: 'docs_view', fn: (p) => DocumentService.file(p) },
  'documents.reportMissing': { section: 'docs_reports', fn: (p) => DocumentService.reportMissing(p) },
  'documents.reportNearExpiry': { section: 'docs_reports', fn: (p) => DocumentService.reportNearExpiry(p) },
  'documents.reportByType': { section: 'docs_reports', fn: (p) => DocumentService.reportByType(p) },
  'documents.exportPermit': { section: 'documents_export', fn: () => ({ ok: true }) },
  'documents.nearDays.get': { section: 'docs_view', fn: () => DocumentService.nearDaysGet() },
  'documents.nearDays.set': { section: 'docs_types_manage', fn: (p, c) => DocumentService.nearDaysSet(p, c) },
  // الشئون القانونية — التحقيقات (OLD key: legal)
  'investigations.list': { section: 'legal', fn: (p) => InvestigationService.list(p) },
  'investigations.save': { section: 'legal', fn: (p, c) => InvestigationService.save(p, c) },
  'investigations.cancel': { section: 'legal', fn: (p, c) => InvestigationService.cancel(p, c) },
  'investigations.attachmentAdd': { section: 'legal', fn: (p, c) => InvestigationService.attachmentAdd(p, c) },
  'investigations.attachmentRemove': { section: 'legal', fn: (p, c) => InvestigationService.attachmentRemove(p, c) },
  'investigations.attachmentFile': { section: 'legal', fn: (p) => InvestigationService.attachmentFile(p) },
  // الحضور والإقفال — القواعد
  'rules.list': { section: 'rules', fn: () => RulesService.list() },
  'rules.scheduleRuleAdd': { section: 'rules', fn: (p, c) => RulesService.scheduleRuleAdd(p, c) },
  'rules.scheduleRuleUpdate': { section: 'rules', fn: (p, c) => RulesService.scheduleRuleUpdate(p, c) },
  'rules.scheduleRuleArchive': { section: 'rules', fn: (p) => RulesService.scheduleRuleArchive(p) },
  'rules.deductionRuleAdd': { section: 'rules', fn: (p, c) => RulesService.deductionRuleAdd(p, c) },
  'rules.holidayAdd': { section: 'rules', fn: (p, c) => RulesService.holidayAdd(p, c) },
  'rules.publicHolidays': { section: 'rules', fn: () => HolidayService.list() },
  'rules.publicHolidaySync': { section: 'rules', fn: (p, c) => HolidayService.sync(p, c) },
  'rules.publicHolidayCalendar': { section: 'rules', fn: (p) => HolidayService.calendar(p) },
  'rules.publicHolidayCancel': { section: 'rules', fn: (p, c) => HolidayService.cancel(p, c) },
  'rules.publicHolidayReactivate': { section: 'rules', fn: (p, c) => HolidayService.reactivate(p, c) },
  'rules.publicHolidaySetObserved': { section: 'rules', fn: (p, c) => HolidayService.setObserved(p, c) },
  // الحضور والإقفال — إعفاءات التأخير والخصومات
  'attendance.adjustments': { section: 'attendance_adjust', fn: (p) => AttendanceAdjustService.list(p) },
  'attendance.permissionAdd': { section: 'permissions_add', fn: (p, c) => AttendanceAdjustService.permissionAdd(p, c) },
  'attendance.forgotCheckinAdd': { section: 'attendance_adjust', fn: (p, c) => AttendanceAdjustService.forgotCheckinAdd(p, c) },
  'attendance.extraPenaltyAdd': { section: 'attendance_adjust', fn: (p, c) => AttendanceAdjustService.extraPenaltyAdd(p, c) },
  'attendance.checkoutExceptionAdd': { section: 'attendance_adjust', fn: (p, c) => AttendanceAdjustService.checkoutExceptionAdd(p, c) },
  'attendance.leaveAdd': { section: 'attendance_adjust', fn: (p, c) => AttendanceAdjustService.leaveAdd(p, c) },
  'attendance.adjustmentAdd': { section: 'attendance_adjust', fn: (p, c) => AttendanceAdjustService.adjustmentAdd(p, c) },
  'attendance.lateWaivers': { section: 'attendance_adjust', fn: (p) => LateWaiverService.list(p) },
  'attendance.lateWaiverAdd': { section: 'attendance_adjust', fn: (p, c) => LateWaiverService.add(p, c) },
  'attendance.lateWaiverUpdate': { section: 'attendance_adjust', fn: (p, c) => LateWaiverService.update(p, c) },
  'attendance.lateWaiverDelete': { section: 'attendance_adjust', fn: (p, c) => LateWaiverService.remove(p, c) },
  'attendance.dayLateInfo': { section: 'attendance_adjust', fn: (p) => LateWaiverService.dayInfo(p) },
  // الحضور والإقفال — اقفال شهري
  'closing.periods': { section: 'reports', fn: () => ClosingService.list() },
  'closing.periodAdd': { section: 'period_close', fn: (p, c) => ClosingService.periodAdd(p, c) },
  'closing.monthlyClosing': { section: 'reports', fn: (p) => ClosingService.closing(p) },
  'closing.close': { section: 'period_close', fn: (p, c) => ClosingService.close(p, c) },
  'closing.reopen': { section: 'period_reopen', fn: (p, c) => ClosingService.reopen(p, c) },
  'closing.history': { section: 'reports', fn: (p) => ClosingService.history(p) },
  'closing.exportPermit': { section: 'attendance_export', fn: () => ({ ok: true }) },
  // التقارير (تقارير الحضور)
  'reports.attendance': { section: 'reports', fn: (p) => AttendanceReportService.report(p) },
  'reports.exportPermit': { section: 'attendance_export', fn: () => ({ ok: true }) },
  // مركز التقارير
  'reportCenter.list': { fn: (p, c) => ReportService.list(p, c) },   // session-only: the catalogue itself is filtered per permission (OLD behaviour)
  'reportCenter.newHires': { section: 'employees', fn: (p) => ReportService.newHires(p) },
  'reportCenter.endOfService': { section: 'end_of_service_reports', fn: (p) => ReportService.endOfService(p) },
  'reportCenter.employees': { section: 'employees', fn: (p) => ReportService.employees(p) },
  'reportCenter.investigations': { section: 'legal', fn: (p) => ReportService.investigations(p) },
  // الرواتب
  'payroll.salaryTable': { section: 'salary', fn: (p) => SalaryService.salaryTable(p) },
  'payroll.employee': { section: 'salary', fn: (p) => SalaryService.employeePayroll(p) },
  'payroll.basicSalaries': { section: 'salary', fn: (p) => SalaryService.basicSalariesReport(p) },
  'payroll.basicSalariesSave': { section: 'salary', fn: (p, c) => SalaryService.basicSalariesSave(p, c) },
  'payroll.allowanceApprove': { section: 'salary', fn: (p, c) => SalaryService.allowanceApprove(p, c) },
  'payroll.rates': { section: 'salary', fn: () => SalaryService.ratesGet() },
  'payroll.ratesSet': { section: 'salary', fn: (p, c) => SalaryService.ratesSet(p, c) },
  'payroll.yearSettings': { section: 'salary', fn: (p) => SalaryService.yearSettingsGet(p) },
  'payroll.yearSettingsSet': { section: 'salary', fn: (p, c) => SalaryService.yearSettingsSet(p, c) },
  'payroll.quarterPolicy': { section: 'settings', fn: (p) => SalaryService.quarterPolicyGet(p) },
  'payroll.quarterPolicySet': { section: 'settings', fn: (p, c) => SalaryService.quarterPolicySet(p, c) },
  'payroll.exportPermit': { section: 'salary', fn: () => ({ ok: true }) },
  // حركات المرتب وأنواعها
  'payroll.txTypes': { section: 'salary', fn: () => SalaryService.typesList() },
  'payroll.txTypeAdd': { section: 'salary', fn: (p, c) => SalaryService.typeAdd(p, c) },
  'payroll.txTypeToggle': { section: 'salary', fn: (p, c) => SalaryService.typeToggle(p, c) },
  'payroll.transactions': { section: 'salary', fn: (p) => SalaryService.transactionsForEmployee(p) },
  'payroll.transactionAdd': { section: 'salary', fn: (p, c) => SalaryService.add(p, c) },
  'payroll.advances': { section: 'salary', fn: (p) => ({ advances: SalaryService.advances(U.str(p.code) || null) }) },
  'payroll.advanceCreate': { section: 'salary', fn: (p, c) => SalaryService.advanceCreate(p, c) },
  'payroll.advanceCancel': { section: 'salary', fn: (p, c) => SalaryService.advanceCancel(p, c) },
  'payroll.advancesReport': { section: 'salary', fn: (p) => SalaryService.advancesReport(p) },
  // خصم الصيف
  'summer.departments': { section: 'payroll', fn: () => SalaryService.summerDepartmentsGet() },
  'summer.departmentSet': { section: 'payroll', fn: (p, c) => SalaryService.summerDepartmentSet(p, c) },
  'summer.calculate': { section: 'payroll', fn: (p, c) => SalaryService.summerCalculate(p, c) },
  'summer.refund': { section: 'payroll', fn: (p, c) => SalaryService.summerRefund(p, c) },
  'summer.refundByType': { section: 'payroll', fn: (p, c) => SalaryService.summerRefundByType(p, c) },
  'summer.logReport': { section: 'payroll', fn: (p) => SalaryService.summerLogReport(p) },
  // رسائل واتساب
  'whatsapp.preview': { section: 'whatsapp_send', fn: (p) => WhatsAppService.preview(p) },
  'whatsapp.logSent': { section: 'whatsapp_send', fn: (p, c) => WhatsAppService.logSent(p, c) },
  // التنبيهات والإشعارات
  'notifications.list': { section: 'notifications_manage', fn: (p) => NotificationService.list(p) },
  'notifications.create': { section: 'notifications_manage', fn: (p, c) => NotificationService.create(p, c) },
  'notifications.update': { section: 'notifications_manage', fn: (p, c) => NotificationService.update(p, c) },
  'notifications.cancel': { section: 'notifications_manage', fn: (p, c) => NotificationService.cancel(p, c) },
  'notifications.delete': { section: 'notifications_manage', fn: (p, c) => NotificationService.remove(p, c) },
  // نسخ احتياطي
  'backup.list': { section: 'backup_view', fn: () => BackupService.list() },
  'backup.create': { section: 'backup_create', fn: (p, c) => BackupService.create(p, c) },
  'backup.download': { section: 'backup_view', fn: (p) => BackupService.download(p) },
  'backup.restore': { section: 'backup_restore', fn: (p, c) => BackupService.restore(p, c) },
  'system.resetGroups': { section: 'settings', fn: (p, c) => SystemService.resetGroups(c) },
  'system.resetData': { section: 'settings', fn: (p, c) => SystemService.resetData(p, c) },
  'system.pdf': { fn: (p) => SystemService.pdf(p) },   // تحويل جدول التصدير الحالي لـPDF بإمكانيات Apps Script نفسها (بدون أي مكتبة جديدة)
  'system.schema': { section: 'settings', fn: () => SystemService.schemaInfo() },
  'system.verify': { section: 'settings', fn: (p, c) => verifyEnvironment_(c) },
  'system.dbInfo': { section: 'settings', fn: () => ({ info: getV2DatabaseInfo(), binding: diagnoseV2DatabaseBinding(), canonical: verifyCanonicalV2Database() }) },
  'system.perf': { section: 'settings', fn: () => SystemService.perf() }
};

function dispatch(req) {
  const t0 = Date.now(); DA.reset();
  const meta = () => ({ ms: Date.now() - t0, reads: DA.stats.reads, writes: DA.stats.writes, extReads: DA.stats.extReads, extWrites: DA.stats.extWrites });
  try {
    req = req || {}; const action = U.str(req.action);
    if (action === 'employeeBridge') return { ok: true, data: BridgeService.handle(req), meta: meta() };
    const route = ROUTES[action]; if (!route) fail(ERR.NOT_FOUND, 'Unknown action: ' + action);
    const ctx = { action };
    if (route.auth !== false) { ctx.session = AuthService.resolve(req.token); if (!AuthService.can(ctx.session, route.section)) fail(ERR.PERMISSION_DENIED, 'مالكش صلاحية الوصول لقسم "' + (SECTION_LABELS_[route.section] || route.section) + '".'); }
    return { ok: true, data: route.fn(req.payload || {}, ctx), meta: meta() };
  } catch (e) {
    const known = e instanceof AppError; if (!known) console.error('[GHIS] ' + (req && req.action) + ': ' + (e && e.stack || e));
    return { ok: false, error: { code: known ? e.code : ERR.SERVER_ERROR, message: known ? e.message : 'خطأ غير متوقع في الخادم', details: known ? e.details : null }, meta: meta() };
  }
}

/* ===================================================================== */
/* 8. WEB ENTRY POINTS + LOGIN ASSET (frozen)                            */
/* ===================================================================== */

function api(request) { return dispatch(request); }

const LOGIN_ASSET_FILES = ['login_bg', 'login-bg', 'loginbg', 'login_bg.html'];
const LOGIN_BG_PLACEHOLDER = 'src="login-bg.jpg"';
function loginAssetInfo_() {
  const info = { file: null, rawLength: 0, head: '', tail: '', extractedLength: 0, method: null, error: null, dataUri: '' }; let raw = '';
  for (let i = 0; i < LOGIN_ASSET_FILES.length; i++) { try { raw = HtmlService.createHtmlOutputFromFile(LOGIN_ASSET_FILES[i]).getContent(); info.file = LOGIN_ASSET_FILES[i]; info.error = null; break; } catch (e) { info.error = String(e && e.message || e); } }
  if (!raw) return info; info.rawLength = raw.length; info.head = raw.slice(0, 80); info.tail = raw.slice(-40);
  let m = raw.match(/data:image\/[a-z]+;base64,[A-Za-z0-9+\/=\s]+/); if (m) { info.dataUri = m[0].replace(/\s+/g, ''); info.method = 'data-uri'; }
  else { const b = raw.match(/\/9j\/[A-Za-z0-9+\/=\s]{1000,}/); if (b) { info.dataUri = 'data:image/jpeg;base64,' + b[0].replace(/\s+/g, ''); info.method = 'bare-base64'; } }
  info.extractedLength = info.dataUri.length; return info;
}
function doGet(e) {
  let html = HtmlService.createHtmlOutputFromFile('index').getContent(); const info = loginAssetInfo_(); const placeholderCount = html.split(LOGIN_BG_PLACEHOLDER).length - 1;
  if (e && e.parameter && e.parameter.diag === '1') return ContentService.createTextOutput(JSON.stringify({ version: CFG.VERSION, serverTime: U.now(), indexLength: html.length, placeholderCount, asset: { file: info.file, error: info.error, rawLength: info.rawLength, head: info.head, tail: info.tail, method: info.method, extractedLength: info.extractedLength, injected: !!(info.dataUri && placeholderCount) } }, null, 2)).setMimeType(ContentService.MimeType.TEXT);
  const status = info.dataUri ? 'injected:' + info.extractedLength + ':' + info.method : 'missing:' + (info.error || 'no-image-in-' + (info.file || 'file'));
  if (info.dataUri) html = html.split(LOGIN_BG_PLACEHOLDER).join('src="' + info.dataUri + '"');
  html = html.replace('<img id="login-bg"', '<img id="login-bg" data-asset="' + status + '"');
  return HtmlService.createHtmlOutput(html).setTitle('GHIS V2').addMetaTag('viewport', 'width=device-width, initial-scale=1').setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}
/** External hosting / attendance bridge transport: POST JSON {action, token, payload} (employeeBridge: {action:'employeeBridge', secret, code, op, payload}) */
function doPost(e) { let req = null; try { req = JSON.parse((e && e.postData && e.postData.contents) || '{}'); } catch (err) { req = { action: '' }; } return ContentService.createTextOutput(JSON.stringify(dispatch(req))).setMimeType(ContentService.MimeType.JSON); }

/* ===================================================================== */
/* 9. ADMIN / MAINTENANCE (run manually from the editor)                 */
/* ===================================================================== */

/* ===================================================================== */
/* DATABASE BINDING TOOLKIT (run manually from the Apps Script editor)    */
/* ===================================================================== */

/** Read-only: what the app would open right now, and whether it really holds the Phase-2 schema. */
function diagnoseV2DatabaseBinding() {
  const expected = Object.keys(SCHEMA).map(k => SCHEMA[k].sheet);
  const out = { scriptPropertyId: null, spreadsheetId: null, spreadsheetName: null, spreadsheetUrl: null, sheetCount: 0, sheetNames: [], expectedSheetCount: expected.length, expectedSheetsPresent: 0, missingSheets: expected.slice(), unexpectedSheets: [], rowCounts: {}, dataRowTotal: 0, bindingStatus: 'MISSING_PROPERTY', note: '', version: CFG.VERSION };
  const id = PropertiesService.getScriptProperties().getProperty(CFG.PROP_SSID);
  out.scriptPropertyId = id || null;
  if (!id) { out.note = 'GHIS_V2_SPREADSHEET_ID غير مضبوط — التطبيق مش هيفتح أي قاعدة. استخدم bindV2Database("<id>") أو createV2DatabaseExplicitly().'; Logger.log(JSON.stringify(out, null, 2)); return out; }
  let s;
  try { s = SpreadsheetApp.openById(id); } catch (e) { out.bindingStatus = 'ERROR'; out.note = 'تعذّر فتح الـSpreadsheet بالـid ده: ' + (e && e.message || e); Logger.log(JSON.stringify(out, null, 2)); return out; }
  out.spreadsheetId = s.getId(); out.spreadsheetName = s.getName(); out.spreadsheetUrl = s.getUrl();
  const sheets = s.getSheets(); out.sheetNames = sheets.map(sh => sh.getName()); out.sheetCount = sheets.length;
  out.missingSheets = expected.filter(n => out.sheetNames.indexOf(n) === -1);
  out.expectedSheetsPresent = expected.length - out.missingSheets.length;
  out.unexpectedSheets = out.sheetNames.filter(n => expected.indexOf(n) === -1);
  sheets.forEach(sh => { const n = sh.getName(); if (expected.indexOf(n) !== -1) { const rows = Math.max(0, sh.getLastRow() - 1); out.rowCounts[n] = rows; out.dataRowTotal += rows; } });
  if (out.expectedSheetsPresent === 0) { out.bindingStatus = 'WRONG_DATABASE'; out.note = 'الـSpreadsheet ده مافيهوش أي شيت من سكيمة GHIS V2 — غالبًا قاعدة تانية أو ملف فاضي.'; }
  else if (out.missingSheets.length) { out.bindingStatus = 'INCOMPLETE_DATABASE'; out.note = 'ناقص ' + out.missingSheets.length + ' شيت: ' + out.missingSheets.join(' · ') + ' — شغّل initializeSystem() لإضافة الناقص فقط (مش بيمسح أي بيانات).'; }
  else { out.bindingStatus = 'CORRECT'; out.note = 'كل شيتات Phase 2 موجودة في الـSpreadsheet المربوط. عدد صفوف البيانات: ' + out.dataRowTotal + '.'; }
  Logger.log(JSON.stringify(out, null, 2)); return out;
}

/** Read-only: the identity of the bound database (no secrets). */
function getV2DatabaseInfo() {
  const id = PropertiesService.getScriptProperties().getProperty(CFG.PROP_SSID);
  if (!id) { const r = { spreadsheetId: null, spreadsheetName: null, spreadsheetUrl: null, sheetCount: 0, sheetNames: [], version: CFG.VERSION, note: 'GHIS_V2_SPREADSHEET_ID غير مضبوط.' }; Logger.log(JSON.stringify(r, null, 2)); return r; }
  const s = SpreadsheetApp.openById(id); const sheets = s.getSheets();
  const r = { spreadsheetId: s.getId(), spreadsheetName: s.getName(), spreadsheetUrl: s.getUrl(), sheetCount: sheets.length, sheetNames: sheets.map(sh => sh.getName()), version: CFG.VERSION };
  Logger.log(JSON.stringify(r, null, 2)); return r;
}

/**
 * Re-binds the app to an EXISTING Spreadsheet. Changes ONLY the Script Property.
 * Never creates, renames, deletes or migrates anything. Refuses a Spreadsheet that holds no GHIS V2 sheet
 * unless allowEmpty is true (for provisioning a deliberately empty file you own).
 */
function bindV2Database(spreadsheetId, allowEmpty) {
  const id = String(spreadsheetId || '').trim();
  if (!id) throw new Error('مرّر spreadsheetId. مثال: bindV2Database("1AbC...")');
  const s = SpreadsheetApp.openById(id);                       // throws if the id is wrong / no access
  const expected = Object.keys(SCHEMA).map(k => SCHEMA[k].sheet);
  const names = s.getSheets().map(sh => sh.getName());
  const present = expected.filter(n => names.indexOf(n) !== -1);
  if (!present.length && !allowEmpty) throw new Error('الـSpreadsheet ده مافيهوش أي شيت من سكيمة GHIS V2 (' + s.getName() + '). لو متأكد إنه الملف الصح وفاضي، استخدم bindV2Database(id, true).');
  const previous = PropertiesService.getScriptProperties().getProperty(CFG.PROP_SSID) || null;
  PropertiesService.getScriptProperties().setProperty(CFG.PROP_SSID, s.getId());
  DA.unbind();
  const r = { bound: s.getId(), name: s.getName(), url: s.getUrl(), previousBoundId: previous, expectedSheetsPresent: present.length, expectedSheetCount: expected.length, missingSheets: expected.filter(n => names.indexOf(n) === -1), note: 'اتغيّرت خاصية GHIS_V2_SPREADSHEET_ID بس — مفيش أي ملف أو شيت أو بيانات اتمسحت أو اتغيّرت.' };
  Logger.log(JSON.stringify(r, null, 2)); return r;
}

/**
 * The ONLY path that may create a new database Spreadsheet — and only when called deliberately.
 * Refuses to run while a binding already exists, unless force is true (the old Spreadsheet is never deleted).
 */
function createV2DatabaseExplicitly(force) {
  const props = PropertiesService.getScriptProperties(); const existing = props.getProperty(CFG.PROP_SSID);
  if (existing && !force) throw new Error('فيه قاعدة مربوطة بالفعل (' + existing + '). شغّل diagnoseV2DatabaseBinding() الأول. لو متأكد إنك عايز قاعدة جديدة تمامًا: createV2DatabaseExplicitly(true) — القاعدة القديمة مش هتتمسح.');
  const s = SpreadsheetApp.create(CFG.SS_NAME);
  props.setProperty(CFG.PROP_SSID, s.getId()); DA.unbind();
  const r = initializeSystem();
  r.created = true; r.previousBoundId = existing || null; r.note = 'قاعدة جديدة اتعملت واتربطت. القاعدة القديمة (لو كانت موجودة) اتسابت زي ما هي.';
  Logger.log(JSON.stringify(r, null, 2)); return r;
}

/**
 * initializeSystem(): idempotent, NON-CREATING. Works only on the already-bound Spreadsheet:
 * adds any missing sheet (with its headers/seed) and the admin user if absent. Never creates a Spreadsheet,
 * never deletes or rewrites existing rows, never opens the OLD GHIS or the Attendance spreadsheet.
 */
function initializeSystem() {
  const report = DA.ensureSchema(); DA.reset();
  if (!DA.readOne('Users', { username: 'admin' })) { const password = 'Admin@' + Math.floor(1000 + Math.random() * 9000); const salt = U.salt(); DA.insert('Users', { username: 'admin', passwordHash: U.hash(password, salt), salt, role: 'أدمن', active: YES, createdAt: U.now(), lastLoginAt: '' }); report.adminPassword = password; }
  ['roles:all', 'nameLists', 'exemptDepts', 'salaryTxTypes', 'docTypes', 'settings:' + SettingsService.KEY_AUTO_CODE].forEach(k => Cache.remove(k));
  if (!DA.readOne('SalaryTxTypes', { name: 'الراتب الأساسي' })) DA.insert('SalaryTxTypes', { name: 'الراتب الأساسي', kind: 'إضافة', active: YES, category: 'basic' });
  report.attendanceConfigured = !!AttendanceService.attId(); report.bridgeConfigured = !!U.str(PropertiesService.getScriptProperties().getProperty(CFG.PROP_BRIDGE_SECRET));
  report.sheetsCreated = report.sheets.filter(x => x.created).map(x => x.sheet);
  Logger.log(JSON.stringify(report, null, 2)); return report;
}

/* ===================================================================== */
/* ONE-TIME CANONICAL ALIGNMENT: copy the 25 Phase-2 sheets from the      */
/* source database into the container Spreadsheet, then re-bind to it.    */
/* Run manually: migratePhase2DatabaseToContainer()                       */
/* ===================================================================== */

const MIGRATION = {
  SOURCE_ID: '1G46LASvywYyXmxnubSN0Bzge595KB2jI1HXavZflr-s',   // current Phase-2 database (also holds the 10 Phase-0 English sheets — NOT copied)
  TARGET_ID: '1Wgp6w3DZz2bhJsYr_RV2GnHdyXHROqHQKoQtuzVgQuE'    // container Spreadsheet bound to this Apps Script project
};
/** The 25 canonical sheet names come from SCHEMA itself — no hand-typed list can drift from the code. */
function phase2SheetNames_() { return Object.keys(SCHEMA).map(k => SCHEMA[k].sheet); }

/**
 * Read-only: where the canonical database stands right now.
 * Never writes, never binds, safe to run at any time.
 */
function verifyCanonicalV2Database() {
  const required = phase2SheetNames_();
  const out = { containerSpreadsheetId: MIGRATION.TARGET_ID, configuredDatabaseId: PropertiesService.getScriptProperties().getProperty(CFG.PROP_SSID) || null, databaseName: null, databaseUrl: null, sheetCount: 0, sheetNames: [], requiredSheetCount: required.length, requiredSheetsPresent: 0, missingSheets: required.slice(), extraSheets: [], rowCounts: {}, sourceSpreadsheetId: MIGRATION.SOURCE_ID, targetSpreadsheetId: MIGRATION.TARGET_ID, boundToContainer: false, bindingStatus: 'MISSING_PROPERTY', note: '', version: CFG.VERSION };
  if (!out.configuredDatabaseId) { out.note = 'GHIS_V2_SPREADSHEET_ID غير مضبوط.'; Logger.log(JSON.stringify(out, null, 2)); return out; }
  out.boundToContainer = out.configuredDatabaseId === MIGRATION.TARGET_ID;
  let s; try { s = SpreadsheetApp.openById(out.configuredDatabaseId); } catch (e) { out.bindingStatus = 'ERROR'; out.note = String(e && e.message || e); Logger.log(JSON.stringify(out, null, 2)); return out; }
  out.databaseName = s.getName(); out.databaseUrl = s.getUrl();
  const sheets = s.getSheets(); out.sheetNames = sheets.map(sh => sh.getName()); out.sheetCount = sheets.length;
  out.missingSheets = required.filter(n => out.sheetNames.indexOf(n) === -1); out.requiredSheetsPresent = required.length - out.missingSheets.length;
  out.extraSheets = out.sheetNames.filter(n => required.indexOf(n) === -1);
  sheets.forEach(sh => { if (required.indexOf(sh.getName()) !== -1) out.rowCounts[sh.getName()] = Math.max(0, sh.getLastRow() - 1); });
  out.bindingStatus = out.requiredSheetsPresent === 0 ? 'WRONG_DATABASE' : (out.missingSheets.length ? 'INCOMPLETE_DATABASE' : 'CORRECT');
  out.note = out.bindingStatus === 'CORRECT' ? (out.boundToContainer ? 'القاعدة الكانونية = ملف الحاوية، وكل الـ25 شيت موجودين.' : 'كل الشيتات موجودة، لكن المربوط مش ملف الحاوية — شغّل migratePhase2DatabaseToContainer().') : 'ناقص: ' + out.missingSheets.join(' · ');
  Logger.log(JSON.stringify(out, null, 2)); return out;
}

/**
 * One-time, explicit, verify-then-bind migration.
 *   copySheetToTarget → copies each of the 25 sheets WITH its data, formats, validation, formulas and frozen rows
 *   (SpreadsheetApp.copyTo preserves them), then renames the copy to the exact original name.
 * Nothing is deleted in the SOURCE. The Script Property is changed ONLY after every check passes.
 * Second run returns ALREADY_MIGRATED without copying anything.
 * dryRun: true → performs every check and reports, but copies nothing and binds nothing.
 */
function migratePhase2DatabaseToContainer(dryRun) {
  const required = phase2SheetNames_();
  const report = { status: 'STARTED', dryRun: !!dryRun, sourceSpreadsheetId: MIGRATION.SOURCE_ID, targetSpreadsheetId: MIGRATION.TARGET_ID, requiredSheetCount: required.length, sourceSheetsFound: [], sourceMissing: [], targetBefore: [], copied: [], skipped: [], sheet1: 'not-present', verification: null, configuredDatabaseIdBefore: PropertiesService.getScriptProperties().getProperty(CFG.PROP_SSID) || null, configuredDatabaseIdAfter: null, errors: [] };
  const done = (status, note) => { report.status = status; if (note) report.note = note; Logger.log(JSON.stringify(report, null, 2)); return report; };
  let src, tgt;
  try { src = SpreadsheetApp.openById(MIGRATION.SOURCE_ID); } catch (e) { report.errors.push('SOURCE: ' + (e && e.message || e)); return done('FAILED_OPEN_SOURCE'); }
  try { tgt = SpreadsheetApp.openById(MIGRATION.TARGET_ID); } catch (e) { report.errors.push('TARGET: ' + (e && e.message || e)); return done('FAILED_OPEN_TARGET'); }
  report.sourceName = src.getName(); report.targetName = tgt.getName();

  const srcNames = src.getSheets().map(sh => sh.getName()); const tgtNames = tgt.getSheets().map(sh => sh.getName());
  report.targetBefore = tgtNames.slice();
  report.sourceSheetsFound = required.filter(n => srcNames.indexOf(n) !== -1); report.sourceMissing = required.filter(n => srcNames.indexOf(n) === -1);

  // idempotency: target already complete → nothing to copy
  const alreadyPresent = required.filter(n => tgtNames.indexOf(n) !== -1);
  if (alreadyPresent.length === required.length) {
    report.skipped = alreadyPresent; report.verification = verifyTargetAgainstSource_(src, tgt, required);
    if (!dryRun && report.verification.ok) { PropertiesService.getScriptProperties().setProperty(CFG.PROP_SSID, MIGRATION.TARGET_ID); DA.unbind(); report.configuredDatabaseIdAfter = MIGRATION.TARGET_ID; }
    return done('ALREADY_MIGRATED', 'كل الـ25 شيت موجودين في ملف الحاوية — مفيش أي نسخ اتعمل تاني.');
  }
  if (report.sourceMissing.length) { report.errors.push('الشيتات دي مش موجودة في ملف المصدر: ' + report.sourceMissing.join(' · ')); return done('FAILED_SOURCE_INCOMPLETE'); }
  if (alreadyPresent.length) { report.errors.push('ملف الحاوية فيه بالفعل شيتات بنفس الأسماء (نسخ جزئي سابق؟): ' + alreadyPresent.join(' · ') + ' — امسحها يدويًا بعد التأكد إنها فاضية، أو سيبها وشغّل الفحص.'); return done('FAILED_TARGET_CONFLICT'); }

  // Sheet1 check (never deleted while it holds anything)
  const sheet1 = tgt.getSheetByName('Sheet1') || tgt.getSheetByName('Sheet 1') || tgt.getSheetByName('ورقة1');
  if (sheet1) { const empty = sheet1.getLastRow() === 0 && sheet1.getLastColumn() === 0; report.sheet1 = empty ? 'empty' : 'HAS_DATA'; if (!empty) report.errors.push('Sheet1 فيه بيانات — مش هيتمسح. راجعه بنفسك.'); }
  if (dryRun) { report.verification = { ok: false, note: 'dryRun — مفيش نسخ اتعمل.' }; return done('DRY_RUN_OK', 'كل الفحوصات عدّت. شغّل migratePhase2DatabaseToContainer() من غير dryRun عشان النسخ الفعلي.'); }

  // copy (data + formats + validation + formulas + frozen rows are preserved by copyTo)
  required.forEach(name => { try { const sh = src.getSheetByName(name); const copy = sh.copyTo(tgt); copy.setName(name); report.copied.push(name); } catch (e) { report.errors.push('نسخ "' + name + '": ' + (e && e.message || e)); } });
  if (report.errors.length) return done('FAILED_DURING_COPY', 'النسخ وقف بخطأ — الخاصية ماتغيّرتش، وملف المصدر زي ما هو.');

  // verify target against source BEFORE binding
  const verification = verifyTargetAgainstSource_(src, tgt, required); report.verification = verification;
  if (!verification.ok) { report.errors.push('التحقق فشل بعد النسخ — الخاصية ماتغيّرتش.'); return done('FAILED_VERIFICATION'); }

  if (sheet1 && report.sheet1 === 'empty') { try { tgt.deleteSheet(sheet1); report.sheet1 = 'removed'; } catch (e) { report.sheet1 = 'kept (' + (e && e.message || e) + ')'; } }

  PropertiesService.getScriptProperties().setProperty(CFG.PROP_SSID, MIGRATION.TARGET_ID); DA.unbind();
  report.configuredDatabaseIdAfter = MIGRATION.TARGET_ID;
  report.finalCheck = verifyCanonicalV2Database();
  return done('MIGRATED', 'اتنسخ ' + report.copied.length + ' شيت واتربطت القاعدة الكانونية بملف الحاوية. ملف المصدر ما اتغيّرش ولا اتمسح منه حاجة.');
}

/** Compares every required sheet in the target against the source: headers, column count and data-row count. */
function verifyTargetAgainstSource_(src, tgt, required) {
  const res = { ok: true, sheets: [], mismatches: [] };
  required.forEach(name => {
    const a = src.getSheetByName(name), b = tgt.getSheetByName(name);
    if (!b) { res.ok = false; res.mismatches.push(name + ': مش موجود في الهدف'); return; }
    const aCols = a.getLastColumn(), bCols = b.getLastColumn(), aRows = Math.max(0, a.getLastRow() - 1), bRows = Math.max(0, b.getLastRow() - 1);
    const aHead = aCols ? a.getRange(1, 1, 1, aCols).getValues()[0].join('|') : '', bHead = bCols ? b.getRange(1, 1, 1, bCols).getValues()[0].join('|') : '';
    const row = { sheet: name, sourceRows: aRows, targetRows: bRows, sourceCols: aCols, targetCols: bCols, headersMatch: aHead === bHead };
    if (aHead !== bHead) { res.ok = false; res.mismatches.push(name + ': العناوين مختلفة'); }
    if (aRows !== bRows) { res.ok = false; res.mismatches.push(name + ': عدد الصفوف مختلف (' + aRows + ' → ' + bRows + ')'); }
    res.sheets.push(row);
  });
  return res;
}

/** Optional time-driven maintenance: removes expired session rows. */
function cleanupSessions() { const now = Date.now(); return DA.remove('Sessions', r => !r.expiresAt || Date.parse(r.expiresAt) < now); }

/* ===================================================================== */
/* 10. REAL-ENVIRONMENT VERIFICATION (read-only; run verifyEnvironment() from the editor or Settings → النظام) */
/* ===================================================================== */
function verifyEnvironment_(ctx) {
  const items = []; const add = (name, status, detail) => items.push({ name, status, detail: detail || '' });
  const props = PropertiesService.getScriptProperties();
  const ssid = props.getProperty(CFG.PROP_SSID); add('GHIS_V2_SPREADSHEET_ID', ssid ? 'PASS' : 'FAIL', ssid || 'غير مضبوط — شغّل bindV2Database("<id>") أو createV2DatabaseExplicitly()');
  if (ssid) { let diag = null; try { diag = diagnoseV2DatabaseBinding(); } catch (e) { add('database binding', 'FAIL', String(e.message)); }
    if (diag) add('database binding: ' + diag.bindingStatus, diag.bindingStatus === 'CORRECT' ? 'PASS' : (diag.bindingStatus === 'INCOMPLETE_DATABASE' ? 'BLOCKED' : 'FAIL'), (diag.spreadsheetName || '') + ' — ' + diag.expectedSheetsPresent + '/' + diag.expectedSheetCount + ' شيت · ' + diag.dataRowTotal + ' صف بيانات' + (diag.missingSheets.length ? ' · ناقص: ' + diag.missingSheets.join('، ') : ''));
    Object.keys(SCHEMA).forEach(n => { try { const t = DA.readMany(n); add('sheet: ' + SCHEMA[n].sheet, 'PASS', t.length + ' rows'); } catch (e) { add('sheet: ' + SCHEMA[n].sheet, 'FAIL', String(e.message)); } }); }
  try { Cache.put('verify:ping', { t: Date.now() }, 60); add('CacheService', Cache.get('verify:ping') ? 'PASS' : 'FAIL'); } catch (e) { add('CacheService', 'FAIL', String(e.message)); }
  try { FileStore.ping(); add('DriveApp (via FileStore)', 'PASS'); } catch (e) { add('DriveApp (via FileStore)', 'FAIL', String(e.message)); }
  const attId = AttendanceService.attId(); if (!attId) add('ATTENDANCE_SPREADSHEET_ID', 'BLOCKED', 'Script Property not set — fingerprint sync / password reset / retry are blocked'); else { try { const rows = DA.extReadColumns(attId, CFG.ATT_EMPLOYEES_SHEET, 2); add('Attendance file: ' + CFG.ATT_EMPLOYEES_SHEET, 'PASS', rows.length + ' rows (read-only)'); } catch (e) { add('Attendance file: ' + CFG.ATT_EMPLOYEES_SHEET, 'FAIL', String(e.message)); } try { DA.extReadColumns(attId, CFG.ATT_PASSWORDS_SHEET, 1); add('Attendance file: ' + CFG.ATT_PASSWORDS_SHEET, 'PASS', 'read-only'); } catch (e) { add('Attendance file: ' + CFG.ATT_PASSWORDS_SHEET, 'FAIL', String(e.message)); } }
  add('EMPLOYEE_BRIDGE_SECRET', U.str(props.getProperty(CFG.PROP_BRIDGE_SECRET)) ? 'PASS' : 'BLOCKED', 'employeeBridge requires the shared secret in Script Properties (and the same value in the Attendance app)');
  try { const li = loginAssetInfo_(); add('login_bg.html asset', li.dataUri ? 'PASS' : 'FAIL', li.dataUri ? li.extractedLength + ' chars (' + li.method + ')' : (li.error || 'no image found')); } catch (e) { add('login_bg.html asset', 'FAIL', String(e.message)); }
  add('Session / permissions (this call)', 'PASS', ctx && ctx.session ? ctx.session.username + ' / ' + ctx.session.role + (ctx.session.isAdmin ? ' (أدمن)' : '') : '');
  return { version: CFG.VERSION, serverTime: U.now(), items, summary: { pass: items.filter(i => i.status === 'PASS').length, fail: items.filter(i => i.status === 'FAIL').length, blocked: items.filter(i => i.status === 'BLOCKED').length } };
}
function verifyEnvironment() { const r = verifyEnvironment_({ session: { username: 'editor', role: '-', isAdmin: true } }); Logger.log(JSON.stringify(r, null, 2)); return r; }

/** تشغيل يدوي مرة واحدة: trigger يومي للمزامنة (الساعة 3 صباحًا بتوقيت المشروع). قراءة/كتابة محلية فقط. */
function installHolidaySyncTrigger() {
  ScriptApp.getProjectTriggers().forEach(t => { if (t.getHandlerFunction() === 'dailyHolidaySync') ScriptApp.deleteTrigger(t); });
  ScriptApp.newTrigger('dailyHolidaySync').timeBased().atHour(3).everyDays(1).create();
  Logger.log('dailyHolidaySync trigger installed (03:00 daily)'); return true;
}
/** الهاندلر اليومي — أي فشل بيتسجّل ومابيوقفش أي حاجة. */
function dailyHolidaySync() { try { return HolidayService.syncOfficialHolidays_({}); } catch (e) { Logger.log('holiday sync failed: ' + (e && e.message || e)); return null; } }
/** مزامنة يدوية من المحرر. */
function syncOfficialHolidays() { return HolidayService.syncOfficialHolidays_({}); }