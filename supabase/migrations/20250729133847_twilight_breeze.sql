-- إصلاحات قاعدة البيانات المطلوبة

-- 1. إضافة فهارس لتحسين الأداء
CREATE INDEX IF NOT EXISTS idx_attendance_emp_date ON attendance_records(emp_code, work_date);
CREATE INDEX IF NOT EXISTS idx_shift_assignments_emp_date ON shift_assignments(employee_id, shift_date);
CREATE INDEX IF NOT EXISTS idx_leave_balances_emp_year ON leave_balances(emp_code, year);

-- 2. إضافة عمود work_hours_per_day للموظفين إذا لم يكن موجوداً
ALTER TABLE employees ADD COLUMN IF NOT EXISTS work_hours_per_day DECIMAL(4,2) DEFAULT 8.00;

-- 3. إصلاح جدول shift_assignments للتأكد من وجود العلاقات الصحيحة
ALTER TABLE shift_assignments 
ADD CONSTRAINT IF NOT EXISTS fk_shift_assignments_employee 
FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE;

-- 4. إضافة جدول لتتبع أخطاء المزامنة
CREATE TABLE IF NOT EXISTS sync_errors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    error_type VARCHAR(50),
    error_message TEXT,
    emp_code VARCHAR(20),
    punch_time DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 5. إضافة جدول للإعدادات العامة
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE,
    setting_value TEXT,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- إدراج الإعدادات الافتراضية
INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
('work_day_start_hour', '3', 'ساعة بداية اليوم العملي (لحساب work_date)'),
('default_work_hours', '8', 'عدد ساعات العمل الافتراضية'),
('late_tolerance_minutes', '10', 'دقائق السماح الافتراضية للتأخير'),
('salary_calculation_method', 'monthly', 'طريقة حساب الراتب الافتراضية');