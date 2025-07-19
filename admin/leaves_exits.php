<?php
$pdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "12341234");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ===================================
// دوال الشفتات
// ===================================

// جلب بيانات الشفت للموظف في يوم معين
function get_shift_info($pdo, $emp_id, $date) {
    $stmt = $pdo->prepare("
        SELECT s.shift_type, s.end_time, s.start_time, sa.shift_day_id
        FROM shift_assignments sa
        JOIN shift_days sd ON sa.shift_day_id=sd.id
        JOIN shifts s ON sd.shift_id=s.id
        WHERE sa.employee_id=? AND sa.shift_date=?
    ");
    $stmt->execute([$emp_id, $date]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// أول بصمة حضور في اليوم
function get_first_checkin($pdo, $emp_code, $date) {
    $stmt = $pdo->prepare("
        SELECT punch_time FROM attendance_records
        WHERE emp_code=? AND DATE(punch_time)=? AND punch_type='حضور'
        ORDER BY punch_time ASC LIMIT 1
    ");
    $stmt->execute([$emp_code, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['punch_time'] : null;
}

// آخر بصمة انصراف في اليوم
function get_last_checkout($pdo, $emp_code, $date) {
    $stmt = $pdo->prepare("
        SELECT punch_time FROM attendance_records
        WHERE emp_code=? AND DATE(punch_time)=? AND punch_type='انصراف'
        ORDER BY punch_time DESC LIMIT 1
    ");
    $stmt->execute([$emp_code, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['punch_time'] : null;
}

// مجموع مدة الدوام الفعلي
function get_actual_work_minutes($pdo, $emp_code, $date) {
    $in = get_first_checkin($pdo, $emp_code, $date);
    $out = get_last_checkout($pdo, $emp_code, $date);
    if ($in && $out) {
        $min = round((strtotime($out) - strtotime($in)) / 60);
        return $min > 0 ? $min : 0;
    }
    return 0;
}

// نهاية الدوام حسب نوع الشفت
function get_shift_end_time($pdo, $emp_id, $emp_code, $date, $official_hours = 8) {
    $shift = get_shift_info($pdo, $emp_id, $date);
    if ($shift) {
        if ($shift['shift_type'] == 'flexible') {
            $first_in = get_first_checkin($pdo, $emp_code, $date);
            if ($first_in) {
                $start_time = strtotime($first_in);
                $end_time = $start_time + ($official_hours * 3600);
                return [
                    'datetime' => date('Y-m-d H:i:s', $end_time),
                    'shift_day_id' => $shift['shift_day_id'],
                    'shift_type' => 'flexible'
                ];
            } else {
                // لا حضور: نهاية اليوم
                return [
                    'datetime' => $date . ' 23:59:59',
                    'shift_day_id' => $shift['shift_day_id'],
                    'shift_type' => 'flexible'
                ];
            }
        } elseif ($shift['shift_type'] == 'open') {
            $last_out = get_last_checkout($pdo, $emp_code, $date);
            if ($last_out) {
                return [
                    'datetime' => $last_out,
                    'shift_day_id' => $shift['shift_day_id'],
                    'shift_type' => 'open'
                ];
            } else {
                return [
                    'datetime' => $date . ' 23:59:59',
                    'shift_day_id' => $shift['shift_day_id'],
                    'shift_type' => 'open'
                ];
            }
        } else {
            // ثابت
            if ($shift['end_time'] <= $shift['start_time']) {
                return [
                    'datetime' => date('Y-m-d', strtotime($date . ' +1 day')) . ' ' . $shift['end_time'],
                    'shift_day_id' => $shift['shift_day_id'],
                    'shift_type' => 'fixed'
                ];
            } else {
                return [
                    'datetime' => $date . ' ' . $shift['end_time'],
                    'shift_day_id' => $shift['shift_day_id'],
                    'shift_type' => 'fixed'
                ];
            }
        }
    }
    return [
        'datetime' => $date . ' 23:59:59',
        'shift_day_id' => null,
        'shift_type' => null
    ];
}

// ===================================
// استقبال وتهيئة المتغيرات العامة
// ===================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_date'])) {
    $selected_date = $_POST['selected_date'] ?: date('Y-m-d');
} else {
    $selected_date = $_GET['date'] ?? date('Y-m-d');
}
$current_year = date('Y', strtotime($selected_date));
$official_hours = 8;

// الموظفون النشطون
$employees = $pdo->query("SELECT *, IFNULL(annual_leave_quota, 21) as annual_leave_quota FROM employees WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// أرصدة الموظفين من جدول balances للسنة الحالية
$balances = [];
$stmt = $pdo->prepare("SELECT * FROM balances WHERE year=?");
$stmt->execute([$current_year]);
foreach($stmt as $row) {
    $balances[$row['employee_id']] = $row;
}

// ===================================
// معالجة إضافة مغادرة (القواعد الكاملة)
// ===================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grant_exit'])) {
    $emp_id = intval($_POST['exit_employee_id'] ?? 0);
    $date = $_POST['exit_date'] ?? date('Y-m-d');
    $exit_type = $_POST['exit_type'] ?? '';
    $from_time = $_POST['exit_from_time'] ?? '';
    $to_time = $_POST['exit_to_time'] ?? null;

    $emp_stmt = $pdo->prepare("SELECT emp_code FROM employees WHERE id=?");
    $emp_stmt->execute([$emp_id]);
    $emp_code = $emp_stmt->fetchColumn();

    // لا تمنح مغادرة لموظف أكمل دوامه فعلاً
    $shift = get_shift_info($pdo, $emp_id, $date);
    $already_completed = false;
    if ($shift) {
        if ($shift['shift_type'] == 'flexible') {
            $actual_minutes = get_actual_work_minutes($pdo, $emp_code, $date);
            if ($actual_minutes >= $official_hours * 60) $already_completed = true;
        } elseif ($shift['shift_type'] == 'open') {
            $actual_minutes = get_actual_work_minutes($pdo, $emp_code, $date);
            if ($actual_minutes > 0) $already_completed = true;
        } else {
            $shift_end = get_shift_end_time($pdo, $emp_id, $emp_code, $date, $official_hours)['datetime'];
            $last_out = get_last_checkout($pdo, $emp_code, $date);
            if ($last_out && strtotime($last_out) >= strtotime($shift_end)) $already_completed = true;
        }
    }
    if ($already_completed) {
        $msg = "لا يمكن منح مغادرة لموظف أكمل دوامه اليوم أو أنهى الشفت.";
        $alert_class = "danger";
    } elseif (!$emp_id || !$from_time || !$exit_type) {
        $msg = "يرجى إدخال جميع بيانات المغادرة";
        $alert_class = "danger";
    } else {
        // ----------- تحقق من رصيد المغادرة من leave_balances -----------
        $leave_type_id = 4; // رقم نوع المغادرة الشخصية حسب جدول leave_types لديك
        $year = date('Y', strtotime($date));
        $stmt = $pdo->prepare("SELECT balance FROM leave_balances WHERE emp_code=? AND leave_type_id=? AND year=?");
        $stmt->execute([$emp_code, $leave_type_id, $year]);
        $exit_remain = floatval($stmt->fetchColumn() ?: 0);

        // احسب دقائق المغادرة المطلوبة
        if ($exit_type === "temporary" && $to_time) {
            $duration = (strtotime($to_time) - strtotime($from_time)) / 60;
            if ($duration < 0) $duration = 0;
        } else {
            // مغادرة نهائية
            $from_time_full = strtotime("$date $from_time");
            $shift_data = get_shift_end_time($pdo, $emp_id, $emp_code, $date, $official_hours);
            $work_end_full = strtotime($shift_data['datetime']);
            $duration = ($work_end_full - $from_time_full) / 60;
            if ($duration < 0) $duration = 0;
        }

        if ($duration > $exit_remain) {
            $msg = "لا يمكن منح مغادرة: الموظف لا يملك رصيد مغادرات كافٍ (رصيده المتبقي: $exit_remain دقيقة فقط)";
            $alert_class = "danger";
        } else {
            $shift_data = get_shift_end_time($pdo, $emp_id, $emp_code, $date, $official_hours);
            $work_end_time = $shift_data['datetime'];
            $shift_day_id = $shift_data['shift_day_id'];

            if ($exit_type === "temporary" && $to_time) {
                $status = "waiting";
            } else {
                $to_time = null;
                $status = "approved";
            }
            $pdo->prepare("INSERT INTO exit_permissions (employee_id, date, shift_id, from_time, to_time, duration_requested, duration_real, type, status)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)")
                ->execute([$emp_id, $date, $shift_day_id, $from_time, $to_time, intval($duration), $exit_type, $status]);
            $msg = "تم منح إذن مغادرة للموظف بنجاح.";
            $alert_class = "success";
        }
    }
}
// ===================================
// معالجة إضافة إجازة
// ===================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grant_leave'])) {
    $emp_id = intval($_POST['leave_employee_id'] ?? 0);
    $leave_type = $_POST['leave_type'] ?? '';
    $start_date = $_POST['leave_start_date'] ?? '';
    $end_date = $_POST['leave_end_date'] ?? '';
    if (!$emp_id || !$leave_type || !$start_date || !$end_date) {
        $msg = "يرجى إدخال جميع بيانات الإجازة";
        $alert_class = "danger";
    } else {
        $pdo->prepare("INSERT INTO leaves (employee_id, type, start_date, end_date, status) VALUES (?, ?, ?, ?, 'approved')")
            ->execute([$emp_id, $leave_type, $start_date, $end_date]);
        $msg = "تم منح إجازة للموظف بنجاح.";
        $alert_class = "success";
    }
}

// ===================================
// معالجة المغادرات المؤقتة فعلياً بناءً على البصمات
// ===================================

function process_exits($pdo, $date) {
    $stmt = $pdo->prepare("SELECT ep.*, e.emp_code FROM exit_permissions ep
        JOIN employees e ON ep.employee_id = e.id
        WHERE ep.date = ? AND ep.type = 'temporary'
        AND (ep.status = 'waiting' OR ep.status = 'done' OR ep.status = 'cancelled')");
    $stmt->execute([$date]);
    foreach ($stmt as $exit) {
        $emp_id = $exit['employee_id'];
        $emp_code = $exit['emp_code'];
        $from_time = $exit['from_time'];
        $exit_id = $exit['id'];
        $duration_requested = intval($exit['duration_requested']);
        $from_datetime = $date . ' ' . $from_time;

        // أول انصراف بعد وقت المغادرة
        $out_stmt = $pdo->prepare("SELECT punch_time FROM attendance_records
            WHERE emp_code=? AND punch_type='انصراف' AND punch_time >= ?
            ORDER BY punch_time ASC LIMIT 1");
        $out_stmt->execute([$emp_code, $from_datetime]);
        $actual_out = $out_stmt->fetchColumn();

        if ($actual_out) {
            // أول حضور بعد الانصراف
            $in_stmt = $pdo->prepare("SELECT punch_time FROM attendance_records
                WHERE emp_code=? AND punch_type='حضور' AND punch_time > ?
                ORDER BY punch_time ASC LIMIT 1");
            $in_stmt->execute([$emp_code, $actual_out]);
            $actual_in = $in_stmt->fetchColumn();

            if ($actual_in) {
                // هل يوجد انصراف جديد بعد أول حضور؟
                $next_out_stmt = $pdo->prepare("SELECT punch_time FROM attendance_records
                    WHERE emp_code=? AND punch_type='انصراف' AND punch_time > ?
                    ORDER BY punch_time ASC LIMIT 1");
                $next_out_stmt->execute([$emp_code, $actual_in]);
                $next_out = $next_out_stmt->fetchColumn();

                if ($next_out) {
                    // الموظف خرج بعد عودته، اعتبر أنه لم يرجع فعليا أو خرج بشكل نهائي
                    $duration_real = round((strtotime($next_out) - strtotime($actual_out)) / 60);
                    if ($duration_real < 0) $duration_real = 0;
                    $pdo->prepare("UPDATE exit_permissions
                        SET duration_real=?, status='out_without_return', notes='خرج بعد العودة'
                        WHERE id=?")
                        ->execute([$duration_real, $exit_id]);
                } else {
                    // عاد وواصل عمله (حالة طبيعية)
                    $duration_real = round((strtotime($actual_in) - strtotime($actual_out)) / 60);
                    if ($duration_real < 0) $duration_real = 0;
                    $deduct = min($duration_real, $duration_requested);
                    $late_minutes = max(0, $duration_real - $duration_requested);

                    $pdo->prepare("UPDATE exit_permissions SET duration_real=?, status='done', late_minutes=? WHERE id=?")
                        ->execute([$deduct, $late_minutes, $exit_id]);
                }
			}
        } else {
            // لم يبصم انصراف: لا تخصم ولا تنفذ المغادرة
$pdo->prepare("UPDATE exit_permissions SET duration_real=0, status='cancelled' WHERE id=?")
    ->execute([$exit_id]);
        }
    }
    // معالجة المغادرات النهائية
    $stmt2 = $pdo->prepare("SELECT ep.*, e.emp_code FROM exit_permissions ep
        JOIN employees e ON ep.employee_id=e.id
        WHERE ep.date=? AND ep.type='final' AND ep.status='approved' AND (duration_real IS NULL OR duration_real=0)");
    $stmt2->execute([$date]);
    foreach ($stmt2 as $exit) {
        $emp_id = $exit['employee_id'];
        $emp_code = $exit['emp_code'];
        $from_time = $exit['from_time'];
        $exit_id = $exit['id'];

        // جلب أول بصمة انصراف بعد وقت المغادرة
        $out_stmt = $pdo->prepare("SELECT punch_time FROM attendance_records
            WHERE emp_code=? AND punch_type='انصراف' AND punch_time >= ?
            ORDER BY punch_time ASC LIMIT 1");
        $out_stmt->execute([$emp_code, $date, $from_time]);
        $actual_out = $out_stmt->fetchColumn();

        if ($actual_out) {
            // نهاية الشفت
            $shift_data = get_shift_end_time($pdo, $emp_id, $emp_code, $date);
            $shift_end_time = $shift_data['datetime'];
            $from_sec = strtotime($actual_out);
            $to_sec = strtotime($shift_end_time);
            $duration = round(($to_sec - $from_sec) / 60);
            if ($duration < 0) $duration = 0;

            $pdo->prepare("UPDATE exit_permissions SET duration_real=?, status='done' WHERE id=?")
                ->execute([$duration, $exit_id]);
        } else {
            // لم يبصم انصراف: لا تخصم ولا تنفذ المغادرة
            $pdo->prepare("UPDATE exit_permissions SET duration_real=0, status='cancelled' WHERE id=?")
                ->execute([$exit_id]);
        }
    }
}
process_exits($pdo, $selected_date);

// ===================================
// جلب بيانات العرض
// ===================================
// جلب جميع أرصدة leave_balances لكل موظف
$leave_balances = [];
$stmt = $pdo->prepare("SELECT * FROM leave_balances WHERE year = ?");
$stmt->execute([$current_year]);
foreach ($stmt as $row) {
    // استخدم emp_code بدلاً من id في الربط!
    $leave_balances[$row['emp_code']][$row['leave_type_id']] = $row['balance'];
}
$leaves_stmt = $pdo->prepare("SELECT * FROM leaves WHERE ? BETWEEN start_date AND end_date AND status='approved'");
$leaves_stmt->execute([$selected_date]);
$leave_map = [];
foreach ($leaves_stmt as $lv) {
    $leave_map[$lv['employee_id']][] = $lv;
}

$exit_permissions_stmt = $pdo->prepare("SELECT * FROM exit_permissions WHERE date = ?");
$exit_permissions_stmt->execute([$selected_date]);
$exit_per_map = [];
foreach ($exit_permissions_stmt as $ep) {
    $exit_per_map[$ep['employee_id']][] = $ep;
}

// تفاصيل الإجازات والمغادرات السنوية لكل موظف
$all_leaves_stmt = $pdo->prepare("SELECT * FROM leaves WHERE employee_id=? AND status='approved' AND YEAR(start_date)=? ORDER BY start_date DESC");
$leave_details_map = [];
foreach ($employees as $emp) {
    $all_leaves_stmt->execute([$emp['id'], $current_year]);
    $result = $all_leaves_stmt->fetchAll(PDO::FETCH_ASSOC);
    $leave_details_map[$emp['id']] = $result ? $result : [];
}
$all_exits_stmt = $pdo->prepare("SELECT * FROM exit_permissions WHERE employee_id=? AND YEAR(date)=? ORDER BY date DESC, from_time DESC");
$exit_details_map = [];
foreach ($employees as $emp) {
    $all_exits_stmt->execute([$emp['id'], $current_year]);
    $result = $all_exits_stmt->fetchAll(PDO::FETCH_ASSOC);
    $exit_details_map[$emp['id']] = $result ? $result : [];
}

// دوال حساب الرصيد الفعلي والمستهلك
function get_annual_leave_used($pdo, $emp_id, $year) {
    $stmt = $pdo->prepare("SELECT SUM(DATEDIFF(LEAST(end_date, :year_end), GREATEST(start_date, :year_start)) + 1)
        FROM leaves 
        WHERE employee_id=:emp_id AND status='approved' AND type='سنوية'
        AND ((start_date BETWEEN :year_start AND :year_end) OR (end_date BETWEEN :year_start AND :year_end) OR (:year_start BETWEEN start_date AND end_date))");
    $year_start = "$year-01-01";
    $year_end = "$year-12-31";
    $stmt->execute([
        'emp_id' => $emp_id,
        'year_start' => $year_start,
        'year_end' => $year_end
    ]);
    return intval($stmt->fetchColumn());
}
function get_exit_used($pdo, $emp_id, $year) {
    $stmt = $pdo->prepare("SELECT SUM(
        CASE 
            WHEN type='final' THEN duration_requested
            WHEN type='temporary' THEN IFNULL(duration_real, 0)
            ELSE 0 END
        ) FROM exit_permissions 
        WHERE employee_id=? AND YEAR(date)=? 
        AND ((type='final' AND status='approved') OR (type='temporary' AND status='done'))");
    $stmt->execute([$emp_id, $year]);
    return intval($stmt->fetchColumn());
}
function minutesToHoursAndMinutes($mins) {
    $h = floor($mins / 60);
    $m = $mins % 60;
    if($h > 0) return "$h س $m د";
    return "$m د";
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدارة الإجازات والمغادرات</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Cairo', Tahoma, Arial, sans-serif; background: #f6f7fa;}
        .main-title { color: #007bff; font-weight: bold;}
        .form-section { background: #fff; border-radius: 15px; padding:22px 15px; box-shadow:0 4px 18px #d0dbe850; margin-bottom: 22px;}
        .table { background: #fff;}
        .expand-btn { cursor: pointer; color: #0d6efd; background: none; border: none; font-size: 1.15em; margin-left: 6px; vertical-align: middle;}
        .employee-details-row { display: none; background: #f9f9fd; }
        .details-cell {padding: 14px 20px; vertical-align: top;}
        .mini-table {font-size:.97em;}
        .mini-table th, .mini-table td {padding:4px 8px;}
        .summary-badges {margin: 8px 0; font-size:.97em;}
        .summary-badge {margin-left:7px; background:#f0f6fa; color:#1976d2; border-radius:7px; padding:3px 10px; display:inline-block;}
        .badge-exit { background: #ffe7b6 !important; color: #fff !important; }
        .badge-leave { background: #f6bdbd !important; color: #fff !important; }
        .badge-exit strong, .badge-leave strong { font-weight: bold; }
        @media (max-width: 700px) {
            .form-section, .table { font-size: 0.97em;}
            .main-title { font-size:1.2em;}
        }
        select.form-select { min-width: 110px;}
    </style>
</head>
<body>
<div class="container py-3">
    <h2 class="main-title mb-4 text-center">إدارة الإجازات والمغادرات</h2>
    <form method="post" class="mb-4 text-center">
        <label class="form-label fw-bold">اختر التاريخ:</label>
        <input type="date" name="selected_date" value="<?= htmlspecialchars($selected_date ?? '') ?>" class="form-control d-inline-block" style="max-width:170px">
        <button type="submit" name="select_date" class="btn btn-primary ms-2">عرض</button>
    </form>
    <?php if(isset($msg) && $msg): ?>
        <div class="alert alert-<?= $alert_class ?> text-center"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <!-- نموذج منح مغادرة -->
    <div class="form-section mb-4">
        <form method="post" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label">الموظف</label>
                <select name="exit_employee_id" class="form-select" required>
                    <option value="">اختر...</option>
                    <?php foreach($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name'] ?? '') ?> (<?= htmlspecialchars($emp['emp_code'] ?? '') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">تاريخ المغادرة</label>
                <input type="date" name="exit_date" class="form-control" value="<?= htmlspecialchars($selected_date ?? '') ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">نوع المغادرة</label>
                <select name="exit_type" id="exit_type" class="form-select" required onchange="toggleExitToTime()">
                    <option value="temporary">مغادرة مؤقتة (مع رجوع)</option>
                    <option value="final">مغادرة مع عدم الرجوع</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">وقت الخروج</label>
                <input type="time" name="exit_from_time" class="form-control" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">وقت العودة</label>
                <input type="time" id="exit_to_time" name="exit_to_time" class="form-control">
            </div>
            <div class="col-md-2 d-grid">
                <label class="form-label">&nbsp;</label>
                <button name="grant_exit" class="btn btn-warning text-dark">منح مغادرة <i class="bi bi-box-arrow-right"></i></button>
            </div>
        </form>
        <div class="text-muted mt-2" style="font-size:.95em;">
            <ul>
                <li>المغادرة النهائية: تخصم من وقت الخروج حتى نهاية الشفت فقط.</li>
                <li>المغادرة المؤقتة: تحسب فعلياً من أول بصمة انصراف بعد إذن المغادرة حتى أول حضور بعد الانصراف.</li>
                <li>إذا حضر الموظف قبل انتهاء المدة المقدرة يرجع له المتبقي، وإذا تأخر يخصم منه الفرق.</li>
                <li>لا يمكن منح مغادرة لموظف أكمل دوامه فعلاً.</li>
            </ul>
        </div>
    </div>
    <!-- نموذج منح إجازة -->
    <div class="form-section mb-4">
        <form method="post" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">الموظف</label>
                <select name="leave_employee_id" class="form-select" required>
                    <option value="">اختر...</option>
                    <?php foreach($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name'] ?? '') ?> (<?= htmlspecialchars($emp['emp_code'] ?? '') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">نوع الإجازة</label>
                <select name="leave_type" class="form-select" required>
                    <option value="سنوية">سنوية</option>
                    <option value="مرضية">مرضية</option>
                    <option value="عارضة">عارضة</option>
                    <option value="بدون راتب">بدون راتب</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">من تاريخ</label>
                <input type="date" name="leave_start_date" class="form-control" value="<?= htmlspecialchars($selected_date ?? '') ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">إلى تاريخ</label>
                <input type="date" name="leave_end_date" class="form-control" value="<?= htmlspecialchars($selected_date ?? '') ?>" required>
            </div>
            <div class="col-md-3 d-grid">
                <label class="form-label">&nbsp;</label>
                <button name="grant_leave" class="btn btn-danger">منح إجازة <i class="bi bi-calendar-check"></i></button>
            </div>
        </form>
    </div>
    <!-- جدول ملخص الإجازات والمغادرات لليوم المحدد -->
    <div class="form-section">
        <h5 class="mb-3"><i class="bi bi-calendar-check"></i> إجازات ومغادرات الموظفين في <?= htmlspecialchars($selected_date) ?></h5>
        <table class="table table-bordered table-hover text-center align-middle" id="employees-table">
            <thead class="table-light">
                <tr>
                    <th>الموظف</th>
                    <th>الإجازات اليوم</th>
                    <th>المغادرات اليوم</th>
                    <th>تفاصيل</th>
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
                    <td>
                        <?php
                        if (isset($leave_map[$emp_id])) {
                            foreach($leave_map[$emp_id] as $lv) {
                                echo "<span class='badge badge-leave'><strong>إجازة</strong>";
                                if (!empty($lv['type'])) echo ": " . htmlspecialchars($lv['type'] ?? '');
                                if (($lv['start_date'] ?? '') != ($lv['end_date'] ?? '')) {
                                    echo " <small>(".htmlspecialchars($lv['start_date'] ?? '')." إلى ".htmlspecialchars($lv['end_date'] ?? '').")</small>";
                                }
                                echo "</span> ";
                            }
                        } else {
                            echo "<span class='text-muted'>لا يوجد</span>";
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        if (isset($exit_per_map[$emp_id])) {
                            foreach($exit_per_map[$emp_id] as $ep) {
                                $badgeType = $ep['type'] === 'final' ? 'badge-danger' : 'badge-exit';
                                $status_txt = $ep['type'] === 'temporary'
                                    ? (($ep['status'] ?? '') === 'done' ? 'تمت فعليًا' : 'بانتظار البصمة')
                                    : 'معتمدة';
                                echo "<span class='badge $badgeType'><strong>مغادرة:</strong> ".htmlspecialchars($ep['from_time'] ?? '');
                                if ($ep['type'] == 'temporary' && !empty($ep['to_time'])) {
                                    echo " - ".htmlspecialchars($ep['to_time'] ?? '');
                                } elseif ($ep['type'] == 'final') {
                                    echo " - حتى نهاية الشفت";
                                }
                                echo " <small>(" . intval($ep['duration_requested']) . " د)</small>";
                                if ($ep['type'] == 'temporary' && $ep['duration_real'] !== null) {
                                    echo " <small>فعلي: ".intval($ep['duration_real'])." د</small>";
                                }
                                echo " <span class='text-secondary'>[".($ep['type']=='final'?'نهائية':'مؤقتة')."]</span>";
                                echo " <span class='text-muted'>($status_txt)</span>";
                                echo "</span> ";
                            }
                        } else {
                            echo "<span class='text-muted'>لا يوجد</span>";
                        }
                        ?>
                    </td>
                    <td>
                        <button type="button" class="expand-btn btn btn-sm btn-outline-primary" data-target="<?= $expand_id ?>">
                            <i class="bi bi-chevron-down"></i> عرض كل التفاصيل
                        </button>
                    </td>
                </tr>
                <!-- تفاصيل الإجازات والمغادرات السنوية -->
                <tr class="employee-details-row" id="<?= $expand_id ?>">
                    <td colspan="4" class="details-cell text-start">
                        <?php
                        // رصيد الإجازة والمغادرات من قاعدة البيانات + المستهلك فعلياً + المتبقي
						$emp_code = $emp['emp_code'];
						$leave_total = isset($leave_balances[$emp_code][2]) ? floatval($leave_balances[$emp_code][2]) : 0; // 2=سنوية
						$exit_total = isset($leave_balances[$emp_code][4]) ? floatval($leave_balances[$emp_code][4]) : 0; // 4=مغادرة/خروج
                        $leave_used = get_annual_leave_used($pdo, $emp_id, $current_year);
                        $exit_used = get_exit_used($pdo, $emp_id, $current_year);
                        $leave_remain = max($leave_total - $leave_used, 0);
                        $exit_remain = max($exit_total - $exit_used, 0);
                        ?>
                        <div class="mb-3 summary-badges">
                            <span class="summary-badge">
                                <i class="bi bi-calendar-check"></i>
                                رصيد الإجازة السنوي : 
                                <strong><?= $leave_total ?></strong> /
                                <span style="color:#176b34"><strong><?= $leave_remain ?></strong></span>
                                <span class="text-muted">/ <?= $leave_used ?> مستهلك</span>
                                يوم
                            </span>
                            <span class="summary-badge">
                                <i class="bi bi-arrow-left-right"></i>
                                رصيد المغادرات (د): 
                                <strong><?= $exit_total ?></strong> /
                                <span style="color:#176b34"><strong><?= $exit_remain ?></strong></span>
                                <span class="text-muted">/ <?= $exit_used ?> مستهلك</span>
                                دقيقة
                            </span>
                        </div>
                        <div class="mb-2 fw-bold text-secondary"><i class="bi bi-calendar-event"></i> جميع الإجازات خلال السنة:</div>
                        <div style="max-width:100vw;overflow-x:auto;">
                            <table class="table mini-table table-bordered table-sm mb-2">
                                <thead>
                                    <tr>
                                        <th>النوع</th>
                                        <th>من</th>
                                        <th>إلى</th>
                                        <th>عدد الأيام</th>
                                        <th>الحالة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $leaves = isset($leave_details_map[$emp_id]) ? $leave_details_map[$emp_id] : [];
                                if (count($leaves)):
                                    foreach($leaves as $lv):
                                ?>
                                    <tr>
                                        <td><?= htmlspecialchars($lv['type'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($lv['start_date'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($lv['end_date'] ?? '') ?></td>
                                        <td><?= (new DateTime($lv['start_date'] ?? ''))->diff(new DateTime($lv['end_date'] ?? ''))->days + 1 ?></td>
                                        <td><?= htmlspecialchars($lv['status'] ?? '') ?></td>
                                    </tr>
                                <?php
                                    endforeach;
                                else: ?>
                                    <tr><td colspan="5" class="text-muted">لا يوجد إجازات لهذا الموظف في السنة الحالية.</td></tr>
                                <?php endif;?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mb-2 fw-bold text-secondary"><i class="bi bi-arrow-return-left"></i> جميع المغادرات خلال السنة:</div>
                        <div style="max-width:100vw;overflow-x:auto;">
                            <table class="table mini-table table-bordered table-sm mb-2">
                                <thead>
                                    <tr>
                                        <th>التاريخ</th>
                                        <th>من</th>
                                        <th>إلى</th>
                                        <th>المدة المقدرة</th>
                                        <th>المدة الفعلية</th>
                                        <th>النوع</th>
                                        <th>الحالة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $exits = isset($exit_details_map[$emp_id]) ? $exit_details_map[$emp_id] : [];
                                if (count($exits)):
                                    foreach($exits as $ep):
                                ?>
                                    <tr>
                                        <td><?= htmlspecialchars($ep['date'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($ep['from_time'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($ep['to_time'] ?? '') ?: '-' ?></td>
                                        <td><?= intval($ep['duration_requested']) ?></td>
                                        <td><?= $ep['duration_real'] !== null ? intval($ep['duration_real']) : '-' ?></td>
                                        <td><?= ($ep['type'] ?? '')=='final'?'نهائية':'مؤقتة' ?></td>
                                        <td><?= isset($ep['status']) ? htmlspecialchars($ep['status'] ?? '') : '-' ?></td>
                                    </tr>
                                <?php
                                    endforeach;
                                else: ?>
                                    <tr><td colspan="7" class="text-muted">لا يوجد مغادرات لهذا الموظف في السنة الحالية.</td></tr>
                                <?php endif;?>
                                </tbody>
                            </table>
                        </div>
                    </td>
                </tr>
            <?php endforeach;?>
            <?php if(count($employees)==0): ?>
                <tr><td colspan="4">لا يوجد موظفون نشطون.</td></tr>
            <?php endif;?>
            </tbody>
        </table>
    </div>
</div>
<script>
function toggleExitToTime() {
    var exitType = document.getElementById('exit_type');
    if(!exitType) return;
    var toTime = document.getElementById('exit_to_time');
    if (exitType.value === 'final') {
        toTime.value = '';
        toTime.disabled = true;
        toTime.removeAttribute('required');
    } else {
        toTime.disabled = false;
        toTime.setAttribute('required', 'required');
    }
}
toggleExitToTime();

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
});
</script>
</body>
</html>