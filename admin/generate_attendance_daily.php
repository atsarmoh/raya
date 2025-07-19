<?php
// إعداد الاتصال بقاعدة البيانات
$pdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "12341234");

// الشهر المطلوب
$month = $_GET['month'] ?? date("Y-m");
$first_day = "$month-01";
$last_day = date("Y-m-t", strtotime($first_day));

// جلب جميع الموظفين النشطين
$employees = $pdo->query("SELECT * FROM employees WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC);

foreach ($employees as $emp) {
    $emp_code = $emp['emp_code'];
    // جلب توزيع الشفتات لهذا الموظف
    $shifts = $pdo->prepare(
        "SELECT sa.shift_date, s.start_time, s.end_time, s.tolerance_minutes
         FROM shift_assignments sa
         JOIN shift_days sd ON sa.shift_day_id=sd.id
         JOIN shifts s ON sd.shift_id=s.id
         WHERE sa.employee_id=? AND sa.shift_date BETWEEN ? AND ?");
    $shifts->execute([$emp['id'], $first_day, $last_day]);

    foreach ($shifts as $shift) {
        $date = $shift['shift_date'];
        $start_time = $shift['start_time'];
        $end_time = $shift['end_time'];
        $allowed_late = intval($shift['tolerance_minutes']);

        // جلب أول بصمة حضور وآخر بصمة انصراف
        $rec = $pdo->prepare("SELECT MIN(punch_time) as first_in, MAX(punch_time) as last_out
            FROM attendance_records
            WHERE emp_code=? AND work_date=?");
        $rec->execute([$emp_code, $date]);
        $row = $rec->fetch(PDO::FETCH_ASSOC);

        $present = $row['first_in'] ? 1 : 0;
        $absent = $present ? 0 : 1;
        $late = 0;
        $late_duration = 0;
        $early_leave = 0;
        $early_leave_duration = 0;

        if ($present) {
            // حساب التأخير
            if ($start_time && $row['first_in']) {
                $sched = strtotime("$date $start_time");
                $actual = strtotime($row['first_in']);
                if ($actual > $sched + $allowed_late * 60) {
                    $late = 1;
                    $late_duration = intval(($actual - $sched)/60);
                }
            }
            // حساب الخروج المبكر
            if ($end_time && $row['last_out']) {
                $sched_end = strtotime("$date $end_time");
                $actual_out = strtotime($row['last_out']);
                if ($actual_out < $sched_end) {
                    $early_leave = 1;
                    $early_leave_duration = intval(($sched_end - $actual_out)/60);
                }
            }
        }

        // تحقق إذا كان يوجد صف مسبق
        $exists = $pdo->prepare("SELECT id FROM attendance_daily_details WHERE emp_code=? AND work_date=?");
        $exists->execute([$emp_code, $date]);
        if ($exists->fetchColumn()) {
            // تحديث
            $stmt = $pdo->prepare("UPDATE attendance_daily_details SET present=?, first_in=?, last_out=?, late=?, late_duration=?, early_leave=?, early_leave_duration=?, absent=? WHERE emp_code=? AND work_date=?");
            $stmt->execute([$present, $row['first_in'], $row['last_out'], $late, $late_duration, $early_leave, $early_leave_duration, $absent, $emp_code, $date]);
        } else {
            // إدراج جديد
            $stmt = $pdo->prepare("INSERT INTO attendance_daily_details (emp_code, work_date, present, first_in, last_out, late, late_duration, early_leave, early_leave_duration, absent) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$emp_code, $date, $present, $row['first_in'], $row['last_out'], $late, $late_duration, $early_leave, $early_leave_duration, $absent]);
        }
    }
}
echo "تم توليد/تحديث الحضور اليومي بنجاح!";
?>