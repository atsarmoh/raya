<?php
// نسخة محسنة من salary_calculator.php مع إصلاح المشاكل

if (!function_exists('diff_minutes')) {
    function diff_minutes($from, $to) {
        $from_ts = strtotime($from);
        $to_ts   = strtotime($to);
        if ($to_ts < $from_ts) {
            $to_ts += 24*60*60;
        }
        return max(0, intval(($to_ts - $from_ts) / 60));
    }
}

function calculate_employee_salary($pdo, $employee_id, $month) {
    try {
        // 1. جلب بيانات الموظف
        $emp = $pdo->prepare("SELECT * FROM employees WHERE id=?");
        $emp->execute([$employee_id]);
        $employee = $emp->fetch(PDO::FETCH_ASSOC);
        if (!$employee) return ['error'=>'الموظف غير موجود'];

        $salary_type = $employee['salary_type'] ?? 'monthly';
        $salary_amount = floatval($employee['salary_amount'] ?? 0);
        $base_salary = isset($employee['base_salary']) && $employee['base_salary'] !== '' ? floatval($employee['base_salary']) : $salary_amount;
        $gross_salary = isset($employee['gross_salary']) && $employee['gross_salary'] !== '' ? floatval($employee['gross_salary']) : $base_salary;
        $insurance_salary = isset($employee['insurance_salary']) && $employee['insurance_salary'] !== '' ? floatval($employee['insurance_salary']) : 0;
        $is_insured = isset($employee['is_insured']) && $employee['is_insured'] ? 1 : 0;
        $emp_code = $employee['emp_code'];
        $work_hours_per_day = floatval($employee['work_hours_per_day'] ?? 8);

        // 2. تحديد أيام الشهر
        $year = substr($month, 0, 4);
        $mon = substr($month, 5, 2);
        $days_in_month = date('t', strtotime("$month-01"));
        $today = date('Y-m-d');
        $max_day = ($month == date('Y-m')) ? date('j') : $days_in_month;
        $last_day = "$month-" . str_pad($max_day, 2, "0", STR_PAD_LEFT);

        $from = "$month-01";
        $to = $last_day;

        // 3. جلب حضور الموظف
        $att_stmt = $pdo->prepare("SELECT * FROM attendance_daily_details WHERE emp_code=? AND work_date BETWEEN ? AND ?");
        $att_stmt->execute([$emp_code, $from, $to]);
        $attendance = [];
        foreach ($att_stmt as $row) {
            $attendance[$row['work_date']] = $row;
        }

        // 4. جلب جدول الشفتات
        $shifts_map = [];
        $shift_assign = $pdo->prepare("
            SELECT sa.shift_date, s.start_time, s.end_time, s.tolerance_minutes, s.name, s.shift_type
            FROM shift_assignments sa
            JOIN shift_days sd ON sa.shift_day_id=sd.id
            JOIN shifts s ON sd.shift_id=s.id
            WHERE sa.employee_id=? AND sa.shift_date BETWEEN ? AND ?
        ");
        $shift_assign->execute([$employee_id, $from, $to]);
        foreach($shift_assign as $row) {
            $shifts_map[$row['shift_date']] = [
                'start_time'=>$row['start_time'],
                'end_time'=>$row['end_time'],
                'tolerance_minutes'=>(int)$row['tolerance_minutes'],
                'name'=>$row['name'] ?? '',
                'shift_type'=>$row['shift_type'] ?? 'fixed',
            ];
        }

        $assigned_dates = array_keys($shifts_map);

        // 5. جلب الإجازات والمغادرات
        $leaves_map = [];
        $leaves_stmt = $pdo->prepare("
            SELECT start_date, end_date, type 
            FROM leaves 
            WHERE employee_id=? AND status='approved' 
            AND (start_date <= ? AND end_date >= ?)
        ");
        $leaves_stmt->execute([$employee_id, $to, $from]);
        foreach($leaves_stmt as $lv) {
            $start = $lv['start_date'];
            $end = $lv['end_date'];
            $period = new DatePeriod(new DateTime($start), new DateInterval('P1D'), (new DateTime($end))->modify('+1 day'));
            foreach ($period as $dt) {
                $leaves_map[$dt->format('Y-m-d')] = $lv['type'];
            }
        }

        // المغادرات النهائية
        $exits_final_map = [];
        $exits_final_stmt = $pdo->prepare("
            SELECT date, duration_real 
            FROM exit_permissions 
            WHERE employee_id=? AND status IN ('approved', 'done') AND type='final' 
            AND date BETWEEN ? AND ?
        ");
        $exits_final_stmt->execute([$employee_id, $from, $to]);
        foreach($exits_final_stmt as $ep) {
            $exits_final_map[$ep['date']] = floatval($ep['duration_real'] ?? 0);
        }

        // المغادرات المؤقتة
        $exits_temp_map = [];
        $exits_temp_stmt = $pdo->prepare("
            SELECT date, duration_real 
            FROM exit_permissions 
            WHERE employee_id=? AND status='done' AND type='temporary' 
            AND date BETWEEN ? AND ?
        ");
        $exits_temp_stmt->execute([$employee_id, $from, $to]);
        foreach($exits_temp_stmt as $ep) {
            if (!isset($exits_temp_map[$ep['date']])) {
                $exits_temp_map[$ep['date']] = 0;
            }
            $exits_temp_map[$ep['date']] += floatval($ep['duration_real'] ?? 0);
        }

        // 6. حساب الحضور والغياب والتأخير
        $present_dates = [];
        $absent_dates = [];
        $late_dates = [];
        $early_dates = [];
        $total_late = 0;
        $total_early = 0;
        $total_exit_minutes = 0;

        foreach ($assigned_dates as $date) {
            $shift = $shifts_map[$date];
            $is_leave = isset($leaves_map[$date]);
            $is_exit_final = isset($exits_final_map[$date]);
            $is_excused = $is_leave || $is_exit_final;

            // تحقق من الحضور
            $is_present = isset($attendance[$date]) && (
                !empty($attendance[$date]['first_in']) ||
                !empty($attendance[$date]['last_out']) ||
                $attendance[$date]['present']
            );

            if ($is_present) {
                $present_dates[] = $date;

                // حساب التأخير
                $first_in = $attendance[$date]['first_in'] ?? null;
                $late_minutes = 0;
                if ($shift['start_time'] && $first_in && $shift['shift_type'] !== 'open') {
                    $sched = strtotime("$date {$shift['start_time']}");
                    $actual = strtotime($first_in);
                    $tolerance = $shift['tolerance_minutes'];
                    $after_tolerance = $sched + $tolerance * 60;
                    if ($actual > $after_tolerance) {
                        $late_minutes = intval(($actual - $after_tolerance) / 60);
                        // خصم المغادرات المؤقتة من التأخير
                        if (isset($exits_temp_map[$date])) {
                            $late_minutes = max(0, $late_minutes - $exits_temp_map[$date]);
                        }
                        $total_late += $late_minutes;
                        if($late_minutes > 0) $late_dates[] = ['date'=>$date, 'minutes'=>$late_minutes];
                    }
                }

                // حساب الخروج المبكر
                $last_out = $attendance[$date]['last_out'] ?? null;
                $early_minutes = 0;
                if ($shift['end_time'] && $last_out && $first_in && $shift['shift_type'] !== 'open') {
                    $sched_end = strtotime("$date {$shift['end_time']}");
                    if ($shift['end_time'] <= $shift['start_time']) {
                        $sched_end = strtotime("+1 day", $sched_end);
                    }
                    $actual_out = strtotime($last_out);
                    $actual_in = strtotime($first_in);
                    
                    // تجاهل الخروج المبكر إذا حضر وخرج مباشرة (أقل من نصف ساعة)
                    if (($actual_out - $actual_in) > 30*60 && $actual_out < $sched_end) {
                        $early_minutes = intval(($sched_end - $actual_out)/60);
                        // خصم المغادرات المؤقتة من الخروج المبكر
                        if (isset($exits_temp_map[$date])) {
                            $early_minutes = max(0, $early_minutes - $exits_temp_map[$date]);
                        }
                        $total_early += $early_minutes;
                        if($early_minutes > 0) $early_dates[] = ['date'=>$date, 'minutes'=>$early_minutes];
                    }
                }

                // إضافة دقائق المغادرة للإجمالي
                if (isset($exits_temp_map[$date])) {
                    $total_exit_minutes += $exits_temp_map[$date];
                }
                if (isset($exits_final_map[$date])) {
                    $total_exit_minutes += $exits_final_map[$date];
                }

            } else {
                // غياب
                if ($salary_type == "monthly") {
                    if (!$is_excused) {
                        $absent_dates[] = $date;
                    }
                } elseif ($salary_type == "daily") {
                    if (!$is_leave) { // للمياومة، المغادرة النهائية لا تعفي من الغياب
                        $absent_dates[] = $date;
                    }
                }
            }
        }

        $actual_days_worked = count($present_dates);
        $num_absent_days = count($absent_dates);

        // 7. حساب الساعات المطلوبة والفعلية
        $required_minutes = 0;
        $work_minutes_per_day = [];
        $worked_shifts = [];
        $actual_work_minutes = 0;
        $days_double_worked = [];

        foreach ($assigned_dates as $date) {
            // استثنِ أيام الجمعة من الحساب
            if (date('N', strtotime($date)) == 5) continue;
            
            $shift = $shifts_map[$date];
            $day_minutes = $work_hours_per_day * 60; // افتراضي
            
            if ($shift['start_time'] && $shift['end_time'] && $shift['shift_type'] !== 'open') {
                $start = strtotime($date.' '.$shift['start_time']);
                $end = strtotime($date.' '.$shift['end_time']);
                if ($end <= $start) $end = strtotime('+1 day', $end);
                $day_minutes = intval(($end - $start)/60);
            }
            
            $required_minutes += $day_minutes;
            $work_minutes_per_day[$date] = $day_minutes;
            
            // إذا كان الموظف حاضر
            if (in_array($date, $present_dates)) {
                $actual_work_minutes += $day_minutes;
                $shift_name = $shift['name'] ?? '';
                $worked_shifts[] = [
                    'date' => $date,
                    'minutes' => $day_minutes,
                    'hours' => round($day_minutes/60,2),
                    'shift_name' => $shift_name,
                ];
                
                // تحقق من العمل المضاعف (للمياومة)
                if ($salary_type == 'daily' && $day_minutes >= 16*60) {
                    $days_double_worked[] = $date;
                }
            }
        }

        // الساعات المنجزة = مجموع الدقائق - التأخير - الخروج المبكر
        $effective_minutes = max(0, $actual_work_minutes - $total_late - $total_early);
        $actual_hours = round($effective_minutes / 60, 2);

        // 8. جلب العلاوات والخصومات
        $bonuses_stmt = $pdo->prepare("
            SELECT * FROM bonuses 
            WHERE employee_id=? AND MONTH(bonus_date)=? AND YEAR(bonus_date)=?
        ");
        $bonuses_stmt->execute([$employee_id, $mon, $year]);
        $total_additions = 0;
        $total_deductions = 0;
        $bonuses_rows = [];
        foreach ($bonuses_stmt as $b) {
            $bonuses_rows[] = $b;
            if ($b['bonus_type'] == 'addition')
                $total_additions += floatval($b['amount']);
            else
                $total_deductions += floatval($b['amount']);
        }

        // 9. جلب الوقت الإضافي
        $overtime_stmt = $pdo->prepare("
            SELECT * FROM overtime 
            WHERE employee_id=? AND MONTH(overtime_date)=? AND YEAR(overtime_date)=?
        ");
        $overtime_stmt->execute([$employee_id, $mon, $year]);
        $overtime_amount = 0;
        $overtime_rows = [];
        foreach ($overtime_stmt as $ot) {
            $hours = 0;
            if (!empty($ot['from_time']) && !empty($ot['to_time'])) {
                $from = $ot['overtime_date'].' '.$ot['from_time'];
                $to   = $ot['overtime_date'].' '.$ot['to_time'];
                $minutes = diff_minutes($from, $to);
                $hours = $minutes / 60;
            } else {
                $hours = floatval($ot['hours'] ?? 0);
            }
            
            $rate = floatval($ot['rate'] ?? 0);
            if ($rate == 0) {
                // حساب السعر الافتراضي
                if ($salary_type == 'monthly') {
                    $monthly_hours = ($required_minutes/60) * 22; // تقدير شهري
                    $rate = $monthly_hours > 0 ? ($base_salary / $monthly_hours) : 0;
                } else {
                    $rate = $work_hours_per_day > 0 ? ($salary_amount / $work_hours_per_day) : 0;
                }
            }
            
            $amount = $hours * $rate;
            $overtime_rows[] = [
                'id' => $ot['id'],
                'date' => $ot['overtime_date'],
                'from_time' => $ot['from_time'],
                'to_time' => $ot['to_time'],
                'hours' => $hours,
                'rate' => $rate,
                'amount' => $amount,
            ];
            $overtime_amount += $amount;
        }

        // 10. حساب الراتب النهائي
        $deduction = 0;
        $absence_deduction = 0;
        $insurance_deduction = 0;

        if ($salary_type == "monthly") {
            // الراتب الشهري
            $per_minute = $work_hours_per_day > 0 ? (($base_salary / $days_in_month) / ($work_hours_per_day * 60)) : 0;
            $deduction = ($total_late + $total_early) * $per_minute;
            $day_salary = $base_salary / $days_in_month;
            $absence_deduction = $num_absent_days * $day_salary;
            $deduction += $absence_deduction;
            
            // خصم الضمان الاجتماعي
            if ($is_insured && $base_salary > 0) {
                $insurance_deduction = $base_salary * 0.075; // 7.5%
            }
            
            $net_salary = $gross_salary - $deduction - $insurance_deduction + $total_additions + $overtime_amount - $total_deductions;
            
        } else {
            // المياومة
            $base_salary_total = 0;
            foreach ($present_dates as $date) {
                $shift = $shifts_map[$date] ?? null;
                if (in_array($date, $days_double_worked)) {
                    $base_salary_total += ($salary_amount * 2);
                } else {
                    $base_salary_total += $salary_amount;
                }
            }
            $base_salary = $base_salary_total;

            // حساب خصم التأخير والخروج المبكر
            if ($is_insured && $insurance_salary > 0) {
                $per_minute = ($insurance_salary / 30) / ($work_hours_per_day * 60);
            } else {
                $per_minute = $work_hours_per_day > 0 ? (($salary_amount / $work_hours_per_day) / 60) : 0;
            }
            $deduction = ($total_late + $total_early) * $per_minute;

            // لا نخصم الضمان من المياومة
            $insurance_deduction = 0;
            
            $net_salary = $base_salary - $deduction + $total_additions + $overtime_amount - $total_deductions;
        }

        // التأكد من عدم وجود راتب سالب
        $net_salary = max(0, $net_salary);

        return [
            'employee' => $employee,
            'month' => $month,
            'salary_type' => $salary_type,
            'base_salary' => round($base_salary, 2),
            'gross_salary' => round($gross_salary, 2),
            'insurance_salary' => round($insurance_salary, 2),
            'is_insured' => $is_insured,
            'actual_days_worked' => $actual_days_worked,
            'required_minutes' => $required_minutes,
            'required_hours' => round($required_minutes/60, 2),
            'actual_hours' => $actual_hours,
            'total_exit_minutes' => $total_exit_minutes,
            'worked_shifts' => $worked_shifts,
            'total_late_minutes' => $total_late,
            'total_early_minutes' => $total_early,
            'total_additions' => round($total_additions, 2),
            'total_deductions' => round($total_deductions, 2),
            'overtime_amount' => round($overtime_amount, 2),
            'deduction' => round($deduction, 2),
            'insurance_deduction' => round($insurance_deduction, 2),
            'num_absent_days' => $num_absent_days,
            'absence_deduction' => round($absence_deduction, 2),
            'absent_dates' => $absent_dates,
            'late_dates' => $late_dates,
            'early_leave_dates' => $early_dates,
            'net_salary' => round($net_salary, 2),
            'days_double_worked' => $days_double_worked,
            'bonuses_rows' => $bonuses_rows,
            'overtime_rows' => $overtime_rows,
        ];

    } catch (Exception $e) {
        error_log("خطأ في حساب راتب الموظف {$employee_id}: " . $e->getMessage());
        return [
            'error' => 'حدث خطأ في حساب الراتب: ' . $e->getMessage(),
            'employee_id' => $employee_id,
            'month' => $month
        ];
    }
}
?>