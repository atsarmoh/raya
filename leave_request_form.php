<?php
session_start();
$emp_code = $_SESSION['emp_code'] ?? '1';
if (!$emp_code) die("يجب تسجيل الدخول أولاً.");

// الاتصال بالداتا بيز
$pdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "12341234");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// جلب أنواع الإجازات/المغادرات
$types = $pdo->query("SELECT id, name, unit FROM leave_types")->fetchAll(PDO::FETCH_ASSOC);

// جلب أرصدة الموظف الحالي للسنة الحالية
$year = date('Y');
$balances = $pdo->prepare("SELECT lb.leave_type_id, lb.balance, lt.unit 
    FROM leave_balances lb 
    JOIN leave_types lt ON lb.leave_type_id=lt.id 
    WHERE lb.emp_code=? AND lb.year=?");
$balances->execute([$emp_code, $year]);
$emp_balances = [];
foreach($balances as $row) {
    $emp_balances[$row['leave_type_id']] = [
        'balance' => $row['balance'],
        'unit' => $row['unit']
    ];
}

// معالجة الطلب
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leave_type_id = $_POST['leave_type_id'];
    $leave_type = array_filter($types, fn($t) => $t['id']==$leave_type_id);
    $leave_type = reset($leave_type);
    $unit = $leave_type['unit'];
    $reason = $_POST['reason'];

    if ($unit == 'days') {
        // طلب إجازة
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $duration = (strtotime($end_date) - strtotime($start_date))/86400 + 1;
        $show_duration = "$duration يوم";
    } else {
        // طلب مغادرة
        $date = $_POST['exit_date'];
        $exit_time = $_POST['exit_time'];
        // جلب بيانات الشفت للموظف في ذلك اليوم (نفترض لديك جدول shift_assignments)
        $shift = $pdo->prepare("SELECT s.end_time FROM shift_assignments sa 
                                JOIN shifts s ON sa.shift_id=s.id 
                                WHERE sa.emp_code=? AND sa.shift_date=?");
        $shift->execute([$emp_code, $date]);
        $shift_end = $shift->fetchColumn();

        if (!$shift_end) $msg = "لم يتم العثور على شفت لهذا اليوم!";
        else {
            // الفرق بالوقت (بالساعات مع الدقائق)
            $exit_dt = strtotime("$date $exit_time");
            $end_dt = strtotime("$date $shift_end");
            $duration = max(0, ($end_dt - $exit_dt)/3600); // ساعات عشرية
            $show_duration = number_format($duration, 2) . " ساعة";
        }
    }

    // التأكد من الرصيد
    $emp_balance = $emp_balances[$leave_type_id]['balance'] ?? 0;
    if ($duration > $emp_balance) {
        $msg = "لا يوجد لديك رصيد كافٍ ($show_duration متاح: $emp_balance)";
    } elseif (!$msg) {
        // إضافة الطلب، دون خصم حتى موافقة الإدارة
        $stmt = $pdo->prepare("INSERT INTO leave_requests 
            (emp_code, leave_type_id, start_date, end_date, duration_days, reason) 
            VALUES (?, ?, ?, ?, ?, ?)");
        if ($unit == 'days')
            $stmt->execute([$emp_code, $leave_type_id, $start_date, $end_date, $duration, $reason]);
        else
            $stmt->execute([$emp_code, $leave_type_id, $date, $date, $duration, $reason]);
        $msg = "تم إرسال الطلب ($show_duration) بنجاح. بانتظار موافقة الإدارة.";
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>طلب إجازة أو مغادرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <script>
        function updateForm() {
            var types = <?php echo json_encode($types); ?>;
            var sel = document.getElementById('leave_type_id');
            var val = sel.value;
            var unit = '';
            for (var i=0; i<types.length; i++) 
                if (types[i].id == val) unit = types[i].unit;
            if (unit == 'days') {
                document.getElementById('leave_days').style.display = '';
                document.getElementById('leave_exit').style.display = 'none';
            } else if (unit == 'hours') {
                document.getElementById('leave_days').style.display = 'none';
                document.getElementById('leave_exit').style.display = '';
            } else {
                document.getElementById('leave_days').style.display = 'none';
                document.getElementById('leave_exit').style.display = 'none';
            }
        }
    </script>
</head>
<body>
<div class="container py-4">
    <h3>طلب إجازة أو مغادرة</h3>
    <?php if($msg): ?><div class="alert alert-info"><?= $msg ?></div><?php endif;?>
    <form method="post" class="form-section">
        <div class="mb-3">
            <label>نوع الطلب</label>
            <select name="leave_type_id" id="leave_type_id" onchange="updateForm()" class="form-select" required>
                <option value="">اختر...</option>
                <?php foreach($types as $type): ?>
                    <option value="<?= $type['id'] ?>">
                        <?= htmlspecialchars($type['name']) ?>
                        (رصيدك: <?= $emp_balances[$type['id']]['balance'] ?? 0 ?>
                        <?= $type['unit']=='days'?'يوم':'ساعة' ?>)
                    </option>
                <?php endforeach;?>
            </select>
        </div>
        <div id="leave_days" style="display:none;">
            <div class="mb-3">
                <label>من تاريخ</label>
                <input type="date" name="start_date" class="form-control">
            </div>
            <div class="mb-3">
                <label>إلى تاريخ</label>
                <input type="date" name="end_date" class="form-control">
            </div>
        </div>
        <div id="leave_exit" style="display:none;">
            <div class="mb-3">
                <label>تاريخ المغادرة</label>
                <input type="date" name="exit_date" class="form-control">
            </div>
            <div class="mb-3">
                <label>وقت المغادرة</label>
                <input type="time" name="exit_time" class="form-control">
            </div>
        </div>
        <div class="mb-3">
            <label>السبب</label>
            <textarea name="reason" class="form-control"></textarea>
        </div>
        <button class="btn btn-primary">إرسال الطلب</button>
    </form>
</div>
<script>updateForm();</script>
</body>
</html>