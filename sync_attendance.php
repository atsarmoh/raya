<?php
// إعدادات الاتصال بقاعدة بيانات BioTime
$biotime_host = 'localhost';
$biotime_user = 'root';
$biotime_pass = '12341234';
$biotime_db = 'zkb';

// إعدادات الاتصال بقاعدة بيانات نظامك
$local_host = 'localhost';
$local_user = 'root';
$local_pass = '12341234';
$local_db = 'zk_attendance';

// الاتصال بقاعدة بيانات BioTime
$biotime_conn = new mysqli($biotime_host, $biotime_user, $biotime_pass, $biotime_db);
if ($biotime_conn->connect_error) {
    die("فشل الاتصال بقاعدة بيانات BioTime: " . $biotime_conn->connect_error);
}
$biotime_conn->set_charset("utf8mb4");

// الاتصال بقاعدة بيانات نظامك
$local_conn = new mysqli($local_host, $local_user, $local_pass, $local_db);
if ($local_conn->connect_error) {
    die("فشل الاتصال بقاعدة بيانات نظامك: " . $local_conn->connect_error);
}
$local_conn->set_charset("utf8mb4");

// استيراد بيانات الموظفين
$employee_query = "SELECT id, emp_code, first_name, last_name, photo FROM personnel_employee";
$employee_result = $biotime_conn->query($employee_query);

$employees_imported = 0;
$employees_updated = 0;

if ($employee_result && $employee_result->num_rows > 0) {
    while ($row = $employee_result->fetch_assoc()) {
        $emp_code = $row['emp_code'];
        $name = $row['first_name'] . ' ' . $row['last_name'];
        $photo = $row['photo'];
        
        // التحقق من وجود الموظف
        $check_query = "SELECT id FROM employees WHERE emp_code = ?";
        $check_stmt = $local_conn->prepare($check_query);
        $check_stmt->bind_param("s", $emp_code);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            // تحديث الموظف الموجود
            $update_query = "UPDATE employees SET name = ?, photo = ? WHERE emp_code = ?";
            $update_stmt = $local_conn->prepare($update_query);
            $update_stmt->bind_param("sss", $name, $photo, $emp_code);
            $update_stmt->execute();
            $employees_updated++;
        } else {
            // إضافة موظف جديد
            $insert_query = "INSERT INTO employees (emp_code, name, photo) VALUES (?, ?, ?)";
            $insert_stmt = $local_conn->prepare($insert_query);
            $insert_stmt->bind_param("sss", $emp_code, $name, $photo);
            $insert_stmt->execute();
            $employees_imported++;
        }
    }
}

// استيراد سجلات الحضور
$last_record_query = "SELECT MAX(punch_time) as last_time FROM attendance_records";
$last_record_result = $local_conn->query($last_record_query);
$last_record = $last_record_result->fetch_assoc();
$last_time = $last_record['last_time'] ?? '1970-01-01 00:00:00';

$attendance_query = "
    SELECT 
        t.id, 
        t.emp_code, 
        t.punch_time, 
        t.punch_state, 
        t.verify_type, 
        t.terminal_sn, 
        t.terminal_alias
    FROM 
        iclock_transaction t
    WHERE 
        t.punch_time > ?
    ORDER BY 
        t.punch_time ASC
";

$attendance_stmt = $biotime_conn->prepare($attendance_query);
$attendance_stmt->bind_param("s", $last_time);
$attendance_stmt->execute();
$attendance_result = $attendance_stmt->get_result();

$records_imported = 0;
$records_skipped = 0;

if ($attendance_result && $attendance_result->num_rows > 0) {
    while ($row = $attendance_result->fetch_assoc()) {
    $emp_code = $row['emp_code'];
    $punch_time = $row['punch_time'];
    $punch_type = ($row['punch_state'] == '0') ? 'حضور' : 'انصراف';
    $verify_type = $row['verify_type'];
    $terminal_sn = $row['terminal_sn'];
    $terminal_name = $row['terminal_alias'];
    
    // حساب work_date بناء على الساعة 3 فجراً كبداية اليوم
    $dt = new DateTime($punch_time);
    $hour = (int)$dt->format('H');
    $work_date = $dt->format('Y-m-d');
    if ($hour < 3) {
        // إذا البصمة قبل 03:00 صباحا، اعتبرها ليوم أمس
        $dt->modify('-1 day');
        $work_date = $dt->format('Y-m-d');
    }

    // التحقق من عدم وجود السجل مسبقاً (يُنصح أن يشمل work_date)
    $check_query = "SELECT id FROM attendance_records WHERE emp_code = ? AND punch_time = ?";
    $check_stmt = $local_conn->prepare($check_query);
    $check_stmt->bind_param("ss", $emp_code, $punch_time);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows == 0) {
        // إضافة سجل جديد مع work_date
        $insert_query = "INSERT INTO attendance_records 
            (emp_code, punch_time, punch_type, verify_type, terminal_sn, terminal_name, work_date)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
        $insert_stmt = $local_conn->prepare($insert_query);
        $insert_stmt->bind_param("sssisss", $emp_code, $punch_time, $punch_type, $verify_type, $terminal_sn, $terminal_name, $work_date);
        $insert_stmt->execute();
        $records_imported++;
    } else {
        $records_skipped++;
    }
}
}

// إغلاق الاتصالات
$biotime_conn->close();
$local_conn->close();

// عرض النتائج
echo "<h2>نتائج استيراد البيانات</h2>";
echo "<p>تم استيراد {$employees_imported} موظف جديد.</p>";
echo "<p>تم تحديث {$employees_updated} موظف موجود.</p>";
echo "<p>تم استيراد {$records_imported} سجل حضور جديد.</p>";
echo "<p>تم تخطي {$records_skipped} سجل موجود مسبقاً.</p>";
?>
