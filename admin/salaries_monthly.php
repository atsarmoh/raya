<?php
require_once 'salary_calculator.php';

$pdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "12341234");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$selected_month = $_GET['month'] ?? date('Y-m');
$year = substr($selected_month, 0, 4);
$month = substr($selected_month, 5, 2);

$employees = $pdo->query("SELECT * FROM employees WHERE is_active=1 ORDER BY department, name")->fetchAll(PDO::FETCH_ASSOC);

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_salaries'])) {
    $salaries = json_decode($_POST['salaries_data'], true);
    if (is_array($salaries)) {
        foreach($salaries as $row) {
            $exists = $pdo->prepare("SELECT id FROM salaries WHERE employee_id=? AND month=?");
            $exists->execute([$row['employee']['id'], $selected_month]);
            if ($exists->fetchColumn()) {
                $stmt = $pdo->prepare("UPDATE salaries SET base_salary=?, total_deductions=?, total_additions=?, overtime_amount=?, net_salary=? WHERE employee_id=? AND month=?");
                $stmt->execute([
                    $row['base_salary'],
                    $row['total_deductions'] + $row['deduction'],
                    $row['total_additions'],
                    $row['overtime_amount'],
                    $row['net_salary'],
                    $row['employee']['id'],
                    $selected_month
                ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO salaries (employee_id, month, base_salary, total_deductions, total_additions, overtime_amount, net_salary) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([
                    $row['employee']['id'],
                    $selected_month,
                    $row['base_salary'],
                    $row['total_deductions'] + $row['deduction'],
                    $row['total_additions'],
                    $row['overtime_amount'],
                    $row['net_salary']
                ]);
            }
        }
        $msg = "تم حفظ الرواتب الشهرية بنجاح.";
    }
}

$all_salaries = [];
foreach($employees as $emp) {
    $all_salaries[] = calculate_employee_salary($pdo, $emp['id'], $selected_month);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>رواتب الموظفين الشهرية</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
    .badge-date {
        background: #e9ecef;
        color: #333;
        font-family: Tahoma,Arial,sans-serif;
        font-size: .93em;
        border-radius: 7px;
        padding: 3px 8px 3px 3px;
        margin-left: 7px;
        margin-right: 3px;
        display: inline-block;
    }
    .icon-btn {
        color: #f0ad4e;
        margin-right: 2px;
        vertical-align: middle;
    }
    body { font-family: 'Cairo', Tahoma, Arial, sans-serif; background: #f4f7fa;}
    .main-title { color: #007B8A; font-weight: bold;}
    .salary-table th, .salary-table td { vertical-align: middle; text-align: center;}
    .salary-row { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px #d2e3e8; margin-bottom: 12px;}
    .salary-summary { background: #f8f9fa; border-radius: 6px; padding: 8px 0;}
    .expand-btn { cursor: pointer; color: #007B8A; }
    .details-row { display: none; background: #eef3f7;}
    .badge-type {font-size: .97em;}
    .search-box {max-width: 300px;}
    @media (max-width: 700px) {
        .salary-table { font-size: 0.92em;}
        .main-title { font-size:1.1em;}
        .salary-row { padding: 0 3px;}
    }
    </style>
    <script>
        function toggleDetails(idx) {
            var row = document.getElementById('details-' + idx);
            row.style.display = (row.style.display==='table-row') ? 'none' : 'table-row';
        }
        function filterTable() {
            var val = document.getElementById('search').value.trim().toLowerCase();
            document.querySelectorAll('.salary-row').forEach(function(tr) {
                var txt = tr.dataset.empname.toLowerCase() + ' ' + tr.dataset.empcode;
                tr.style.display = txt.includes(val) ? '' : 'none';
                var det = document.getElementById('details-' + tr.dataset.idx);
                if (det) det.style.display = 'none';
            });
        }
    </script>
</head>
<body>
<div class="container py-3">
    <h2 class="main-title mb-4 text-center">
        كشف الرواتب الشهرية
        <form method="get" class="d-inline">
            <input type="month" name="month" value="<?=$selected_month?>" onchange="this.form.submit()" class="form-control d-inline" style="width:170px;display:inline-block;">
        </form>
    </h2>
    <?php if($msg): ?>
        <div class="alert alert-success text-center"><?= $msg ?></div>
    <?php endif;?>
    <div class="mb-3">
        <input type="text" id="search" onkeyup="filterTable()" class="form-control search-box" placeholder="بحث باسم أو رقم موظف...">
    </div>
    <form method="post">
    <input type="hidden" name="salaries_data" value="<?= htmlspecialchars(json_encode($all_salaries, JSON_UNESCAPED_UNICODE)) ?>">
    <div class="table-responsive">
    <table class="table salary-table align-middle">
        <thead class="table-light">
            <tr>
                <th></th>
                <th>اسم الموظف</th>
                <th>الرقم</th>
                <th>نوع الراتب</th>
                <th>الراتب الأساسي</th>
                <th>خصم تأخير/مبكر</th>
                <th>علاوات</th>
                <th>خصومات</th>
                <th>دوام إضافي</th>
                <th>ساعات مطلوبة</th>
                <th>ساعات منجزة</th>
                <th>الراتب النهائي</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($all_salaries as $idx => $row): 
        $emp = $row['employee'];
        ?>
        <tr class="salary-row" data-empname="<?= htmlspecialchars($emp['name']) ?>" data-empcode="<?= htmlspecialchars($emp['emp_code']) ?>" data-idx="<?=$idx?>">
            <td><span class="expand-btn" onclick="toggleDetails(<?=$idx?>)">&#x25BC;</span></td>
            <td><?= htmlspecialchars($emp['name']) ?></td>
            <td><?= htmlspecialchars($emp['emp_code']) ?></td>
            <td>
                <?php if($row['salary_type']=='monthly'): ?>
                    <span class="badge bg-primary badge-type">شهري</span>
                <?php else: ?>
                    <span class="badge bg-info text-dark badge-type">يومي/شفت</span>
                <?php endif;?>
            </td>
            <td><?= number_format($row['base_salary'],2) ?></td>
            <td class="text-danger"><?= number_format($row['deduction'],2) ?></td>
            <td class="text-success"><?= number_format($row['total_additions'],2) ?></td>
            <td class="text-danger"><?= number_format($row['total_deductions'],2) ?></td>
            <td class="text-primary"><?= number_format($row['overtime_amount'],2) ?></td>
            <td><?= number_format($row['required_hours'],2) ?></td>
            <td><?= number_format($row['actual_hours'],2) ?></td>
            
<td>
    <?php
    if($row['salary_type']=='monthly') {
        echo number_format($row['net_salary'],2);
    } else {
        echo number_format($row['net_salary'],2);
    }
    ?>
</td>
        </tr>
        <tr class="details-row" id="details-<?=$idx?>">
            <td colspan="12">
                <div class="row">
                    <div class="col-md-5">
                        <ul class="list-group mb-2">
<li class="list-group-item d-flex justify-content-between align-items-center">
    <span>تواريخ التأخير:</span>
    <span>
        <?php
        $emp_code = $row['employee']['emp_code'];
        $arabic_months = [
            '01'=>'يناير','02'=>'فبراير','03'=>'مارس','04'=>'أبريل','05'=>'مايو','06'=>'يونيو',
            '07'=>'يوليو','08'=>'أغسطس','09'=>'سبتمبر','10'=>'أكتوبر','11'=>'نوفمبر','12'=>'ديسمبر'
        ];
        if(count($row['late_dates']) > 0) {
            foreach($row['late_dates'] as $ld) {
                $date = $ld['date'];
                $minutes = $ld['minutes'];
                $dt = explode('-', $date);
                $fdate = intval($dt[2]) . ' ' . $arabic_months[$dt[1]];

                echo "<span class='badge-date'>";
                echo "<a href='../present_today.php?date=$date&emp_code=$emp_code' title='عرض الحضور اليومي' target='_blank' class='icon-btn'><i class='bi bi-clock-history text-info'></i></a>";
                echo "<a href='assign_shifts.php?date=$date' title='تعديل الشفت' target='_blank' class='icon-btn'><i class='bi bi-pencil-square'></i></a>";
                echo " $fdate <span class='text-danger'>(+$minutes د)</span>";
                echo "</span> ";
            }
        } else {
            echo "لا يوجد";
        }
        ?>
    </span>
</li>
<li class="list-group-item d-flex justify-content-between align-items-center">
    <span>أيام الغياب:</span>
    <span>
        <?php
        if (!empty($row['absent_dates'])) {
            foreach($row['absent_dates'] as $date) {
                $dt = explode('-', $date);
                $fdate = intval($dt[2]) . ' ' . $arabic_months[$dt[1]];
                echo "<span class='badge-date bg-danger text-white mx-1'>";
                echo "<a href='assign_shifts.php?date=$date' title='توزيع شفت لهذا اليوم' target='_blank' style='color:inherit; text-decoration:none; margin-left:4px;'><i class='bi bi-pencil-square'></i></a>";
                echo "<a href='../present_today.php?date=$date&emp_code=$emp_code' title='عرض الحضور اليومي' target='_blank' style='color:inherit; text-decoration:none; margin-left:4px;'><i class='bi bi-clock-history'></i></a>";
                echo "$fdate";
                echo "</span> ";
            }
        } else {
            echo "لا يوجد";
        }
        ?>
    </span>
</li>
<li class="list-group-item d-flex justify-content-between align-items-center">
    <span>عدد أيام العمل (المحسوبة):</span>
    <span>
        <?= $row['actual_days_worked'] ?>
        <?php if($row['salary_type']=='daily' && !empty($row['days_double_worked'])): ?>
            <br>
            <span class="text-info" style="font-size:0.90em;">
                أيام تم احتسابها دبل:
                <?php
                foreach($row['days_double_worked'] as $d) {
                    $dt = explode('-', $d);
                    $fdate = intval($dt[2]) . ' ' . $arabic_months[$dt[1]];
                    echo "<span class='badge-date bg-info mx-1 text-dark'>$fdate</span> ";
                }
                ?>
            </span>
        <?php endif; ?>
    </span>
</li>
<li class="list-group-item d-flex justify-content-between align-items-center">
    <span>مجموع دقائق التأخير:</span>
    <span><?= $row['total_late_minutes'] ?></span>
</li>
<li class="list-group-item d-flex justify-content-between align-items-center">
    <span>مجموع دقائق الخروج المبكر:</span>
    <span><?= $row['total_early_minutes'] ?></span>
</li>

<li class="list-group-item d-flex justify-content-between align-items-center">
    <span>مجموع الساعات المطلوبة للشهر:</span>
    <span><?= number_format($row['required_hours'],2) ?> ساعة</span>
</li>
<li class="list-group-item d-flex justify-content-between align-items-center">
    <span>مجموع الساعات المنجزة:</span>
    <span>
        <?php
        // صيغة ساعات:دقائق
        $actual_hours = isset($row['actual_hours']) ? $row['actual_hours'] : 0;
        $hours = floor($actual_hours);
        $minutes = round(($actual_hours - $hours) * 60);
        // تصحيح إذا كانت الدقائق 60
        if($minutes == 60) { $hours++; $minutes = 0; }
        $actual_hours_hm = sprintf('%02d:%02d', $hours, $minutes);
        ?>
        <?= $actual_hours_hm ?> ساعة
        <?php if(isset($row['total_exit_minutes']) && $row['total_exit_minutes'] > 0): ?>
            <span class="text-secondary" style="font-size:0.93em;">(منها مغادرات: <?= $row['total_exit_minutes'] ?> د)</span>
        <?php endif;?>
    </span>
</li>
<?php if($row['salary_type']=='daily' && !empty($row['worked_shifts'])): ?>
<li class="list-group-item">
    <span>تفصيل ساعات العمل الفعلية:</span>
    <div class="table-responsive">
        <table class="table table-sm table-bordered mt-2 mb-0">
            <thead>
                <tr>
                    <th>التاريخ</th>
                    <th>عدد الساعات</th>
                    <th>اسم الشفت</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($row['worked_shifts'] as $ws): 
                    $dt = explode('-', $ws['date']);
                    $fdate = intval($dt[2]) . ' ' . $arabic_months[$dt[1]];
                ?>
                <tr>
                    <td><?= $fdate ?></td>
                    <td><?= $ws['hours'] ?></td>
                    <td><?= htmlspecialchars($ws['shift_name']) ?></td>
                </tr>
                <?php endforeach;?>
            </tbody>
        </table>
    </div>
</li>
<?php endif;?>
                        </ul>
                    </div>
                    <div class="col-md-7">
                        <ul class="list-group mb-2">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>العلاوات:</span>
                                <span>
                                <?php if($row['total_additions']>0): ?>
                                    <span class="text-success"><?= number_format($row['total_additions'],2) ?></span>
                                <?php else: ?>
                                    لا يوجد
                                <?php endif;?>
                                </span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>الخصومات:</span>
                                <span>
                                <?php if($row['total_deductions']>0): ?>
                                    <span class="text-danger"><?= number_format($row['total_deductions'],2) ?></span>
                                <?php else: ?>
                                    لا يوجد
                                <?php endif;?>
                                </span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>دوام إضافي:</span>
                                <span>
                                <?php if($row['overtime_amount']>0): ?>
                                    <span class="text-primary"><?= number_format($row['overtime_amount'],2) ?></span>
                                <?php else: ?>
                                    لا يوجد
                                <?php endif;?>
                                </span>
                            </li>
                            <li class="list-group-item">
                                <span>الراتب النهائي:</span>
                                <span class="fw-bold"><?= number_format($row['net_salary'],2) ?></span>
                            </li>
                        </ul>
                    </div>
                </div>
            </td>
        </tr>
        <?php endforeach;?>
        </tbody>
    </table>
    </div>
    <div class="text-end mt-3">
        <button class="btn btn-success px-5" name="save_salaries">حفظ الرواتب الشهرية</button>
    </div>
    </form>
</div>
</body>
</html>