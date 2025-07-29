<?php
// فحص صحة النظام وتشخيص المشاكل
$pdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "12341234");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$checks = [];
$warnings = [];
$errors = [];

// 1. فحص الجداول الأساسية
$required_tables = [
    'employees', 'shifts', 'shift_days', 'shift_assignments', 
    'attendance_records', 'attendance_daily_details', 
    'leaves', 'exit_permissions', 'bonuses', 'overtime', 
    'salaries', 'leave_balances', 'leave_types'
];

foreach ($required_tables as $table) {
    try {
        $result = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $result->fetchColumn();
        $checks[] = "✅ جدول $table موجود ($count سجل)";
    } catch (Exception $e) {
        $errors[] = "❌ جدول $table غير موجود أو به مشكلة";
    }
}

// 2. فحص الموظفين بدون أكواد
$emp_no_code = $pdo->query("SELECT COUNT(*) FROM employees WHERE emp_code IS NULL OR emp_code = ''")->fetchColumn();
if ($emp_no_code > 0) {
    $warnings[] = "⚠️ يوجد $emp_no_code موظف بدون رقم وظيفي";
}

// 3. فحص الموظفين بدون شفتات
$emp_no_shifts = $pdo->query("
    SELECT COUNT(DISTINCT e.id) 
    FROM employees e 
    LEFT JOIN shift_assignments sa ON e.id = sa.employee_id 
    WHERE e.is_active = 1 AND sa.employee_id IS NULL
")->fetchColumn();
if ($emp_no_shifts > 0) {
    $warnings[] = "⚠️ يوجد $emp_no_shifts موظف نشط بدون شفتات مخصصة";
}

// 4. فحص سجلات الحضور اليتيمة (بدون موظفين)
$orphan_records = $pdo->query("
    SELECT COUNT(*) 
    FROM attendance_records ar 
    LEFT JOIN employees e ON ar.emp_code = e.emp_code 
    WHERE e.emp_code IS NULL
")->fetchColumn();
if ($orphan_records > 0) {
    $warnings[] = "⚠️ يوجد $orphan_records سجل حضور لموظفين غير موجودين";
}

// 5. فحص الشفتات بدون أيام
$shifts_no_days = $pdo->query("
    SELECT COUNT(*) 
    FROM shifts s 
    LEFT JOIN shift_days sd ON s.id = sd.shift_id 
    WHERE sd.shift_id IS NULL
")->fetchColumn();
if ($shifts_no_days > 0) {
    $warnings[] = "⚠️ يوجد $shifts_no_days شفت بدون أيام عمل محددة";
}

// 6. فحص أداء قاعدة البيانات
$slow_queries = [];
$start_time = microtime(true);
$pdo->query("SELECT COUNT(*) FROM attendance_records WHERE work_date >= CURDATE() - INTERVAL 30 DAY");
$query_time = microtime(true) - $start_time;
if ($query_time > 1) {
    $warnings[] = "⚠️ استعلام الحضور بطيء (" . round($query_time, 2) . " ثانية) - قد تحتاج فهارس";
}

// 7. فحص مساحة قاعدة البيانات
try {
    $db_size = $pdo->query("
        SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS db_size_mb
        FROM information_schema.tables 
        WHERE table_schema = 'zk_attendance'
    ")->fetchColumn();
    $checks[] = "📊 حجم قاعدة البيانات: {$db_size} ميجابايت";
} catch (Exception $e) {
    $warnings[] = "⚠️ لا يمكن حساب حجم قاعدة البيانات";
}

// 8. فحص آخر مزامنة
try {
    $last_sync = $pdo->query("SELECT MAX(punch_time) FROM attendance_records")->fetchColumn();
    if ($last_sync) {
        $hours_ago = (time() - strtotime($last_sync)) / 3600;
        if ($hours_ago > 24) {
            $warnings[] = "⚠️ آخر مزامنة كانت منذ " . round($hours_ago) . " ساعة";
        } else {
            $checks[] = "✅ آخر مزامنة: " . date('Y-m-d H:i', strtotime($last_sync));
        }
    } else {
        $errors[] = "❌ لا توجد سجلات حضور - لم تتم المزامنة بعد";
    }
} catch (Exception $e) {
    $errors[] = "❌ خطأ في فحص آخر مزامنة";
}

// 9. فحص الأرصدة المفقودة
$missing_balances = $pdo->query("
    SELECT COUNT(DISTINCT e.emp_code) 
    FROM employees e 
    LEFT JOIN leave_balances lb ON e.emp_code = lb.emp_code AND lb.year = YEAR(CURDATE())
    WHERE e.is_active = 1 AND lb.emp_code IS NULL
")->fetchColumn();
if ($missing_balances > 0) {
    $warnings[] = "⚠️ يوجد $missing_balances موظف بدون أرصدة إجازات للسنة الحالية";
}

// 10. فحص التكوين
$config_issues = [];
if (!extension_loaded('pdo_mysql')) {
    $config_issues[] = "PDO MySQL غير مفعل";
}
if (!extension_loaded('mysqli')) {
    $config_issues[] = "MySQLi غير مفعل";
}
if (count($config_issues) > 0) {
    $errors[] = "❌ مشاكل في التكوين: " . implode(', ', $config_issues);
}

// حساب النتيجة الإجمالية
$total_checks = count($checks) + count($warnings) + count($errors);
$health_score = round((count($checks) / $total_checks) * 100);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>فحص صحة النظام</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', Tahoma, Arial, sans-serif; background: #f6f7fa;}
        .health-score {
            font-size: 3rem;
            font-weight: bold;
        }
        .score-excellent { color: #28a745; }
        .score-good { color: #ffc107; }
        .score-poor { color: #dc3545; }
        .check-item {
            padding: 8px 12px;
            margin: 4px 0;
            border-radius: 6px;
            border-right: 4px solid;
        }
        .check-success {
            background: #d4edda;
            border-right-color: #28a745;
            color: #155724;
        }
        .check-warning {
            background: #fff3cd;
            border-right-color: #ffc107;
            color: #856404;
        }
        .check-error {
            background: #f8d7da;
            border-right-color: #dc3545;
            color: #721c24;
        }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="row">
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">نتيجة صحة النظام</h5>
                    <div class="health-score <?= $health_score >= 80 ? 'score-excellent' : ($health_score >= 60 ? 'score-good' : 'score-poor') ?>">
                        <?= $health_score ?>%
                    </div>
                    <p class="card-text">
                        <?php if ($health_score >= 80): ?>
                            🎉 النظام يعمل بشكل ممتاز!
                        <?php elseif ($health_score >= 60): ?>
                            ⚠️ النظام يعمل مع بعض التحذيرات
                        <?php else: ?>
                            🚨 النظام يحتاج إلى إصلاحات عاجلة
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5>📋 تفاصيل الفحص</h5>
                </div>
                <div class="card-body">
                    <?php if (count($errors) > 0): ?>
                        <h6 class="text-danger">🚨 أخطاء حرجة:</h6>
                        <?php foreach ($errors as $error): ?>
                            <div class="check-item check-error"><?= $error ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <?php if (count($warnings) > 0): ?>
                        <h6 class="text-warning mt-3">⚠️ تحذيرات:</h6>
                        <?php foreach ($warnings as $warning): ?>
                            <div class="check-item check-warning"><?= $warning ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <h6 class="text-success mt-3">✅ فحوصات ناجحة:</h6>
                    <?php foreach ($checks as $check): ?>
                        <div class="check-item check-success"><?= $check ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5>🔧 إجراءات مقترحة</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>للصيانة الدورية:</h6>
                            <ul>
                                <li><a href="sync_attendance.php">🔄 تشغيل المزامنة</a></li>
                                <li><a href="admin/generate_attendance_daily.php">📊 توليد الحضور اليومي</a></li>
                                <li><a href="leave_balances_input.php">💰 تحديث أرصدة الإجازات</a></li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>للمراقبة:</h6>
                            <ul>
                                <li><a href="view_sync_errors.php">📋 عرض أخطاء المزامنة</a></li>
                                <li><a href="present_today.php">👥 مراجعة الحضور اليومي</a></li>
                                <li><a href="admin/salaries_monthly.php">💵 مراجعة الرواتب</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>