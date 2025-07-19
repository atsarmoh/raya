<?php
$pdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "12341234");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// معالجة تعديل الشفتات فقط
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['employee_shift'])) {
    $date = $_POST['date'] ?? date('Y-m-d');
    $employee_shifts = $_POST['employee_shift'] ?? [];
    $pdo->prepare("DELETE FROM shift_assignments WHERE shift_date=?")->execute([$date]);
    $added = 0;
    foreach($employee_shifts as $emp_id => $shift_day_id) {
        if($shift_day_id != '' && $shift_day_id !== null) {
            $pdo->prepare("INSERT INTO shift_assignments (employee_id, shift_day_id, shift_date) VALUES (?, ?, ?)")
                ->execute([$emp_id, $shift_day_id, $date]);
            $added++;
        }
    }
    if ($added > 0) {
        $msg = "تم حفظ التوزيعات بنجاح!";
        $alert_class = "success";
    } else {
        $msg = "لم تقم بتوزيع أي موظف على شفت في هذا التاريخ، جميع التوزيعات لهذا اليوم تم حذفها.";
        $alert_class = "warning";
    }
}

// استقبال التاريخ من النموذج أو الرابط أو اليوم الحالي
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_date'])) {
    $selected_date = $_POST['selected_date'] ?: date('Y-m-d');
} else {
    $selected_date = $_GET['date'] ?? date('Y-m-d');
}
$selected_day = date('l', strtotime($selected_date));
$current_year = date('Y', strtotime($selected_date));

// الشفتات
$shifts = $pdo->query("SELECT sd.id as shift_day_id, sd.shift_id, s.name, sd.day_of_week, s.start_time, s.end_time, s.shift_type
FROM shift_days sd
JOIN shifts s ON sd.shift_id = s.id")->fetchAll(PDO::FETCH_ASSOC);

// الموظفون النشطون + الرصيد الافتراضي
$employees = $pdo->query("SELECT *, IFNULL(annual_leave_quota, 21) as annual_leave_quota FROM employees WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// توزيع الشفتات الحالي
$current_assignments_stmt = $pdo->prepare(
    "SELECT sa.employee_id, sa.shift_day_id
     FROM shift_assignments sa
     WHERE sa.shift_date=?"
);
$current_assignments_stmt->execute([$selected_date]);
$current_assignments = $current_assignments_stmt->fetchAll(PDO::FETCH_ASSOC);
$emp_shift_day_map = [];
foreach($current_assignments as $ca) {
    $emp_shift_day_map[$ca['employee_id']] = $ca['shift_day_id'];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>جدولة الشفتات اليومية للموظفين</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Cairo', Tahoma, Arial, sans-serif; background: #f6f7fa;}
        .main-title { color: #007bff; font-weight: bold;}
        .form-section { background: #fff; border-radius: 15px; padding:22px 15px; box-shadow:0 4px 18px #d0dbe850; margin-bottom: 22px;}
        .table { background: #fff;}
        .search-box { max-width: 350px; }
        .expand-btn { cursor: pointer; color: #0d6efd; background: none; border: none; font-size: 1.15em; margin-left: 6px; vertical-align: middle;}
        .employee-details-row { display: none; background: #f9f9fd; }
        .details-cell {padding: 14px 20px; vertical-align: top;}
        @media (max-width: 700px) {
            .form-section, .table { font-size: 0.97em;}
            .main-title { font-size:1.2em;}
        }
        select.form-select { min-width: 110px;}
    </style>
</head>
<body>
<div class="container py-3">
    <h2 class="main-title mb-4 text-center">جدولة توزيع الشفتات اليومية</h2>
    <div class="mb-3 text-center">
        <a href="leaves_exits.php" class="btn btn-outline-danger">
            <i class="bi bi-calendar2-x"></i> إدارة الإجازات والمغادرات
        </a>
    </div>
    <form method="post" class="mb-4 text-center">
        <label class="form-label fw-bold">اختر التاريخ:</label>
        <input type="date" name="selected_date" value="<?= htmlspecialchars($selected_date ?? '') ?>" class="form-control d-inline-block" style="max-width:170px">
        <button type="submit" name="select_date" class="btn btn-primary ms-2">عرض</button>
    </form>
    <?php if(isset($msg) && $msg): ?>
        <div class="alert alert-<?= $alert_class ?> text-center"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <form method="post" class="form-section" style="overflow-x:auto;">
        <input type="hidden" name="date" value="<?= htmlspecialchars($selected_date ?? '') ?>">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h5 class="mb-0"><i class="bi bi-calendar2-week"></i> تغيير شفت الموظف لهذا التاريخ فقط</h5>
            <input type="text" id="search" class="form-control search-box" placeholder="بحث باسم أو رقم موظف...">
        </div>
        <table class="table table-bordered table-hover text-center align-middle" id="employees-table">
            <thead class="table-light">
                <tr>
                    <th>الموظف</th>
                    <th>الرقم الوظيفي</th>
                    <th>المسمى الوظيفي</th>
                    <th>القسم</th>
                    <th>الشفت</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($employees as $emp): 
                $emp_id = $emp['id'];
                $expand_id = "emp-details-{$emp_id}";
            ?>
                <tr>
                    <td style="text-align:right;">
                        <button type="button" class="expand-btn" title="تفاصيل" data-target="<?= $expand_id ?>">
                            <i class="bi bi-chevron-down"></i>
                        </button>
                        <?= htmlspecialchars($emp['name'] ?? '') ?>
                    </td>
                    <td><?= htmlspecialchars($emp['emp_code'] ?? '') ?></td>
                    <td><?= htmlspecialchars($emp['job_title'] ?? '') ?></td>
                    <td><?= htmlspecialchars($emp['department'] ?? '') ?></td>
                    <td>
                        <select name="employee_shift[<?= $emp_id ?>]" class="form-select">
                            <option value="">بدون شفت</option>
                            <?php foreach($shifts as $sh): ?>
                                <?php if($sh['day_of_week'] === $selected_day): ?>
                                    <option value="<?= $sh['shift_day_id'] ?>"
                                        <?= (isset($emp_shift_day_map[$emp_id]) && $emp_shift_day_map[$emp_id] == $sh['shift_day_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sh['name'] ?? '') ?>
                                        <?php if($sh['shift_type'] == 'fixed' && $sh['start_time'] && $sh['end_time']): ?>
                                            (<?=substr($sh['start_time'],0,5)?> - <?=substr($sh['end_time'],0,5)?>)
                                        <?php else: ?>
                                            (مرن)
                                        <?php endif; ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <!-- صف التفاصيل المنسدل: يمكن هنا فقط وضع معلومات عامة عن الموظف أو حذف هذا الجزء -->
                <tr class="employee-details-row" id="<?= $expand_id ?>">
                    <td colspan="5" class="details-cell text-start">
                        <!-- تفاصيل إضافية للموظف (إن أردت) -->
                        <div class="text-secondary">لا يوجد تفاصيل إضافية في هذه الصفحة. لمتابعة الإجازات أو المغادرات انتقل إلى <a href="leaves_exits.php">إدارة الإجازات والمغادرات</a>.</div>
                    </td>
                </tr>
            <?php endforeach;?>
            <?php if(count($employees)==0): ?>
                <tr><td colspan="5">لا يوجد موظفون نشطون.</td></tr>
            <?php endif;?>
            </tbody>
        </table>
        <div class="text-end mt-3">
            <button class="btn btn-primary px-5"><i class="bi bi-save"></i> حفظ التعديلات</button>
        </div>
    </form>
</div>
<script>
document.addEventListener("DOMContentLoaded", function(){
    document.querySelectorAll('.expand-btn').forEach(function(btn){
        btn.addEventListener('click', function(e){
            e.preventDefault();
            e.stopPropagation();
            var targetId = btn.getAttribute('data-target');
            var target = document.getElementById(targetId);
            document.querySelectorAll('.employee-details-row').forEach(function(el){ el.style.display = 'none'; });
            document.querySelectorAll('.expand-btn i').forEach(function(icon){ icon.classList.remove('bi-chevron-up'); icon.classList.add('bi-chevron-down'); });

            if(target.style.display === 'table-row') {
                target.style.display = 'none';
                btn.querySelector('i').classList.remove('bi-chevron-up');
                btn.querySelector('i').classList.add('bi-chevron-down');
            } else {
                target.style.display = 'table-row';
                btn.querySelector('i').classList.remove('bi-chevron-down');
                btn.querySelector('i').classList.add('bi-chevron-up');
            }
        });
    });
    document.getElementById('search')?.addEventListener('keyup', function() {
        var filter = this.value.trim().toLowerCase();
        var rows = document.querySelectorAll("#employees-table tbody tr:not(.employee-details-row)");
        rows.forEach(function(row) {
            var text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
            var next = row.nextElementSibling;
            if (next && next.classList.contains('employee-details-row')) {
                next.style.display = row.style.display === '' ? next.style.display : 'none';
            }
        });
    });
});
</script>
</body>
</html>