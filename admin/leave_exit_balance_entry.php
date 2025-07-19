<?php
$pdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "12341234");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$employees = $pdo->query("SELECT id, name, emp_code FROM employees WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$current_year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
$years = [];
for($y = $current_year-2; $y <= $current_year+1; $y++) $years[] = $y;

// إضافة أو تحديث رصيد
$msg = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_balance'])) {
    $emp_id = intval($_POST['employee_id']);
    $year = intval($_POST['year']);
    $leave_balance = intval($_POST['leave_balance']);
    $exit_balance = intval($_POST['exit_balance']);

    $check = $pdo->prepare("SELECT id FROM balances WHERE employee_id=? AND year=?");
    $check->execute([$emp_id, $year]);
    if ($check->fetch()) {
        $pdo->prepare("UPDATE balances SET leave_balance=?, exit_balance=? WHERE employee_id=? AND year=?")
            ->execute([$leave_balance, $exit_balance, $emp_id, $year]);
        $msg = "تم تحديث الرصيد بنجاح.";
        $success = true;
    } else {
        $pdo->prepare("INSERT INTO balances (employee_id, year, leave_balance, exit_balance) VALUES (?, ?, ?, ?)")
            ->execute([$emp_id, $year, $leave_balance, $exit_balance]);
        $msg = "تم إدخال الرصيد بنجاح.";
        $success = true;
    }
}

// جلب أرصدة جميع السنوات للعرض
$balances = [];
$res = $pdo->query("SELECT * FROM balances");
foreach($res as $row) {
    $balances[$row['employee_id']][$row['year']] = $row;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدخال رصيد الإجازات والمغادرات السنوي</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap 5 RTL + Google Fonts Cairo -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(120deg, #e0e7ff 0%, #f1f5f9 100%);
            font-family: 'Cairo', Tahoma, Arial, sans-serif;
            min-height: 100vh;
        }
        .main-title {
            color: #2b4170;
            font-weight: bold;
            margin-bottom: 24px;
            letter-spacing: 1px;
        }
        .form-section {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 8px 32px #b4c9ed33;
            padding: 30px 24px 18px 24px;
            margin: 32px auto 24px auto;
            max-width: 700px;
            border-right: 5px solid #2563eb33;
        }
        .form-label {
            font-weight: 600;
            letter-spacing: .5px;
        }
        .custom-btn {
            background: linear-gradient(90deg, #2563eb 0%, #0ea5e9 100%);
            color: #fff;
            font-weight: bold;
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 6px #2563eb33;
            transition: 0.2s;
        }
        .custom-btn:hover {
            background: linear-gradient(90deg, #0ea5e9 0%, #2563eb 100%);
            color: #fff;
            transform: translateY(-2px) scale(1.01);
        }
        .table {
            background: #fff;
            border-radius: 10px;
            overflow: hidden;
        }
        .table thead {
            background: #e0e7ff;
        }
        .table th, .table td {
            vertical-align: middle !important;
            font-size: 1.07em;
        }
        .table th {
            color: #2b4170;
        }
        .badge-year {
            background: #dbeafe;
            color: #2563eb;
            font-size: .95em;
            border-radius: 6px;
            padding: 3px 9px;
        }
        .balance-cell {
            font-size: 1.1em;
            font-weight: bold;
            color: #166534;
            background: #dcfce7;
            border-radius: 8px;
        }
        .balance-cell.zero {
            color: #991b1b;
            background: #fee2e2;
        }
        @media (max-width: 700px) {
            .form-section { padding: 18px 7px; }
            .main-title { font-size: 1.2em;}
            .table th, .table td { font-size: 0.97em;}
        }
    </style>
</head>
<body>
<div class="container py-3">
    <h2 class="main-title text-center"><i class="bi bi-calendar2-week"></i> إدارة رصيد الإجازات والمغادرات السنوي</h2>
    <?php if($msg): ?>
        <div class="alert alert-<?= $success ? "success" : "danger" ?> text-center"><?= $msg ?></div>
    <?php endif; ?>
    <div class="form-section mb-4 shadow-sm">
        <form method="post" class="row g-3">
            <div class="col-md-4 col-12">
                <label class="form-label">اختر الموظف</label>
                <select name="employee_id" class="form-select" required>
                    <option value="">اختر الموظف...</option>
                    <?php foreach($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>">
                            <?= htmlspecialchars($emp['name']) ?> (<?= htmlspecialchars($emp['emp_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-6">
                <label class="form-label">السنة</label>
                <select name="year" class="form-select" required>
                    <?php foreach($years as $y): ?>
                        <option value="<?= $y ?>" <?= ($y==$current_year?'selected':'') ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label">رصيد الإجازات (يوم)</label>
                <input type="number" min="0" name="leave_balance" class="form-control" required>
            </div>
            <div class="col-md-3 col-12">
                <label class="form-label">رصيد المغادرات (دقيقة)</label>
                <input type="number" min="0" name="exit_balance" class="form-control" required>
            </div>
            <div class="col-12 d-grid mt-2">
                <button class="custom-btn py-2" name="set_balance"><i class="bi bi-save2"></i> حفظ الرصيد</button>
            </div>
        </form>
    </div>

    <h5 class="mt-5 mb-2 text-center">الأرصدة السنوية الحالية</h5>
    <div class="table-responsive shadow-sm rounded-3">
        <table class="table table-bordered text-center align-middle mb-0">
            <thead>
                <tr>
                    <th>الموظف</th>
                    <th>الرقم الوظيفي</th>
                    <?php foreach($years as $y): ?>
                        <th><span class="badge-year"><?= $y ?></span><br><small>إجازة/مغادرة</small></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach($employees as $emp): ?>
                <tr>
                    <td><?= htmlspecialchars($emp['name']) ?></td>
                    <td><?= htmlspecialchars($emp['emp_code']) ?></td>
                    <?php foreach($years as $y): 
                        $bal = $balances[$emp['id']][$y] ?? ['leave_balance'=>0,'exit_balance'=>0];
                        $zero = ($bal['leave_balance']==0 && $bal['exit_balance']==0)?' zero':'';
                    ?>
                        <td class="balance-cell<?= $zero ?>">
                            <?= intval($bal['leave_balance']) ?> / <?= intval($bal['exit_balance']) ?>
                        </td>
                    <?php endforeach;?>
                </tr>
            <?php endforeach;?>
            </tbody>
        </table>
    </div>
    <div class="text-muted text-center mt-3" style="font-size: .95em;">
        <i class="bi bi-info-circle"></i> عند بداية سنة جديدة، يُرحّل الرصيد المتبقي أو يمكنك إدخاله يدوياً هنا.
    </div>
</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</body>
</html>