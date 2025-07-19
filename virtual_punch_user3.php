<?php
// الاتصال بقاعدة البيانات
$pdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "12341234");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// جلب الموظفين النشطين مع emp_code واسم الموظف
$employees = $pdo->query("SELECT id, name, emp_code FROM employees WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// عند إدخال بصمة افتراضية من النموذج
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_virtual_punch'])) {
    $emp_id = intval($_POST['employee_id']);
    // جلب emp_code من قاعدة البيانات
    $emp_stmt = $pdo->prepare("SELECT emp_code, name FROM employees WHERE id = ?");
    $emp_stmt->execute([$emp_id]);
    $emp = $emp_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$emp) {
        $error = "الموظف غير موجود!";
    } else {
        $emp_code = $emp['emp_code'];
        $punch_time = $_POST['punch_time']; // مثال: 2025-06-18T08:15
        $punch_type = $_POST['punch_type']; // 'حضور' أو 'انصراف'
        $verify_type = 99; // نوع تحقق افتراضي
        $terminal_sn = "VIRTUAL_SN";
        $terminal_name = "بصمة افتراضية";
        $work_date = substr($punch_time, 0, 10);

        // تحويل وقت النموذج إلى تنسيق قاعدة البيانات الصحيح
        $punch_time_db = str_replace('T', ' ', $punch_time) . ':00';

        $stmt = $pdo->prepare("INSERT INTO attendance_records 
            (emp_code, punch_time, punch_type, verify_type, terminal_sn, terminal_name, work_date) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$emp_code, $punch_time_db, $punch_type, $verify_type, $terminal_sn, $terminal_name, $work_date]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدخال بصمة افتراضية</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', Tahoma, Arial, sans-serif; background: #f6f7fa;}
        .main-title { color: #007bff; font-weight: bold;}
        .form-section { background: #fff; border-radius: 15px; padding:22px 15px; box-shadow:0 4px 18px #d0dbe850; margin-bottom: 22px;}
    </style>
</head>
<body>
<div class="container py-3">
    <h2 class="main-title mb-4 text-center">إدخال بصمة افتراضية (حضور/انصراف)</h2>
    <?php if (isset($success)): ?>
        <div class="alert alert-success text-center">تمت إضافة البصمة الافتراضية بنجاح!</div>
    <?php elseif (isset($error)): ?>
        <div class="alert alert-danger text-center"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div class="form-section mb-4">
        <form method="post" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">الموظف</label>
                <select name="employee_id" class="form-select" required>
                    <option value="">اختر الموظف...</option>
                    <?php foreach($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>">
                            <?= htmlspecialchars($emp['name']) ?> (كود: <?= htmlspecialchars($emp['emp_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">وقت البصمة</label>
                <input type="datetime-local" name="punch_time" class="form-control" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">نوع البصمة</label>
                <select name="punch_type" class="form-select" required>
                    <option value="حضور">حضور</option>
                    <option value="انصراف">انصراف</option>
                </select>
            </div>
            <div class="col-md-3 d-grid">
                <label class="form-label">&nbsp;</label>
                <button name="add_virtual_punch" type="submit" class="btn btn-primary">إدخال البصمة</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>