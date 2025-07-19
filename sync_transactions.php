<?php
$srcPdo = new PDO("mysql:host=localhost;dbname=zkb;charset=utf8mb4", "root", "12341234");
$dstPdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "12341234");

// جلب آخر id موجود في جدول اللوج المحلي
$lastId = $dstPdo->query("SELECT MAX(id) FROM iclock_transaction_log")->fetchColumn();
if(!$lastId) $lastId = 0;

// جلب السجلات الجديدة فقط من zkb
$stmt = $srcPdo->prepare("SELECT * FROM iclock_transaction WHERE id > ?");
$stmt->execute([$lastId]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

if($data){
    $fields = array_keys($data[0]);
    $fieldList = implode(',', $fields);
    $placeholders = implode(',', array_fill(0, count($fields), '?'));

    $insertStmt = $dstPdo->prepare("INSERT INTO iclock_transaction_log ($fieldList) VALUES ($placeholders)");
    foreach($data as $row){
        $insertStmt->execute(array_values($row));
    }
    echo "تم نقل " . count($data) . " سجل جديد.\n";
}else{
    echo "لا توجد بيانات جديدة.\n";
}
?>