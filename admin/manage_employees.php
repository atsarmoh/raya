<?php
$pdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "12341234");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // إضافة موظف جديد
    if (isset($_POST['add'])) {
        $name = $_POST['name'];
        $emp_code = $_POST['emp_code'];
        $salary_type = $_POST['salary_type'];
        $salary_amount = floatval($_POST['salary_amount']);
        $base_salary = isset($_POST['base_salary']) ? floatval($_POST['base_salary']) : $salary_amount;
        $gross_salary = isset($_POST['gross_salary']) ? floatval($_POST['gross_salary']) : $salary_amount;
        $is_insured = isset($_POST['is_insured']) ? 1 : 0;
        $insurance_salary = isset($_POST['insurance_salary']) ? floatval($_POST['insurance_salary']) : null;
        $job_title = $_POST['job_title'];
        $department = $_POST['department'];
        $stmt = $pdo->prepare("INSERT INTO employees (
            name, emp_code, salary_type, salary_amount, base_salary, gross_salary, is_insured, insurance_salary, job_title, department, is_active
        ) VALUES (?,?,?,?,?,?,?,?,?,?,1)");
        $stmt->execute([
            $name, $emp_code, $salary_type, $salary_amount, $base_salary, $gross_salary, $is_insured, $insurance_salary, $job_title, $department
        ]);
        $msg = "تم إضافة الموظف بنجاح.";
    }
    // تعديل بيانات موظف
    if (isset($_POST['edit_id'])) {
        $id = $_POST['edit_id'];
        $name = $_POST['edit_name'];
        $emp_code = $_POST['edit_emp_code'];
        $salary_type = $_POST['edit_salary_type'];
        $salary_amount = floatval($_POST['edit_salary_amount']);
        $base_salary = isset($_POST['edit_base_salary']) ? floatval($_POST['edit_base_salary']) : $salary_amount;
        $gross_salary = isset($_POST['edit_gross_salary']) ? floatval($_POST['edit_gross_salary']) : $salary_amount;
        $is_insured = isset($_POST['edit_is_insured']) ? 1 : 0;
        $insurance_salary = isset($_POST['edit_insurance_salary']) ? floatval($_POST['edit_insurance_salary']) : null;
        $job_title = $_POST['edit_job_title'];
        $department = $_POST['edit_department'];
        $stmt = $pdo->prepare("UPDATE employees SET 
            name=?, emp_code=?, salary_type=?, salary_amount=?, base_salary=?, gross_salary=?, is_insured=?, insurance_salary=?, job_title=?, department=?
            WHERE id=?");
        $stmt->execute([
            $name, $emp_code, $salary_type, $salary_amount, $base_salary, $gross_salary, $is_insured, $insurance_salary, $job_title, $department, $id
        ]);
        $msg = "تم تحديث بيانات الموظف.";
    }
}

// جلب جميع الموظفين
$employees = $pdo->query("SELECT * FROM employees ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدارة الموظفين والرواتب</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', Tahoma, Arial, sans-serif; background: #f4f7fa;}
        .main-title { color: #007B8A; font-weight: bold;}
        .form-section { background: #fff; border-radius: 15px; padding:20px; box-shadow:0 4px 18px #d0dbe890; margin-bottom: 28px;}
        .table-responsive { margin-top: 20px;}
        @media (max-width: 700px) {
            .main-title { font-size:1.1em;}
            .form-section, .table { font-size: 0.97em;}
        }
    </style>
    <script>
        function fillEditForm(id) {
            var row = document.getElementById('emp-'+id);
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_name').value = row.dataset.name;
            document.getElementById('edit_emp_code').value = row.dataset.empcode;
            document.getElementById('edit_salary_type').value = row.dataset.salarytype;
            document.getElementById('edit_salary_amount').value = row.dataset.salaryamount;
            document.getElementById('edit_base_salary').value = row.dataset.basesalary;
            document.getElementById('edit_gross_salary').value = row.dataset.grosssalary;
            document.getElementById('edit_is_insured').checked = (row.dataset.isinsured == "1");
            document.getElementById('edit_insurance_salary').value = row.dataset.insurancesalary;
            document.getElementById('edit_job_title').value = row.dataset.jobtitle;
            document.getElementById('edit_department').value = row.dataset.department;
            document.getElementById('editModalLabel').textContent = 'تعديل بيانات الموظف: ' + row.dataset.name;
            // Trigger display logic for insurance fields
            toggleEditInsuranceFields();
        }
        function toggleAddInsuranceFields() {
            var type = document.getElementById('salary_type').value;
            var insuranceFields = document.getElementById('add_insurance_fields');
            if (type === 'daily') {
                insuranceFields.style.display = '';
            } else {
                insuranceFields.style.display = 'none';
            }
            var insured = document.getElementById('is_insured').checked;
            document.getElementById('add_insurance_salary_group').style.display = (insured && type === 'daily') ? '' : 'none';
        }
        function toggleEditInsuranceFields() {
            var type = document.getElementById('edit_salary_type').value;
            var insuranceFields = document.getElementById('edit_insurance_fields');
            if (type === 'daily') {
                insuranceFields.style.display = '';
            } else {
                insuranceFields.style.display = 'none';
            }
            var insured = document.getElementById('edit_is_insured').checked;
            document.getElementById('edit_insurance_salary_group').style.display = (insured && type === 'daily') ? '' : 'none';
        }
        document.addEventListener("DOMContentLoaded", function() {
            document.getElementById('salary_type').addEventListener('change', toggleAddInsuranceFields);
            document.getElementById('is_insured').addEventListener('change', toggleAddInsuranceFields);
            toggleAddInsuranceFields();

            document.getElementById('edit_salary_type').addEventListener('change', toggleEditInsuranceFields);
            document.getElementById('edit_is_insured').addEventListener('change', toggleEditInsuranceFields);
            toggleEditInsuranceFields();
        });
    </script>
</head>
<body>
<div class="container py-3">
    <h2 class="main-title mb-4 text-center">إدارة الموظفين والرواتب</h2>
    <?php if($msg): ?>
        <div class="alert alert-success text-center"><?= $msg ?></div>
    <?php endif; ?>

    <!-- نموذج إضافة موظف جديد -->
    <div class="form-section mb-4">
        <form method="post" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">الاسم</label>
                <input required name="name" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label">الرقم الوظيفي</label>
                <input required name="emp_code" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label">نوع الراتب</label>
                <select name="salary_type" id="salary_type" class="form-select">
                    <option value="monthly">شهري</option>
                    <option value="daily">مياومة</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">قيمة الراتب/اليوم</label>
                <input required name="salary_amount" type="number" step="0.01" min="0" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label">الراتب الأساسي</label>
                <input name="base_salary" type="number" step="0.01" min="0" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label">الراتب الإجمالي</label>
                <input name="gross_salary" type="number" step="0.01" min="0" class="form-control">
            </div>
            <div class="col-md-3" id="add_insurance_fields" style="display:none;">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="is_insured" id="is_insured" value="1">
                    <label class="form-check-label" for="is_insured">مشترك في الضمان الاجتماعي (للمياومة)</label>
                </div>
                <div class="mt-2" id="add_insurance_salary_group" style="display:none;">
                    <label class="form-label">راتب الضمان (للمياومة فقط)</label>
                    <input name="insurance_salary" type="number" step="0.01" min="0" class="form-control">
                </div>
            </div>
            <div class="col-md-1">
                <label class="form-label">المسمى</label>
                <input name="job_title" class="form-control">
            </div>
            <div class="col-md-1">
                <label class="form-label">القسم</label>
                <input name="department" class="form-control">
            </div>
            <div class="col-md-1 text-end">
                <button class="btn btn-success px-4" name="add">إضافة</button>
            </div>
        </form>
    </div>

    <!-- جدول الموظفين -->
    <div class="table-responsive">
    <table class="table table-bordered align-middle text-center">
        <thead class="table-light">
            <tr>
                <th>الاسم</th>
                <th>الرقم الوظيفي</th>
                <th>نوع الراتب</th>
                <th>قيمة الراتب/اليوم</th>
                <th>الراتب الأساسي</th>
                <th>الراتب الإجمالي</th>
                <th>ضمان</th>
                <th>راتب الضمان</th>
                <th>المسمى الوظيفي</th>
                <th>القسم</th>
                <th>إجراءات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($employees as $emp): ?>
            <tr id="emp-<?= $emp['id'] ?>"
    data-name="<?= htmlspecialchars($emp['name']) ?>"
    data-empcode="<?= htmlspecialchars($emp['emp_code']) ?>"
    data-salarytype="<?= $emp['salary_type'] ?>"
    data-salaryamount="<?= $emp['salary_amount'] !== null && $emp['salary_amount'] !== '' ? $emp['salary_amount'] : '0' ?>"
    data-basesalary="<?= $emp['base_salary'] !== null && $emp['base_salary'] !== '' ? $emp['base_salary'] : '0' ?>"
    data-grosssalary="<?= $emp['gross_salary'] !== null && $emp['gross_salary'] !== '' ? $emp['gross_salary'] : '0' ?>"
    data-isinsured="<?= isset($emp['is_insured']) ? $emp['is_insured'] : '0' ?>"
    data-insurancesalary="<?= isset($emp['insurance_salary']) && $emp['insurance_salary'] !== null && $emp['insurance_salary'] !== '' ? $emp['insurance_salary'] : '0' ?>"
    data-jobtitle="<?= htmlspecialchars($emp['job_title']) ?>"
    data-department="<?= htmlspecialchars($emp['department']) ?>">
    <td><?= htmlspecialchars($emp['name']) ?></td>
    <td><?= htmlspecialchars($emp['emp_code']) ?></td>
    <td>
        <?php if($emp['salary_type']=='monthly'): ?>
            <span class="badge bg-primary">شهري</span>
        <?php else: ?>
            <span class="badge bg-info text-dark">مياومة</span>
        <?php endif;?>
    </td>
    <td><?= $emp['salary_amount'] !== null && $emp['salary_amount'] !== '' ? number_format((float)$emp['salary_amount'],2) : '-' ?></td>
    <td><?= $emp['base_salary'] !== null && $emp['base_salary'] !== '' ? number_format((float)$emp['base_salary'],2) : '-' ?></td>
    <td><?= $emp['gross_salary'] !== null && $emp['gross_salary'] !== '' ? number_format((float)$emp['gross_salary'],2) : '-' ?></td>
    <td>
        <?php if(isset($emp['is_insured']) && $emp['is_insured']): ?>
            <span class="badge bg-success">مشترك</span>
        <?php else: ?>
            <span class="badge bg-secondary">غير مشترك</span>
        <?php endif;?>
    </td>
    <td><?= isset($emp['insurance_salary']) && $emp['insurance_salary'] !== null && $emp['insurance_salary'] !== '' ? number_format((float)$emp['insurance_salary'],2) : '-' ?></td>
    <td><?= htmlspecialchars($emp['job_title']) ?></td>
    <td><?= htmlspecialchars($emp['department']) ?></td>
    <td>
        <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editModal" onclick="fillEditForm(<?= $emp['id'] ?>)">تعديل</button>
    </td>
</tr>
            <?php endforeach;?>
        </tbody>
    </table>
    </div>
</div>

<!-- نافذة التعديل -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
    <form method="post">
      <div class="modal-header">
        <h5 class="modal-title" id="editModalLabel">تعديل بيانات الموظف</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="اغلاق"></button>
      </div>
      <div class="modal-body row g-2">
        <input type="hidden" name="edit_id" id="edit_id">
        <div class="col-12">
            <label class="form-label">الاسم</label>
            <input type="text" name="edit_name" id="edit_name" class="form-control" required>
        </div>
        <div class="col-6">
            <label class="form-label">الرقم الوظيفي</label>
            <input type="text" name="edit_emp_code" id="edit_emp_code" class="form-control" required>
        </div>
        <div class="col-6">
            <label class="form-label">نوع الراتب</label>
            <select name="edit_salary_type" id="edit_salary_type" class="form-select">
                <option value="monthly">شهري</option>
                <option value="daily">مياومة</option>
            </select>
        </div>
        <div class="col-6">
            <label class="form-label">قيمة الراتب/اليوم</label>
            <input type="number" step="0.01" min="0" name="edit_salary_amount" id="edit_salary_amount" class="form-control" required>
        </div>
        <div class="col-6">
            <label class="form-label">الراتب الأساسي</label>
            <input type="number" step="0.01" min="0" name="edit_base_salary" id="edit_base_salary" class="form-control">
        </div>
        <div class="col-6">
            <label class="form-label">الراتب الإجمالي</label>
            <input type="number" step="0.01" min="0" name="edit_gross_salary" id="edit_gross_salary" class="form-control">
        </div>
        <div class="col-12" id="edit_insurance_fields" style="display:none;">
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="edit_is_insured" id="edit_is_insured" value="1">
                <label class="form-check-label" for="edit_is_insured">مشترك في الضمان الاجتماعي (للمياومة)</label>
            </div>
            <div class="mt-2" id="edit_insurance_salary_group" style="display:none;">
                <label class="form-label">راتب الضمان (للمياومة فقط)</label>
                <input type="number" step="0.01" min="0" name="edit_insurance_salary" id="edit_insurance_salary" class="form-control">
            </div>
        </div>
        <div class="col-6">
            <label class="form-label">المسمى الوظيفي</label>
            <input type="text" name="edit_job_title" id="edit_job_title" class="form-control">
        </div>
        <div class="col-6">
            <label class="form-label">القسم</label>
            <input type="text" name="edit_department" id="edit_department" class="form-control">
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary">حفظ التعديل</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">اغلاق</button>
      </div>
    </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>