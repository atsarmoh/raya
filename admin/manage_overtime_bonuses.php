<?php
$pdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "12341234");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$employees = $pdo->query("SELECT id, name, emp_code FROM employees WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// استقبال اختيار الموظف والشهر
$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
$month = $_GET['month'] ?? date('Y-m');
$year = substr($month, 0, 4);
$mon  = substr($month, 5, 2);

$msg = '';
$alert_class = 'success';

// إضافة أو تعديل وقت إضافي
if (isset($_POST['save_overtime'])) {
    $ot_id = intval($_POST['ot_id'] ?? 0);
    $employee_id = intval($_POST['employee_id']);
    $overtime_date = $_POST['overtime_date'];
    $from_time = $_POST['from_time'];
    $to_time = $_POST['to_time'];
    $rate = floatval($_POST['rate']);
    // احتساب الساعات بدقة ولو عبر اليوم التالي
    $from_dt = strtotime($overtime_date . ' ' . $from_time);
    $to_dt   = strtotime($overtime_date . ' ' . $to_time);
    if ($to_dt < $from_dt) $to_dt += 86400;
    $hours = round(($to_dt - $from_dt) / 3600, 2);

    if ($ot_id > 0) {
        $pdo->prepare("UPDATE overtime SET overtime_date=?, from_time=?, to_time=?, hours=?, rate=? WHERE id=?")
            ->execute([$overtime_date, $from_time, $to_time, $hours, $rate, $ot_id]);
        $msg = "تم تعديل وقت إضافي بنجاح";
    } else {
        $pdo->prepare("INSERT INTO overtime (employee_id, overtime_date, from_time, to_time, hours, rate) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$employee_id, $overtime_date, $from_time, $to_time, $hours, $rate]);
        $msg = "تمت إضافة وقت إضافي بنجاح";
    }
    $alert_class = "success";
}

// حذف وقت إضافي
if (isset($_POST['delete_overtime'])) {
    $pdo->prepare("DELETE FROM overtime WHERE id=?")->execute([intval($_POST['ot_id'])]);
    $msg = "تم حذف الوقت الإضافي بنجاح";
    $alert_class = "danger";
}

// إضافة أو تعديل علاوة/خصم
if (isset($_POST['save_bonus'])) {
    $bonus_id = intval($_POST['bonus_id'] ?? 0);
    $employee_id = intval($_POST['employee_id']);
    $bonus_type = $_POST['bonus_type'];
    $amount = floatval($_POST['amount']);
    $bonus_date = $_POST['bonus_date'];
    $comment = $_POST['comment'];

    if ($bonus_id > 0) {
        $pdo->prepare("UPDATE bonuses SET bonus_type=?, amount=?, bonus_date=?, comment=? WHERE id=?")
            ->execute([$bonus_type, $amount, $bonus_date, $comment, $bonus_id]);
        $msg = "تم تعديل العلاوة/الخصم بنجاح";
    } else {
        $pdo->prepare("INSERT INTO bonuses (employee_id, bonus_type, amount, bonus_date, comment) VALUES (?, ?, ?, ?, ?)")
            ->execute([$employee_id, $bonus_type, $amount, $bonus_date, $comment]);
        $msg = "تمت إضافة علاوة/خصم بنجاح";
    }
    $alert_class = "success";
}

// حذف علاوة/خصم
if (isset($_POST['delete_bonus'])) {
    $pdo->prepare("DELETE FROM bonuses WHERE id=?")->execute([intval($_POST['bonus_id'])]);
    $msg = "تم حذف العلاوة/الخصم بنجاح";
    $alert_class = "danger";
}

// جلب بيانات الوقت الإضافي والعلاوات
$overtime = [];
$bonuses = [];
if ($employee_id) {
    $stmt = $pdo->prepare("SELECT * FROM overtime WHERE employee_id=? AND MONTH(overtime_date)=? AND YEAR(overtime_date)=? ORDER BY overtime_date");
    $stmt->execute([$employee_id, $mon, $year]);
    $overtime = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM bonuses WHERE employee_id=? AND MONTH(bonus_date)=? AND YEAR(bonus_date)=? ORDER BY bonus_date");
    $stmt->execute([$employee_id, $mon, $year]);
    $bonuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدارة الوقت الإضافي والعلاوات</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', Tahoma, Arial, sans-serif; background: #f6f7fa;}
        .main-title { color: #007bff; font-weight: bold;}
        .form-section { background: #fff; border-radius: 15px; padding:18px 10px; box-shadow:0 4px 18px #d0dbe850; margin-bottom: 22px;}
        .table { background: #fff;}
    </style>
</head>
<body>
<div class="container py-3">
    <h2 class="main-title mb-4 text-center">إدارة الوقت الإضافي والعلاوات</h2>
    <?php if($msg): ?>
        <div class="alert alert-<?= $alert_class ?> text-center"><?= $msg ?></div>
    <?php endif; ?>

    <!-- اختيار الموظف والشهر -->
    <form class="form-section row g-2 mb-4" method="get">
        <div class="col-md-4">
            <label class="form-label">الموظف</label>
            <select name="employee_id" class="form-select" required>
                <option value="">اختر...</option>
                <?php foreach($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= $emp['id']==$employee_id?'selected':'' ?>>
                        <?= htmlspecialchars($emp['name']) ?> (<?= htmlspecialchars($emp['emp_code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">الشهر</label>
            <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($month) ?>" required>
        </div>
        <div class="col-md-2 d-grid align-self-end">
            <button class="btn btn-primary">عرض</button>
        </div>
    </form>

    <?php if($employee_id): ?>

    <!-- جدول الوقت الإضافي -->
    <div class="form-section mb-4">
        <h5>الوقت الإضافي</h5>
        <form class="row g-2 mb-2" method="post">
            <input type="hidden" name="employee_id" value="<?= $employee_id ?>">
            <input type="hidden" name="ot_id" value="">
            <div class="col-md-2">
                <input type="date" name="overtime_date" class="form-control" required>
            </div>
            <div class="col-md-2">
                <input type="time" name="from_time" class="form-control" required placeholder="من">
            </div>
            <div class="col-md-2">
                <input type="time" name="to_time" class="form-control" required placeholder="إلى">
            </div>
            <div class="col-md-2">
                <input type="number" name="rate" step="0.01" class="form-control" placeholder="سعر الساعة" required>
            </div>
            <div class="col-md-2 d-grid">
                <button name="save_overtime" class="btn btn-success">إضافة وقت إضافي</button>
            </div>
        </form>
        <div class="table-responsive">
        <table class="table table-bordered text-center align-middle">
            <thead class="table-light">
                <tr>
                    <th>التاريخ</th>
                    <th>من</th>
                    <th>إلى</th>
                    <th>الساعات</th>
                    <th>سعر الساعة</th>
                    <th>الإجمالي</th>
                    <th>تعديل</th>
                    <th>حذف</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($overtime as $ot): ?>
                    <tr>
                        <td><?= htmlspecialchars($ot['overtime_date']) ?></td>
                        <td><?= htmlspecialchars($ot['from_time']) ?></td>
                        <td><?= htmlspecialchars($ot['to_time']) ?></td>
                        <td><?= htmlspecialchars($ot['hours']) ?></td>
                        <td><?= htmlspecialchars($ot['rate']) ?></td>
                        <td><?= number_format($ot['hours'] * $ot['rate'], 2) ?></td>
                        <td>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="ot_id" value="<?= $ot['id'] ?>">
                                <input type="hidden" name="employee_id" value="<?= $employee_id ?>">
                                <input type="hidden" name="overtime_date" value="<?= $ot['overtime_date'] ?>">
                                <input type="hidden" name="from_time" value="<?= $ot['from_time'] ?>">
                                <input type="hidden" name="to_time" value="<?= $ot['to_time'] ?>">
                                <input type="hidden" name="rate" value="<?= $ot['rate'] ?>">
                                <button type="submit" name="save_overtime" class="btn btn-sm btn-warning">تعديل</button>
                            </form>
                        </td>
                        <td>
                            <form method="post" class="d-inline" onsubmit="return confirm('حذف السجل؟');">
                                <input type="hidden" name="ot_id" value="<?= $ot['id'] ?>">
                                <button type="submit" name="delete_overtime" class="btn btn-sm btn-danger">حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach;?>
                <?php if(count($overtime)==0): ?>
                    <tr><td colspan="8" class="text-muted">لا يوجد وقت إضافي لهذا الشهر</td></tr>
                <?php endif;?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- جدول العلاوات والخصومات -->
    <div class="form-section mb-4">
        <h5>العلاوات / الخصومات</h5>
        <form class="row g-2 mb-2" method="post">
            <input type="hidden" name="employee_id" value="<?= $employee_id ?>">
            <input type="hidden" name="bonus_id" value="">
            <div class="col-md-2">
                <input type="date" name="bonus_date" class="form-control" required>
            </div>
            <div class="col-md-2">
                <select name="bonus_type" class="form-select" required>
                    <option value="addition">علاوة</option>
                    <option value="deduction">خصم</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="number" name="amount" step="0.01" class="form-control" placeholder="المبلغ" required>
            </div>
            <div class="col-md-3">
                <input type="text" name="comment" class="form-control" placeholder="ملاحظة">
            </div>
            <div class="col-md-2 d-grid">
                <button name="save_bonus" class="btn btn-success">إضافة</button>
            </div>
        </form>
        <div class="table-responsive">
        <table class="table table-bordered text-center align-middle">
            <thead class="table-light">
                <tr>
                    <th>التاريخ</th>
                    <th>النوع</th>
                    <th>المبلغ</th>
                    <th>ملاحظة</th>
                    <th>تعديل</th>
                    <th>حذف</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($bonuses as $b): ?>
                    <tr>
                        <td><?= htmlspecialchars($b['bonus_date']) ?></td>
                        <td><?= $b['bonus_type']=='addition'?'علاوة':'خصم' ?></td>
                        <td><?= htmlspecialchars($b['amount']) ?></td>
                        <td><?= htmlspecialchars($b['comment']) ?></td>
                        <td>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="bonus_id" value="<?= $b['id'] ?>">
                                <input type="hidden" name="employee_id" value="<?= $employee_id ?>">
                                <input type="hidden" name="bonus_type" value="<?= $b['bonus_type'] ?>">
                                <input type="hidden" name="amount" value="<?= $b['amount'] ?>">
                                <input type="hidden" name="bonus_date" value="<?= $b['bonus_date'] ?>">
                                <input type="hidden" name="comment" value="<?= $b['comment'] ?>">
                                <button type="submit" name="save_bonus" class="btn btn-sm btn-warning">تعديل</button>
                            </form>
                        </td>
                        <td>
                            <form method="post" class="d-inline" onsubmit="return confirm('حذف السجل؟');">
                                <input type="hidden" name="bonus_id" value="<?= $b['id'] ?>">
                                <button type="submit" name="delete_bonus" class="btn btn-sm btn-danger">حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach;?>
                <?php if(count($bonuses)==0): ?>
                    <tr><td colspan="6" class="text-muted">لا توجد علاوات/خصومات لهذا الشهر</td></tr>
                <?php endif;?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
</div>
</body>
</html>