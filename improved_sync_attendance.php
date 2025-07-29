<?php
// نسخة محسنة من sync_attendance.php مع معالجة أفضل للأخطاء

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

try {
    // الاتصال بقاعدة بيانات BioTime
    $biotime_conn = new mysqli($biotime_host, $biotime_user, $biotime_pass, $biotime_db);
    if ($biotime_conn->connect_error) {
        throw new Exception("فشل الاتصال بقاعدة بيانات BioTime: " . $biotime_conn->connect_error);
    }
    $biotime_conn->set_charset("utf8mb4");

    // الاتصال بقاعدة بيانات نظامك
    $local_conn = new mysqli($local_host, $local_user, $local_pass, $local_db);
    if ($local_conn->connect_error) {
        throw new Exception("فشل الاتصال بقاعدة بيانات نظامك: " . $local_conn->connect_error);
    }
    $local_conn->set_charset("utf8mb4");

    // بدء المعاملة
    $local_conn->autocommit(FALSE);

    // جلب إعدادات النظام
    $settings_query = "SELECT setting_key, setting_value FROM system_settings";
    $settings_result = $local_conn->query($settings_query);
    $settings = [];
    if ($settings_result) {
        while ($row = $settings_result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    $work_day_start_hour = isset($settings['work_day_start_hour']) ? (int)$settings['work_day_start_hour'] : 3;

    // استيراد بيانات الموظفين مع معالجة أفضل للأخطاء
    $employee_query = "SELECT id, emp_code, first_name, last_name, photo FROM personnel_employee WHERE emp_code IS NOT NULL AND emp_code != ''";
    $employee_result = $biotime_conn->query($employee_query);

    $employees_imported = 0;
    $employees_updated = 0;
    $employee_errors = 0;

    if ($employee_result && $employee_result->num_rows > 0) {
        while ($row = $employee_result->fetch_assoc()) {
            try {
                $emp_code = trim($row['emp_code']);
                if (empty($emp_code)) continue;
                
                $name = trim($row['first_name'] . ' ' . $row['last_name']);
                $photo = $row['photo'];
                
                // التحقق من وجود الموظف
                $check_query = "SELECT id FROM employees WHERE emp_code = ?";
                $check_stmt = $local_conn->prepare($check_query);
                if (!$check_stmt) {
                    throw new Exception("خطأ في إعداد استعلام التحقق من الموظف: " . $local_conn->error);
                }
                
                $check_stmt->bind_param("s", $emp_code);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    // تحديث الموظف الموجود
                    $update_query = "UPDATE employees SET name = ?, photo = ?, updated_at = NOW() WHERE emp_code = ?";
                    $update_stmt = $local_conn->prepare($update_query);
                    if ($update_stmt) {
                        $update_stmt->bind_param("sss", $name, $photo, $emp_code);
                        if ($update_stmt->execute()) {
                            $employees_updated++;
                        }
                    }
                } else {
                    // إضافة موظف جديد
                    $insert_query = "INSERT INTO employees (emp_code, name, photo, is_active, created_at) VALUES (?, ?, ?, 1, NOW())";
                    $insert_stmt = $local_conn->prepare($insert_query);
                    if ($insert_stmt) {
                        $insert_stmt->bind_param("sss", $emp_code, $name, $photo);
                        if ($insert_stmt->execute()) {
                            $employees_imported++;
                        }
                    }
                }
            } catch (Exception $e) {
                $employee_errors++;
                // تسجيل الخطأ
                error_log("خطأ في استيراد الموظف {$row['emp_code']}: " . $e->getMessage());
            }
        }
    }

    // استيراد سجلات الحضور مع معالجة محسنة
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
            AND t.emp_code IS NOT NULL 
            AND t.emp_code != ''
        ORDER BY 
            t.punch_time ASC
        LIMIT 1000
    ";

    $attendance_stmt = $biotime_conn->prepare($attendance_query);
    if (!$attendance_stmt) {
        throw new Exception("خطأ في إعداد استعلام الحضور: " . $biotime_conn->error);
    }
    
    $attendance_stmt->bind_param("s", $last_time);
    $attendance_stmt->execute();
    $attendance_result = $attendance_stmt->get_result();

    $records_imported = 0;
    $records_skipped = 0;
    $record_errors = 0;

    if ($attendance_result && $attendance_result->num_rows > 0) {
        while ($row = $attendance_result->fetch_assoc()) {
            try {
                $emp_code = trim($row['emp_code']);
                if (empty($emp_code)) continue;
                
                $punch_time = $row['punch_time'];
                $punch_type = ($row['punch_state'] == '0') ? 'حضور' : 'انصراف';
                $verify_type = $row['verify_type'] ?? 1;
                $terminal_sn = $row['terminal_sn'] ?? '';
                $terminal_name = $row['terminal_alias'] ?? '';
                
                // حساب work_date بناء على ساعة بداية اليوم المحددة في الإعدادات
                $dt = new DateTime($punch_time);
                $hour = (int)$dt->format('H');
                $work_date = $dt->format('Y-m-d');
                if ($hour < $work_day_start_hour) {
                    $dt->modify('-1 day');
                    $work_date = $dt->format('Y-m-d');
                }

                // التحقق من عدم وجود السجل مسبقاً
                $check_query = "SELECT id FROM attendance_records WHERE emp_code = ? AND punch_time = ?";
                $check_stmt = $local_conn->prepare($check_query);
                if (!$check_stmt) {
                    throw new Exception("خطأ في إعداد استعلام التحقق من السجل");
                }
                
                $check_stmt->bind_param("ss", $emp_code, $punch_time);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows == 0) {
                    // التحقق من وجود الموظف في النظام
                    $emp_check = "SELECT id FROM employees WHERE emp_code = ?";
                    $emp_check_stmt = $local_conn->prepare($emp_check);
                    $emp_check_stmt->bind_param("s", $emp_code);
                    $emp_check_stmt->execute();
                    $emp_exists = $emp_check_stmt->get_result()->num_rows > 0;
                    
                    if ($emp_exists) {
                        // إضافة سجل جديد
                        $insert_query = "INSERT INTO attendance_records 
                            (emp_code, punch_time, punch_type, verify_type, terminal_sn, terminal_name, work_date, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                        $insert_stmt = $local_conn->prepare($insert_query);
                        if ($insert_stmt) {
                            $insert_stmt->bind_param("sssisss", $emp_code, $punch_time, $punch_type, $verify_type, $terminal_sn, $terminal_name, $work_date);
                            if ($insert_stmt->execute()) {
                                $records_imported++;
                            }
                        }
                    } else {
                        // تسجيل خطأ: موظف غير موجود
                        $error_stmt = $local_conn->prepare("INSERT INTO sync_errors (error_type, error_message, emp_code, punch_time) VALUES (?, ?, ?, ?)");
                        if ($error_stmt) {
                            $error_type = "employee_not_found";
                            $error_message = "الموظف غير موجود في النظام";
                            $error_stmt->bind_param("ssss", $error_type, $error_message, $emp_code, $punch_time);
                            $error_stmt->execute();
                        }
                        $record_errors++;
                    }
                } else {
                    $records_skipped++;
                }
            } catch (Exception $e) {
                $record_errors++;
                // تسجيل الخطأ
                $error_stmt = $local_conn->prepare("INSERT INTO sync_errors (error_type, error_message, emp_code, punch_time) VALUES (?, ?, ?, ?)");
                if ($error_stmt) {
                    $error_type = "sync_error";
                    $error_message = $e->getMessage();
                    $emp_code_err = $row['emp_code'] ?? '';
                    $punch_time_err = $row['punch_time'] ?? '';
                    $error_stmt->bind_param("ssss", $error_type, $error_message, $emp_code_err, $punch_time_err);
                    $error_stmt->execute();
                }
                error_log("خطأ في استيراد سجل الحضور: " . $e->getMessage());
            }
        }
    }

    // تأكيد المعاملة
    $local_conn->commit();

    // إغلاق الاتصالات
    $biotime_conn->close();
    $local_conn->close();

    // عرض النتائج
    echo "<div style='font-family: Arial; direction: rtl; text-align: right; padding: 20px;'>";
    echo "<h2 style='color: #28a745;'>✅ نتائج استيراد البيانات</h2>";
    echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<h3>الموظفين:</h3>";
    echo "<p>✅ تم استيراد <strong>{$employees_imported}</strong> موظف جديد.</p>";
    echo "<p>🔄 تم تحديث <strong>{$employees_updated}</strong> موظف موجود.</p>";
    if ($employee_errors > 0) {
        echo "<p style='color: #dc3545;'>❌ حدثت أخطاء في <strong>{$employee_errors}</strong> موظف.</p>";
    }
    echo "</div>";
    
    echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<h3>سجلات الحضور:</h3>";
    echo "<p>✅ تم استيراد <strong>{$records_imported}</strong> سجل حضور جديد.</p>";
    echo "<p>⏭️ تم تخطي <strong>{$records_skipped}</strong> سجل موجود مسبقاً.</p>";
    if ($record_errors > 0) {
        echo "<p style='color: #dc3545;'>❌ حدثت أخطاء في <strong>{$record_errors}</strong> سجل.</p>";
        echo "<p><a href='view_sync_errors.php' style='color: #007bff;'>عرض تفاصيل الأخطاء</a></p>";
    }
    echo "</div>";
    
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0; border: 1px solid #c3e6cb;'>";
    echo "<p style='margin: 0; color: #155724;'><strong>✅ تمت عملية المزامنة بنجاح!</strong></p>";
    echo "<p style='margin: 5px 0 0 0; color: #155724;'>وقت المزامنة: " . date('Y-m-d H:i:s') . "</p>";
    echo "</div>";
    echo "</div>";

} catch (Exception $e) {
    // في حالة حدوث خطأ، التراجع عن المعاملة
    if (isset($local_conn)) {
        $local_conn->rollback();
        $local_conn->close();
    }
    if (isset($biotime_conn)) {
        $biotime_conn->close();
    }
    
    echo "<div style='font-family: Arial; direction: rtl; text-align: right; padding: 20px;'>";
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; border: 1px solid #f5c6cb; color: #721c24;'>";
    echo "<h2>❌ خطأ في عملية المزامنة</h2>";
    echo "<p><strong>تفاصيل الخطأ:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>الوقت:</strong> " . date('Y-m-d H:i:s') . "</p>";
    echo "</div>";
    echo "</div>";
    
    // تسجيل الخطأ في ملف اللوج
    error_log("خطأ في مزامنة البصمة: " . $e->getMessage());
}
?>