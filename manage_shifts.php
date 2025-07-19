<?php
$pdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "12341234");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// إضافة/تعديل شفت
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $shift_type = $_POST['shift_type'] ?? 'fixed';
    $tolerance = intval($_POST['tolerance_minutes']);
    $days = isset($_POST['days']) ? $_POST['days'] : [];
    $shift_id = $_POST['shift_id'] ?? null;

    // حسب نوع الشفت
    $start = null;
    $end = null;
    if ($shift_type == 'fixed') {
        $start = $_POST['start_time'] ?? null;
        $end = $_POST['end_time'] ?? null;
    } elseif ($shift_type == 'flexible_period') {
        $start = $_POST['flex_start'] ?? null;
        $end = $_POST['flex_end'] ?? null;
    }
    // نوع open لا يحتاج start/end

    // من هنا: التعديل لضبط القيم الافتراضية للشفت المرن بفترة سماح
    if ($shift_type == 'flexible_period' && (!$start || !$end)) {
        $start = '08:00:00';
        $end = '00:00:00';
    }
    if ($shift_type == 'open') {
        $start = null;
        $end = null;
    }
    // نهاية التعديل

    if ($shift_id) {
        $stmt = $pdo->prepare("UPDATE shifts SET name=?, start_time=?, end_time=?, tolerance_minutes=?, shift_type=? WHERE id=?");
        $stmt->execute([$name, $start, $end, $tolerance, $shift_type, $shift_id]);

        // جلب أيام الشفت القديمة
        $old_days_stmt = $pdo->prepare("SELECT id, day_of_week FROM shift_days WHERE shift_id=?");
        $old_days_stmt->execute([$shift_id]);
        $old_days = $old_days_stmt->fetchAll(PDO::FETCH_ASSOC);

        // حذف الأيام المتروكة فقط إذا لا يوجد عليها توزيعات (وإلا تجاهل الحذف)
        foreach ($old_days as $row) {
            if (!in_array($row['day_of_week'], $days)) {
                $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM shift_assignments WHERE shift_day_id=?");
                $count_stmt->execute([$row['id']]);
                if($count_stmt->fetchColumn() == 0) {
                    $pdo->prepare("DELETE FROM shift_days WHERE id=?")->execute([$row['id']]);
                }
            }
        }

        // إضافة الأيام الجديدة فقط (التي ليست موجودة أساسًا)
        foreach ($days as $day) {
            $found = false;
            foreach ($old_days as $row) {
                if ($row['day_of_week'] === $day) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $pdo->prepare("INSERT INTO shift_days (shift_id, day_of_week) VALUES (?, ?)")->execute([$shift_id, $day]);
            }
        }
    } else {
        // إضافة شفت جديد مع الأيام المحددة
        $stmt = $pdo->prepare("INSERT INTO shifts (name, start_time, end_time, tolerance_minutes, shift_type) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $start, $end, $tolerance, $shift_type]);
        $shift_id = $pdo->lastInsertId();
        foreach ($days as $day) {
            $pdo->prepare("INSERT INTO shift_days (shift_id, day_of_week) VALUES (?, ?)")->execute([$shift_id, $day]);
        }
    }
    header("Location: manage_shifts.php?msg=success");
    exit;
}

// حذف شفت ... كما هو بدون تغيير

// جلب الشفتات والأيام المرتبطة بها
$shifts_stmt = $pdo->query("SELECT * FROM shifts ORDER BY id DESC");
$shifts = $shifts_stmt->fetchAll(PDO::FETCH_ASSOC);

$shift_days = [];
foreach ($pdo->query("SELECT * FROM shift_days") as $row) {
    $shift_days[$row['shift_id']][] = $row['day_of_week'];
}

$days_of_week = ['Saturday'=>'السبت', 'Sunday'=>'الأحد', 'Monday'=>'الاثنين', 'Tuesday'=>'الثلاثاء', 'Wednesday'=>'الأربعاء', 'Thursday'=>'الخميس', 'Friday'=>'الجمعة'];

$edit_shift = null;
if (isset($_GET['edit'])) {
    foreach ($shifts as $sh) {
        if ($sh['id'] == $_GET['edit']) {
            $edit_shift = $sh;
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدارة الشفتات</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap RTL -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', Tahoma, Arial, sans-serif; background: #f4f7fa;}
        .main-title { letter-spacing: 1px; font-weight: 700; color: #007B8A; margin-bottom: 30px;}
        .form-section { background: #fff; border-radius: 15px; padding:28px 22px; box-shadow:0 4px 18px #d0dbe890; margin-bottom: 32px; }
        .form-label { font-weight: 600;}
        .table { background: #fff;}
        .badge-day { background: #17a2b8; font-size: .95em; margin: 1px 2px;}
        .btn-warning, .btn-danger { font-size: .95em;}
        .table th { background: #e2f1f4;}
        .alert { font-size: 1.05em;}
        .form-check-label { font-weight: 500;}
        .rounded-icon { width:38px; height:38px; border-radius:50%; background:#eaf7f2; display:inline-flex; justify-content:center; align-items:center; color:#099; font-size:1.4em;}
        @media (max-width: 700px) {
            .form-section, .table { font-size: 0.97em;}
            .main-title { font-size: 1.25em;}
        }
    </style>
    <script>
        function toggleShiftType() {
            var type = document.querySelector('input[name="shift_type"]:checked').value;
            var fixedFields = document.querySelectorAll('.fixed-field');
            var flexPeriodFields = document.querySelectorAll('.flexible-period-field');
            if(type === 'fixed') {
                fixedFields.forEach(f => f.style.display = '');
                flexPeriodFields.forEach(f => f.style.display = 'none');
            } else if(type === 'flexible_period') {
                fixedFields.forEach(f => f.style.display = 'none');
                flexPeriodFields.forEach(f => f.style.display = '');
            } else {
                fixedFields.forEach(f => f.style.display = 'none');
                flexPeriodFields.forEach(f => f.style.display = 'none');
            }
        }
        window.addEventListener('DOMContentLoaded', function() {
            toggleShiftType();
            document.querySelectorAll('input[name="shift_type"]').forEach(function(radio) {
                radio.addEventListener('change', toggleShiftType);
            });
        });
    </script>
</head>
<body>
    <div class="container py-4">
        <div class="d-flex align-items-center mb-4">
            <span class="rounded-icon me-2"><i class="bi bi-clock-history"></i></span>
            <h2 class="main-title m-0">إدارة الشفتات</h2>
        </div>

        <?php if(isset($_GET['msg'])): ?>
            <div class="alert alert-success text-center">تم حفظ البيانات بنجاح!</div>
        <?php endif; ?>

        <div class="form-section mb-4">
            <h5 class="mb-3"><?= $edit_shift ? "تعديل بيانات الشفت" : "إضافة شفت جديد" ?></h5>
            <form method="post" class="row g-3 align-items-end">
                <input type="hidden" name="shift_id" value="<?= $edit_shift['id'] ?? '' ?>">
                <div class="col-md-3">
                    <label for="name" class="form-label">اسم الشفت</label>
                    <input required type="text" class="form-control" name="name" id="name" value="<?= $edit_shift['name'] ?? '' ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label d-block">نوع الدوام</label>
                    <?php
                        $shift_type_value = $edit_shift['shift_type'] ?? 'fixed';
                    ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="shift_type" id="type_fixed" value="fixed" <?= $shift_type_value == 'fixed' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="type_fixed">دوام ثابت</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="shift_type" id="type_flexible_period" value="flexible_period" <?= $shift_type_value == 'flexible_period' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="type_flexible_period">مرن بفترة سماح</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="shift_type" id="type_open" value="open" <?= $shift_type_value == 'open' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="type_open">غير محدد (يُحتسب من الحضور للانصراف)</label>
                    </div>
                </div>
                <div class="col-md-2 fixed-field">
                    <label for="start_time" class="form-label">وقت البداية</label>
                    <input type="time" class="form-control" name="start_time" id="start_time" value="<?= $edit_shift && $edit_shift['shift_type']=='fixed' ? $edit_shift['start_time'] : '08:00' ?>">
                </div>
                <div class="col-md-2 fixed-field">
                    <label for="end_time" class="form-label">وقت النهاية</label>
                    <input type="time" class="form-control" name="end_time" id="end_time" value="<?= $edit_shift && $edit_shift['shift_type']=='fixed' ? $edit_shift['end_time'] : '00:00' ?>">
                </div>
                <!-- للفترة المرنة -->
                <div class="col-md-2 flexible-period-field">
                    <label for="flex_start" class="form-label">بداية فترة الحضور</label>
                    <input type="time" class="form-control" name="flex_start" id="flex_start"
                        value="<?= $edit_shift && $edit_shift['shift_type']=='flexible_period'
                            ? $edit_shift['start_time']
                            : '08:00' ?>">
                </div>
                <div class="col-md-2 flexible-period-field">
                    <label for="flex_end" class="form-label">نهاية فترة الحضور</label>
                    <input type="time" class="form-control" name="flex_end" id="flex_end"
                        value="<?= $edit_shift && $edit_shift['shift_type']=='flexible_period'
                            ? $edit_shift['end_time']
                            : '00:00' ?>">
                </div>
                <div class="col-md-2">
                    <label for="tolerance_minutes" class="form-label">مدة السماح (دقائق)</label>
                    <input required type="number" class="form-control" name="tolerance_minutes" id="tolerance_minutes" min="0" value="<?= $edit_shift['tolerance_minutes'] ?? 10 ?>">
                </div>
                <div class="col-md-12">
                    <label class="form-label">أيام الشفت</label>
                    <div class="d-flex flex-wrap gap-1">
                    <?php foreach($days_of_week as $eng=>$arabic): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="days[]" value="<?= $eng ?>"
                                id="day_<?= $eng ?>"
                                <?php if($edit_shift && in_array($eng, $shift_days[$edit_shift['id']] ?? [])) echo 'checked'; ?>>
                            <label class="form-check-label" for="day_<?= $eng ?>"><?= $arabic ?></label>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-12 text-end">
                    <button class="btn btn-primary px-4"><?= $edit_shift ? "تحديث" : "إضافة" ?></button>
                    <?php if($edit_shift): ?>
                        <a href="manage_shifts.php" class="btn btn-secondary px-3">إلغاء</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <h5 class="mb-3">جميع الشفتات</h5>
        <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle text-center">
            <thead>
                <tr>
                    <th>اسم الشفت</th>
                    <th>نوع الدوام</th>
                    <th>البداية</th>
                    <th>النهاية</th>
                    <th>مدة السماح</th>
                    <th>أيام العمل</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($shifts as $sh): ?>
                <tr>
                    <td><?= htmlspecialchars($sh['name']) ?></td>
                    <td>
                        <?php if($sh['shift_type']=='open'): ?>
                            <span class="badge bg-secondary">غير محدد</span>
                        <?php elseif($sh['shift_type']=='flexible_period'): ?>
                            <span class="badge bg-info text-dark">مرن بفترة</span>
                        <?php elseif($sh['shift_type']=='flexible'): ?>
                            <span class="badge bg-info text-dark">مرن</span>
                        <?php else: ?>
                            <span class="badge bg-primary">ثابت</span>
                        <?php endif;?>
                    </td>
                    <td>
                        <?php
                        if($sh['shift_type']=='fixed' || $sh['shift_type']=='flexible_period') 
                            echo htmlspecialchars(substr($sh['start_time'],0,5));
                        else echo '-';
                        ?>
                    </td>
                    <td>
                        <?php
                        if($sh['shift_type']=='fixed' || $sh['shift_type']=='flexible_period') 
                            echo htmlspecialchars(substr($sh['end_time'],0,5));
                        else echo '-';
                        ?>
                    </td>
                    <td><?= intval($sh['tolerance_minutes']) ?> دقيقة</td>
                    <td>
                        <?php
                            $days = $shift_days[$sh['id']] ?? [];
                            foreach($days as $d) echo '<span class="badge badge-day">'.$days_of_week[$d].'</span>';
                        ?>
                    </td>
                    <td>
                        <a href="?edit=<?= $sh['id'] ?>" class="btn btn-warning btn-sm">تعديل</a>
                        <a href="?delete=<?= $sh['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('هل أنت متأكد من الحذف؟')">حذف</a>
                    </td>
                </tr>
            <?php endforeach;?>
            <?php if(count($shifts)==0): ?>
                <tr><td colspan="7">لا توجد شفتات مُسجلة بعد.</td></tr>
            <?php endif;?>
            </tbody>
        </table>
        </div>
    </div>
    <!-- Bootstrap icons (اختياري لجمال الأيقونات) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</body>
</html>