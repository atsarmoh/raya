<?php
// صفحة عرض أخطاء المزامنة
$pdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "12341234");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// حذف الأخطاء القديمة (أكثر من 30 يوم)
$pdo->query("DELETE FROM sync_errors WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");

// جلب الأخطاء الحديثة
$errors = $pdo->query("
    SELECT * FROM sync_errors 
    ORDER BY created_at DESC 
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات الأخطاء
$stats = $pdo->query("
    SELECT 
        error_type,
        COUNT(*) as count,
        MAX(created_at) as last_occurrence
    FROM sync_errors 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY error_type
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>أخطاء المزامنة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', Tahoma, Arial, sans-serif; background: #f6f7fa;}
        .error-card { border-right: 4px solid #dc3545; }
        .warning-card { border-right: 4px solid #ffc107; }
        .info-card { border-right: 4px solid #17a2b8; }
    </style>
</head>
<body>
<div class="container py-4">
    <h2 class="mb-4">📊 تقرير أخطاء المزامنة</h2>
    
    <!-- إحصائيات الأخطاء -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5>📈 إحصائيات الأخطاء (آخر 7 أيام)</h5>
                </div>
                <div class="card-body">
                    <?php if (count($stats) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>نوع الخطأ</th>
                                        <th>عدد المرات</th>
                                        <th>آخر حدوث</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($stats as $stat): ?>
                                    <tr>
                                        <td>
                                            <?php
                                            $error_types = [
                                                'employee_not_found' => 'موظف غير موجود',
                                                'sync_error' => 'خطأ في المزامنة',
                                                'database_error' => 'خطأ في قاعدة البيانات'
                                            ];
                                            echo $error_types[$stat['error_type']] ?? $stat['error_type'];
                                            ?>
                                        </td>
                                        <td><span class="badge bg-danger"><?= $stat['count'] ?></span></td>
                                        <td><?= date('Y-m-d H:i', strtotime($stat['last_occurrence'])) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success">
                            ✅ لا توجد أخطاء في الأسبوع الماضي!
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- قائمة الأخطاء التفصيلية -->
    <div class="card">
        <div class="card-header">
            <h5>📋 تفاصيل الأخطاء (آخر 100 خطأ)</h5>
        </div>
        <div class="card-body">
            <?php if (count($errors) > 0): ?>
                <?php foreach($errors as $error): ?>
                <div class="card mb-2 error-card">
                    <div class="card-body py-2">
                        <div class="row align-items-center">
                            <div class="col-md-2">
                                <small class="text-muted"><?= date('Y-m-d H:i', strtotime($error['created_at'])) ?></small>
                            </div>
                            <div class="col-md-2">
                                <span class="badge bg-secondary"><?= htmlspecialchars($error['error_type']) ?></span>
                            </div>
                            <div class="col-md-2">
                                <?php if ($error['emp_code']): ?>
                                    <strong>كود: <?= htmlspecialchars($error['emp_code']) ?></strong>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <?= htmlspecialchars($error['error_message']) ?>
                            </div>
                            <div class="col-md-2">
                                <?php if ($error['punch_time']): ?>
                                    <small><?= date('H:i', strtotime($error['punch_time'])) ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert alert-success">
                    ✅ لا توجد أخطاء مسجلة!
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-3">
        <a href="sync_attendance.php" class="btn btn-primary">🔄 تشغيل المزامنة</a>
        <a href="present_today.php" class="btn btn-secondary">👥 عرض الحضور اليومي</a>
    </div>
</div>
</body>
</html>