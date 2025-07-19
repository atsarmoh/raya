<?php
// دالة تنظيف المصفوفات لأي IN
function clean_array($arr) {
    return array_values(array_filter($arr, function($v) {
        return $v !== null && $v !== '' && is_numeric($v) && $v > 0;
    }));
}

$pdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "12341234");

// جلب بصمات الحضور اليوم
$selected_date = $_GET['date'] ?? date('Y-m-d');
$records_stmt = $pdo->prepare("
    SELECT emp_code, 
        MIN(punch_time) AS check_in, 
        MAX(CASE WHEN punch_type='انصراف' THEN punch_time END) AS check_out
    FROM attendance_records
    WHERE work_date = :date AND punch_type IN ('حضور','انصراف')
    GROUP BY emp_code
");
$records_stmt->execute(['date' => $selected_date]);
$attendance = $records_stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب بيانات كل موظف بناءً على كود البصمة
$emp_codes = array_column($attendance, 'emp_code');
$employees = [];
if (count($emp_codes) > 0) {
    $emp_codes = array_values(array_filter($emp_codes, fn($v) => $v !== null && $v !== ''));
    if (count($emp_codes) > 0) {
        $codes_in = implode(',', array_fill(0, count($emp_codes), '?'));
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE emp_code IN ($codes_in)");
        $stmt->execute($emp_codes);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $emp) {
            $employees[$emp['emp_code']] = $emp;
        }
    }
}

// ربط كل موظف بشفت اليوم (shift_assignments ← shift_days ← shifts)
$shifts = [];
$assignments = [];
$emp_ids = [];
if (count($employees) > 0) {
    foreach ($employees as $e) {
        if (isset($e['id']) && $e['id'] !== null && $e['id'] !== '') $emp_ids[$e['emp_code']] = $e['id'];
    }

    $emp_ids = clean_array($emp_ids);
    if (count($emp_ids) > 0) {
        $emp_ids_in = implode(',', array_fill(0, count($emp_ids), '?'));
        $params = $emp_ids;
        $params[] = $selected_date;

        $sql = "SELECT * FROM shift_assignments WHERE employee_id IN ($emp_ids_in) AND shift_date = ?";
        $shift_assign_stmt = $pdo->prepare($sql);
        $shift_assign_stmt->execute($params);
        $assignments = $shift_assign_stmt->fetchAll(PDO::FETCH_ASSOC);

        // جلب بيانات shift_days
        $shift_day_ids = [];
        $emp_shift_day_map = [];
        foreach ($assignments as $ass) {
            if (isset($ass['shift_day_id']) && $ass['shift_day_id'] !== null && $ass['shift_day_id'] !== '') {
                $shift_day_ids[] = $ass['shift_day_id'];
                $emp_shift_day_map[$ass['employee_id']] = $ass['shift_day_id'];
            }
        }
        $shift_day_ids = clean_array(array_unique($shift_day_ids));
        $shift_days = [];
        if (count($shift_day_ids) > 0) {
            $shift_day_ids_in = implode(',', array_fill(0, count($shift_day_ids), '?'));
            $shift_days_stmt = $pdo->prepare("SELECT * FROM shift_days WHERE id IN ($shift_day_ids_in)");
            $shift_days_stmt->execute($shift_day_ids);
            foreach ($shift_days_stmt->fetchAll(PDO::FETCH_ASSOC) as $sd) {
                $shift_days[$sd['id']] = $sd;
            }
        } else {
            $shift_days = [];
        }

        // جلب بيانات shifts
        $shift_ids = [];
        foreach ($shift_days as $sd) { if (isset($sd['shift_id']) && $sd['shift_id'] !== null && $sd['shift_id'] !== '') $shift_ids[] = $sd['shift_id']; }
        $shift_ids = clean_array(array_unique($shift_ids));
        $shift_defs = [];
        if (count($shift_ids) > 0) {
            $shift_ids_in = implode(',', array_fill(0, count($shift_ids), '?'));
            $shifts_stmt = $pdo->prepare("SELECT * FROM shifts WHERE id IN ($shift_ids_in)");
            $shifts_stmt->execute($shift_ids);
            foreach ($shifts_stmt->fetchAll(PDO::FETCH_ASSOC) as $shf) {
                $shift_defs[$shf['id']] = $shf;
            }
        } else {
            $shift_defs = [];
        }

        // تحديد الشفت لكل موظف
        foreach ($employees as $code => $emp) {
            $emp_id = $emp['id'] ?? null;
            $shift_day_id = $emp_shift_day_map[$emp_id] ?? null;
            $shift_day = $shift_day_id && isset($shift_days[$shift_day_id]) ? $shift_days[$shift_day_id] : null;
            $shift = $shift_day && isset($shift_defs[$shift_day['shift_id']]) ? $shift_defs[$shift_day['shift_id']] : null;
            if ($shift) {
                $shifts[$code] = [
                    'name' => $shift['name'],
                    'start_time' => $shift['start_time'],
                    'end_time' => $shift['end_time'],
                    'shift_type' => $shift['shift_type'] ?? 'fixed',
                    'tolerance_minutes' => $shift['tolerance_minutes'] ?? 0,
                ];
            } else {
                $shifts[$code] = null;
            }
        }
    }
}

// دالة احتساب التأخير حسب نوع الشفت مع معالجة الشفت الليلي أو الممتد
function calculate_late($shift_type, $check_in_ts, $start_ts, $end_ts, $tolerance_minutes=0) {
    $tolerance_secs = $tolerance_minutes * 60;
    // معالجة شفت مرن بدون إطار زمني (غير منطقي)
    if ($shift_type !== 'fixed' && date('H:i:s', $start_ts) == '00:00:00' && date('H:i:s', $end_ts) == '00:00:00' && $start_ts == $end_ts) {
        return [false, 0];
    }
    if (!$check_in_ts || (!$start_ts && $shift_type !== 'open') || (!$end_ts && $shift_type !== 'open')) {
        return [false, 0];
    }
    if ($end_ts && $start_ts && $end_ts <= $start_ts) {
        $end_ts = strtotime('+1 day', $end_ts);
    }

    if ($shift_type === 'open') {
        // فقط تحقق من وجود بصمة دخول
        if ($check_in_ts) {
            return [false, 0];
        } else {
            return [true, 0];
        }
    } elseif ($shift_type === 'fixed') {
        $allowed_time = $start_ts + $tolerance_secs;
        if ($check_in_ts > $allowed_time) {
            return [true, $check_in_ts - $allowed_time];
        } else {
            return [false, 0];
        }
    } elseif ($shift_type === 'flexible_period') {
        $latest_allowed = $start_ts + $tolerance_secs;
        if ($check_in_ts <= $latest_allowed) {
            return [false, 0];
        } elseif ($check_in_ts > $latest_allowed && $check_in_ts <= $end_ts) {
            return [true, $check_in_ts - $latest_allowed];
        } else {
            return [true, 0];
        }
    }
    return [false, 0];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>الحضور اليومي (كل من له بصمة)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap RTL -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', Tahoma, Arial, sans-serif; background: #f8f9fa;}
        .main-title { color: #007B8A; font-weight: bold;}
        .table { background: #fff;}
        .inactive-row { background: #f7e9e9 !important; }
        .late { color: #c00; font-weight:bold; }
        .on-time { color: #28a745; font-weight:bold; }
        @media (max-width: 700px) {
            .table { font-size: 0.97em;}
            .main-title { font-size:1.1em;}
        }
        .avatar { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; }
    </style>
</head>
<body>
<div class="container py-3">
    <h2 class="main-title mb-4 text-center">الحضور بالبصمة ليوم
        <form method="get" class="d-inline">
            <input type="date" name="date" value="<?=$selected_date?>" onchange="this.form.submit()" class="form-control d-inline" style="width:170px;display:inline-block;">
        </form>
    </h2>
    <div class="mb-3">
        <input type="text" id="search" class="form-control" placeholder="بحث باسم أو رقم...">
    </div>
    <div class="table-responsive">
    <table class="table table-bordered table-hover align-middle text-center" id="attendance-table">
        <thead>
            <tr>
                <th>الاسم</th>
                <th>الرقم الوظيفي</th>
                <th>الحالة</th>
                <th>وقت الشفت</th>
                <th>وقت الحضور</th>
                <th>وقت الانصراف</th>
                <th>مدة التأخير</th>
                <th>مدة الدوام</th>
                <th>نقص الدوام</th>
                <th>حالة الحضور</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($attendance as $row):
            $emp = $employees[$row['emp_code']] ?? null;
            $is_active = $emp ? $emp['is_active'] : 0;
            $shift = $shifts[$row['emp_code']] ?? null;

            $check_in = $row['check_in'] ? strtotime($row['check_in']) : null;
            $check_out = $row['check_out'] ? strtotime($row['check_out']) : null;

            $shift_type = $shift['shift_type'] ?? 'fixed';
            $shift_start = ($shift && $shift['start_time']) ? strtotime($selected_date . ' ' . $shift['start_time']) : null;
            $shift_end   = ($shift && $shift['end_time']) ? strtotime($selected_date . ' ' . $shift['end_time']) : null;
            if ($shift_end !== null && $shift_start !== null && $shift_end <= $shift_start) {
                $shift_end = strtotime('+1 day', $shift_end);
            }
            $tolerance = $shift['tolerance_minutes'] ?? 0;

            list($is_late, $late_duration) = calculate_late(
                $shift_type,
                $check_in,
                $shift_start,
                $shift_end,
                $tolerance
            );

            // مدة الدوام المطلوبة (8 ساعات)
            $required_seconds = 8 * 60 * 60;
            // حساب مدة الدوام الفعلية
            if ($check_in && $check_out && $check_out > $check_in) {
                $actual_seconds = $check_out - $check_in;
                $h = floor($actual_seconds / 3600);
                $m = floor(($actual_seconds % 3600) / 60);
                $s = $actual_seconds % 60;
                $actual_duration = sprintf('%02d:%02d:%02d', $h, $m, $s);
            } else {
                $actual_seconds = 0;
                $actual_duration = '-';
            }
            // حساب النقص (الخصم)
            if ($actual_seconds > 0 && $actual_seconds < $required_seconds) {
                $missing_seconds = $required_seconds - $actual_seconds;
                $mh = floor($missing_seconds / 3600);
                $mm = floor(($missing_seconds % 3600) / 60);
                $ms = $missing_seconds % 60;
                $missing_duration = sprintf('%02d:%02d:%02d', $mh, $mm, $ms);
            } else {
                $missing_duration = '-';
            }
        ?>
            <tr class="<?= $is_active ? '' : 'inactive-row' ?>">
                
                <td><?= $emp ? htmlspecialchars($emp['name']) : '---' ?></td>
                <td><?= htmlspecialchars($row['emp_code']) ?></td>
                <td>
                    <?php if($is_active): ?>
                        <span class="badge bg-success">موظف نشط</span>
                    <?php else: ?>
                        <span class="badge bg-danger">غير نشط</span>
                    <?php endif;?>
                </td>
                <td>
                    <?php if($shift): ?>
                        <?php if($shift['shift_type']=='open'): ?>
                            <span class="badge bg-secondary">مرن مفتوح</span>
                        <?php else: ?>
                            <?= $shift['start_time'] ? htmlspecialchars($shift['start_time']) : '--:--' ?> - <?= $shift['end_time'] ? htmlspecialchars($shift['end_time']) : '--:--' ?>
                            <span class="badge bg-info"><?= $shift['shift_type'] ?? '' ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">--:--</span>
                    <?php endif;?>
                </td>
                <td>
                    <?php
                    if ($check_in) echo date('H:i:s', $check_in);
                    else echo '<span class="text-danger">لم يسجل دخول</span>';
                    ?>
                </td>
                <td>
                    <?php
                    if ($check_out) echo date('H:i:s', $check_out);
                    else echo '<span class="text-danger">لم يسجل انصراف</span>';
                    ?>
                </td>
                <td class="<?= $is_late ? 'late' : 'on-time' ?>">
                    <?php
                    if (!$shift) {
                        echo '<span class="text-muted">---</span>';
                    } elseif ($shift['shift_type'] === 'open') {
                        echo '-';
                    } elseif (!$check_in || !$shift_start || !$shift_end) {
                        echo '-';
                    } elseif ($is_late && $late_duration > 0) {
                        $h = floor($late_duration / 3600);
                        $m = floor(($late_duration % 3600) / 60);
                        $s = $late_duration % 60;
                        echo sprintf('%02d:%02d:%02d', $h, $m, $s);
                    } elseif ($is_late) {
                        echo '<span class="text-warning">خارج الوقت المسموح</span>';
                    } else {
                        echo '-';
                    }
                    ?>
                </td>
                <td>
                    <?php
                    // لا تعتمد على الشفت في حساب مدة الدوام، فقط الحضور والانصراف
                    if (!$check_in) echo '<span class="text-danger">لم يسجل دخول</span>';
                    elseif (!$check_out) echo '<span class="text-danger">لم يسجل انصراف</span>';
                    else echo $actual_duration;
                    ?>
                </td>
                <td>
                    <?php
                    if (!$check_in || !$check_out) echo '-';
                    elseif ($actual_seconds < $required_seconds) echo '<span class="text-danger">'.$missing_duration.'</span>';
                    else echo '-';
                    ?>
                </td>
                <td>
                    <?php
                    if (!$shift) {
                        echo '<span class="badge bg-warning text-dark">لم يرتبط بشفت</span>';
                    } elseif ($shift['shift_type'] === 'open') {
                        if (!$check_in) echo '<span class="badge bg-secondary">لم يسجل حضور</span>';
                        else echo '<span class="badge bg-success">في الوقت</span>';
                    } elseif (!$check_in || !$shift_start || !$shift_end) {
                        echo '<span class="badge bg-secondary">لم يسجل حضور</span>';
                    } else {
                        echo $is_late
                            ? '<span class="badge bg-danger">متأخر</span>'
                            : '<span class="badge bg-success">في الوقت</span>';
                    }
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if(count($attendance) == 0): ?>
            <tr><td colspan="11">لا يوجد حضور مسجل في هذا اليوم.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<script>
    // بحث فوري
    document.getElementById('search').addEventListener('keyup', function() {
        var filter = this.value.trim().toLowerCase();
        var rows = document.querySelectorAll("#attendance-table tbody tr");
        rows.forEach(function(row) {
            var text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });
</script>
</body>
</html>