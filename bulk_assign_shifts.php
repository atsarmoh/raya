<?php
$pdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "12341234");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// جلب الشفتات
$shifts = $pdo->query("SELECT * FROM shifts")->fetchAll(PDO::FETCH_ASSOC);
// جلب الموظفين النشطين
$employees = $pdo->query("SELECT * FROM employees WHERE is_active=1 ORDER BY department, name")->fetchAll(PDO::FETCH_ASSOC);

// تحديد الشهر الحالي أو من المستخدم
$selected_month = $_GET['month'] ?? date('Y-m');
$year = date('Y', strtotime($selected_month . '-01'));
$month = date('m', strtotime($selected_month . '-01'));
$last_day = date('t', strtotime($selected_month . '-01'));

// عند الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assigned = $_POST['assign'] ?? [];
    foreach ($assigned as $emp_id => $days) {
        foreach ($days as $day => $shift_id) {
            $shift_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            if ($shift_id) {
                // جلب أو إنشاء shift_day_id (بناءً على الشفت واليوم الفعلي في الأسبوع)
                $day_of_week = date('l', strtotime($shift_date));
                $stmt = $pdo->prepare("SELECT id FROM shift_days WHERE shift_id=? AND day_of_week=?");
                $stmt->execute([$shift_id, $day_of_week]);
                $shift_day_id = $stmt->fetchColumn();
                if (!$shift_day_id) {
                    $pdo->prepare("INSERT INTO shift_days (shift_id, day_of_week) VALUES (?, ?)")->execute([$shift_id, $day_of_week]);
                    $shift_day_id = $pdo->lastInsertId();
                }
                // حذف أي توزيع قديم لنفس الموظف في نفس اليوم
                $pdo->prepare("DELETE FROM shift_assignments WHERE employee_id=? AND shift_date=?")->execute([$emp_id, $shift_date]);
                // إضافة التوزيع الجديد
                $pdo->prepare("INSERT INTO shift_assignments (employee_id, shift_day_id, shift_date) VALUES (?, ?, ?)")->execute([$emp_id, $shift_day_id, $shift_date]);
            } else {
                // إذا لم يحدد شفت احذف أي توزيع سابق لهذا اليوم
                $pdo->prepare("DELETE FROM shift_assignments WHERE employee_id=? AND shift_date=?")->execute([$emp_id, $shift_date]);
            }
        }
    }
    header("Location: bulk_assign_shifts.php?month=$selected_month&msg=success");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إسناد شفتات شهرية للموظفين</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { 
            font-family: 'Cairo', Tahoma, Arial, sans-serif; 
            background: #f5f5f5;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }
        .page-container {
            background: white;
            margin: 20px auto;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 95%;
        }
        .header-section {
            background: #2c3e50;
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }
        .header-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="20" cy="20" r="1" fill="white" opacity="0.1"/><circle cx="80" cy="40" r="1" fill="white" opacity="0.1"/><circle cx="40" cy="80" r="1" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
        }
        .main-title {
            font-size: 2.2rem;
            font-weight: 700;
            margin: 0;
            position: relative;
            z-index: 1;
        }
        .month-selector {
            background: rgba(255,255,255,0.15);
            border-radius: 8px;
            padding: 15px;
            margin: 20px auto 0;
            max-width: 300px;
            position: relative;
            z-index: 1;
        }
        .month-selector input {
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 8px 12px;
            font-size: 1rem;
            width: 180px;
            text-align: center;
        }
        .alert {
            margin: 20px;
            border-radius: 4px;
            border: 1px solid #d1ecf1;
            box-shadow: none;
            background-color: #d1ecf1;
            color: #0c5460;
        }
        .table-container {
            position: relative;
            height: calc(100vh - 400px);
            min-height: 500px;
            margin: 20px;
            margin-bottom: 100px;
            border-radius: 4px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #dee2e6;
        }
        .table-wrapper {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            overflow: auto;
        }
        .main-table {
            width: 100%;
            margin: 0;
            border-collapse: separate;
            border-spacing: 0;
            background: white;
        }
        /* تثبيت الهيدر */
        thead th {
            position: sticky;
            top: 0;
            z-index: 100;
            background: #f8f9fa;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .table-header th {
            padding: 12px 8px;
            text-align: center;
            vertical-align: middle;
            border: 1px solid #dee2e6;
            font-weight: 600;
            color: #495057;
            min-width: 90px;
            background: #f8f9fa;
        }
        /* فقط عمود الاسم ثابت */
        .sticky-name-col {
            position: sticky;
            right: 0;
            z-index: 50;
            background: #ffffff;
            min-width: 180px !important;
            max-width: 180px;
            border-right: 2px solid #dee2e6;
        }
        .employee-name {
            font-weight: 600;
            color: #495057;
            text-align: right;
            padding-right: 15px;
        }
        .employee-code {
            font-weight: 500;
            color: #6c757d;
            font-family: 'Courier New', monospace;
            text-align: center;
        }
        .date-header {
            min-width: 90px !important;
            text-align: center;
            background: #f8f9fa;
        }
        .date-number {
            font-size: 1rem;
            font-weight: 600;
            color: #495057;
        }
        .date-day {
            font-size: 0.75rem;
            color: #6c757d;
            margin-top: 2px;
        }
        .data-row:nth-child(even) {
            background: #f8f9fa;
        }
        .data-row:hover {
            background: #e9ecef;
            transition: background-color 0.2s ease;
        }
        .data-cell {
            padding: 8px;
            border: 1px solid #dee2e6;
            text-align: center;
            vertical-align: middle;
            min-width: 90px;
        }
        .shift-select {
            width: 100%;
            min-width: 80px;
            padding: 4px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            background: white;
            font-size: 0.85rem;
            transition: border-color 0.2s ease;
        }
        .shift-select:focus {
            border-color: #495057;
            box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.25);
            outline: none;
        }
        .shift-select:hover {
            border-color: #6c757d;
        }
        .bottom-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #495057;
            padding: 15px 30px;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            z-index: 1050;
            border-top: 1px solid #dee2e6;
        }
        .save-btn {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 4px;
            font-weight: 500;
            font-size: 1rem;
            transition: background-color 0.2s ease;
        }
        .save-btn:hover {
            background: #0056b3;
        }
        .save-btn:active {
            background: #004085;
        }
        .progress-indicator {
            display: none;
            color: white;
            margin-right: 15px;
        }
        @media (max-width: 768px) {
            .page-container {
                margin: 10px;
                border-radius: 4px;
            }
            .header-section {
                padding: 20px;
            }
            .main-title {
                font-size: 1.5rem;
            }
            .table-container {
                height: calc(100vh - 350px);
                margin: 10px;
                margin-bottom: 80px;
            }
            .sticky-name-col {
                min-width: 140px !important;
            }
            .bottom-bar {
                padding: 10px 15px;
            }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .table-wrapper::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        .table-wrapper::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        .table-wrapper::-webkit-scrollbar-thumb {
            background: #6c757d;
            border-radius: 4px;
        }
        .table-wrapper::-webkit-scrollbar-thumb:hover {
            background: #495057;
        }
    </style>
</head>
<body>
    <div class="page-container fade-in">
        <!-- القسم العلوي -->
        <div class="header-section">
            <h1 class="main-title">
                <i class="fas fa-calendar-alt me-3"></i>
                إسناد شفتات شهرية للموظفين
            </h1>
            <?php if(isset($_GET['msg'])): ?>
                <div class="alert alert-success text-center">
                    <i class="fas fa-check-circle me-2"></i>
                    تم الحفظ بنجاح!
                </div>
            <?php endif; ?>
            <div class="month-selector">
                <form method="get">
                    <label class="text-white">
                        <i class="fas fa-calendar me-2"></i>
                        اختر الشهر: 
                        <input type="month" 
                               name="month" 
                               value="<?= htmlspecialchars($selected_month) ?>" 
                               onchange="this.form.submit()" 
                               class="form-control">
                    </label>
                </form>
            </div>
        </div>
        <!-- جدول البيانات -->
        <form method="post">
            <div class="table-container">
                <div class="table-wrapper">
                    <table class="main-table">
                        <thead class="table-header">
                            <tr>
                                <th style="z-index:2000" class="sticky-name-col">
                                    <i class="fas fa-user me-2"></i>
                                    اسم الموظف
                                </th>
                                <th class="employee-code">
                                    <i class="fas fa-id-badge me-2"></i>
                                    الرقم
                                </th>
                                <?php
                                for ($d = 1; $d <= $last_day; $d++):
                                    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                                    $day_ar = [
                                        'Saturday' => 'السبت',
                                        'Sunday' => 'الأحد',
                                        'Monday' => 'الاثنين',
                                        'Tuesday' => 'الثلاثاء',
                                        'Wednesday' => 'الأربعاء',
                                        'Thursday' => 'الخميس',
                                        'Friday' => 'الجمعة'
                                    ];
                                    $dow = date('l', strtotime($date_str));
                                    $is_friday = ($dow === 'Friday');
                                ?>
                                <th class="date-header" style="<?= $is_friday ? 'background: #fff5f5; color: #d63384;' : '' ?>">
                                    <div class="date-number"><?= $d ?></div>
                                    <div class="date-day"><?= $day_ar[$dow] ?></div>
                                </th>
                                <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $emp): ?>
                            <tr class="data-row">
                                <td class="data-cell sticky-name-col employee-name">
                                    <i class="fas fa-user-circle me-2 text-primary"></i>
                                    <?= htmlspecialchars($emp['name']) ?>
                                </td>
                                <td class="data-cell employee-code">
                                    <?= htmlspecialchars($emp['emp_code']) ?>
                                </td>
                                <?php
                                for ($d = 1; $d <= $last_day; $d++):
                                    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                                    $current_shift = $pdo->prepare("SELECT s.id, s.name, s.shift_type, s.start_time, s.end_time FROM shift_assignments a JOIN shift_days sd ON a.shift_day_id=sd.id JOIN shifts s ON sd.shift_id=s.id WHERE a.employee_id=? AND a.shift_date=?");
                                    $current_shift->execute([$emp['id'], $date_str]);
                                    $cur_row = $current_shift->fetch(PDO::FETCH_ASSOC);
                                    $cur_shift_id = $cur_row['id'] ?? null;
                                    $dow = date('l', strtotime($date_str));
                                    $is_friday = ($dow === 'Friday');
                                ?>
                                <td class="data-cell" style="<?= $is_friday ? 'background: #fff5f5;' : '' ?>">
                                    <select name="assign[<?= $emp['id'] ?>][<?= $d ?>]" class="shift-select">
                                        <option value="">--</option>
                                        <?php foreach($shifts as $sh): ?>
                                            <option value="<?= $sh['id'] ?>" <?= $cur_shift_id == $sh['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($sh['name']) ?>
                                                <?php
                                                if($sh['shift_type'] == 'fixed' && $sh['start_time'] && $sh['end_time']): ?>
                                                    (<?= substr($sh['start_time'], 0, 5) ?>-<?= substr($sh['end_time'], 0, 5) ?>)
                                                <?php elseif($sh['shift_type'] == 'flexible_period' && $sh['start_time'] && $sh['end_time']): ?>
                                                    (مرن <?= substr($sh['start_time'], 0, 5) ?>-<?= substr($sh['end_time'], 0, 5) ?>)
                                                <?php elseif($sh['shift_type'] == 'open'): ?>
                                                    (غير محدد)
                                                <?php else: ?>
                                                    (مرن)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <?php endfor; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>
    </div>
    <!-- شريط الحفظ السفلي -->
    <div class="bottom-bar">
        <div class="d-flex justify-content-between align-items-center">
            <div class="progress-indicator">
                <i class="fas fa-spinner fa-spin me-2"></i>
                جاري الحفظ...
            </div>
            <button type="submit" class="save-btn" onclick="showProgress()">
                <i class="fas fa-save me-2"></i>
                حفظ التوزيع الشهري
            </button>
        </div>
    </div>
    <script>
        // إظهار مؤشر التقدم عند الحفظ
        function showProgress() {
            document.querySelector('.progress-indicator').style.display = 'block';
            document.querySelector('.save-btn').disabled = true;
        }
        // ربط زر الحفظ بالنموذج
        document.querySelector('.save-btn').addEventListener('click', function(e) {
            e.preventDefault();
            showProgress();
            document.querySelector('form[method="post"]').submit();
        });
        // تطبيق اللون الأولي للخيارات المحددة
        document.querySelectorAll('.shift-select').forEach(select => {
            select.addEventListener('change', function() {
                this.style.background = this.value ? '#e8f5e8' : 'white';
            });
            // تطبيق اللون الأولي
            if (select.value) {
                select.style.background = '#e8f5e8';
            }
        });
        // حفظ موقع التمرير
        const tableWrapper = document.querySelector('.table-wrapper');
        let scrollTimeout;
        tableWrapper.addEventListener('scroll', function() {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(() => {
                localStorage.setItem('tableScrollTop', this.scrollTop);
                localStorage.setItem('tableScrollLeft', this.scrollLeft);
            }, 100);
        });
        // استعادة موقع التمرير
        window.addEventListener('load', function() {
            const scrollTop = localStorage.getItem('tableScrollTop');
            const scrollLeft = localStorage.getItem('tableScrollLeft');
            if (scrollTop) tableWrapper.scrollTop = parseInt(scrollTop);
            if (scrollLeft) tableWrapper.scrollLeft = parseInt(scrollLeft);
        });
        // إضافة تأثير بسيط للصفوف
        document.querySelectorAll('.data-row').forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.backgroundColor = '#e9ecef';
            });
            row.addEventListener('mouseleave', function() {
                // العودة للون الأصلي حسب ترقيم الصف
                if (this.rowIndex % 2 === 0) {
                    this.style.backgroundColor = '#f8f9fa';
                } else {
                    this.style.backgroundColor = 'white';
                }
            });
        });
    </script>
</body>
</html>