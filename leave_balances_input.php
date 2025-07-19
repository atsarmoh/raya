<?php
session_start();
$pdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "12341234");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// جلب أنواع الإجازات
$leave_types = $pdo->query("SELECT id, name, unit FROM leave_types")->fetchAll(PDO::FETCH_ASSOC);

// جلب الموظفين مع بيانات القسم
$employees = $pdo->query("SELECT emp_code, name, department FROM employees WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// السنة الحالية
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// جلب أرصدة الموظفين
$balances = $pdo->prepare("SELECT * FROM leave_balances WHERE year=?");
$balances->execute([$year]);
$balances_data = [];
foreach($balances as $row) {
    $balances_data[$row['emp_code']][$row['leave_type_id']] = $row['balance'];
}

$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $year = intval($_POST['year']);
    foreach ($_POST['balance'] as $emp_code => $types) {
        foreach ($types as $leave_type_id => $balance_val) {
            $balance_val = floatval($balance_val);
            // تحقق إذا يوجد رصيد مسبق
            $check = $pdo->prepare("SELECT id FROM leave_balances WHERE emp_code=? AND leave_type_id=? AND year=?");
            $check->execute([$emp_code, $leave_type_id, $year]);
            if ($check->fetchColumn()) {
                // تحديث
                $update = $pdo->prepare("UPDATE leave_balances SET balance=? WHERE emp_code=? AND leave_type_id=? AND year=?");
                $update->execute([$balance_val, $emp_code, $leave_type_id, $year]);
            } else {
                // إدراج
                $insert = $pdo->prepare("INSERT INTO leave_balances (emp_code, leave_type_id, year, balance) VALUES (?, ?, ?, ?)");
                $insert->execute([$emp_code, $leave_type_id, $year, $balance_val]);
            }
        }
    }
    $msg = "تم تحديث/إضافة الأرصدة بنجاح!";
    // إعادة تحميل الأرصدة بعد التحديث
    $balances = $pdo->prepare("SELECT * FROM leave_balances WHERE year=?");
    $balances->execute([$year]);
    $balances_data = [];
    foreach($balances as $row) {
        $balances_data[$row['emp_code']][$row['leave_type_id']] = $row['balance'];
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدارة أرصدة الموظفين</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <style>
        .table-scroll { max-height: 60vh; overflow-y: auto; }
        th,td { vertical-align: middle !important;}
        .emp-search {margin-bottom:1rem;}
    </style>
    <script>
    function filterTable() {
        var input = document.getElementById("empSearch");
        var filter = input.value.toUpperCase();
        var trs = document.getElementById("empTable").getElementsByTagName("tr");
        for (var i = 1; i < trs.length; i++) {
            var td = trs[i].getElementsByTagName("td")[0];
            if (td) {
                trs[i].style.display = td.textContent.toUpperCase().indexOf(filter) > -1 ? "" : "none";
            }
        }
    }
    </script>
</head>
<body>
<div class="container py-4">
    <h3 class="mb-3">إدارة أرصدة الموظفين لكل نوع إجازة</h3>
    <?php if($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif;?>
    <form method="get" class="row g-3 mb-3">
        <div class="col-auto">
            <label class="form-label">السنة</label>
            <input type="number" name="year" value="<?= $year ?>" class="form-control" style="width:100px;">
        </div>
        <div class="col-auto">
            <button class="btn btn-secondary" type="submit">عرض السنة</button>
        </div>
    </form>
    <div class="emp-search">
        <input type="text" id="empSearch" onkeyup="filterTable()" placeholder="ابحث باسم الموظف..." class="form-control" style="max-width:300px;">
    </div>
    <form method="post">
        <input type="hidden" name="year" value="<?= $year ?>">
        <div class="table-responsive table-scroll">
            <table class="table table-bordered align-middle" id="empTable">
                <thead class="table-light">
                    <tr>
                        <th>اسم الموظف</th>
                        <th>القسم</th>
                        <?php foreach($leave_types as $lt): ?>
                            <th><?= htmlspecialchars($lt['name']) ?><br><small>(<?= $lt['unit']=='days'?'أيام':'ساعات' ?>)</small></th>
                        <?php endforeach;?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($employees as $emp): ?>
                        <tr>
                            <td><?= htmlspecialchars($emp['name']) ?></td>
                            <td><?= htmlspecialchars($emp['department']) ?></td>
                            <?php foreach($leave_types as $lt): 
                                $bal = $balances_data[$emp['emp_code']][$lt['id']] ?? '';
                            ?>
                                <td>
                                    <input type="number" step="0.01" min="0" name="balance[<?= $emp['emp_code'] ?>][<?= $lt['id'] ?>]" value="<?= $bal ?>" class="form-control" style="width:90px;">
                                </td>
                            <?php endforeach;?>
                        </tr>
                    <?php endforeach;?>
                </tbody>
            </table>
        </div>
        <button class="btn btn-success mt-3">حفظ الأرصدة</button>
    </form>
</div>
</body>
</html>