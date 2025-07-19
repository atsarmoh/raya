<?php
if (!function_exists('diff_minutes')) {
    function diff_minutes($from, $to) {
        $from_ts = strtotime($from);
        $to_ts   = strtotime($to);
        if ($to_ts < $from_ts) {
            $to_ts += 24*60*60;
        }
        return intval(($to_ts - $from_ts) / 60);
    }
}

function calculate_employee_salary($pdo, $employee_id, $month) {
    // 1. جلب بيانات الموظف
    $emp = $pdo->prepare("SELECT * FROM employees WHERE id=?");
    $emp->execute([$employee_id]);
    $employee = $emp->fetch(PDO::FETCH_ASSOC);
    if (!$employee) return ['error'=>'الموظف غير موجود'];

    $salary_type = $employee['salary_type']; // monthly/daily
    $salary_amount = floatval($employee['salary_amount']);
    $base_salary = isset($employee['base_salary']) && $employee['base_salary'] !== '' ? floatval($employee['base_salary']) : $salary_amount;
    $gross_salary = isset($employee['gross_salary']) && $employee['gross_salary'] !== '' ? floatval($employee['gross_salary']) : $base_salary;
    $insurance_salary = isset($employee['insurance_salary']) && $employee['insurance_salary'] !== '' ? floatval($employee['insurance_salary']) : 0;
    $is_insured = isset($employee['is_insured']) && $employee['is_insured'] ? 1 : 0;
    $emp_code = $employee['emp_code'];

    // 2. تحديد أيام الشهر حتى اليوم الحالي فقط
    $year = substr($month, 0, 4);
    $mon = substr($month, 5, 2);
    $days_in_month = date('t', strtotime("$month-01"));
    $today = date('Y-m-d');
    $max_day = ($month == date('Y-m')) ? date('j') : $days_in_month;
    $last_day = "$month-" . str_pad($max_day, 2, "0", STR_PAD_LEFT);

    // 3. جلب حضور الموظف وأيام الدوام وجدول الشفتات لهذا الشهر حتى اليوم
    $from = "$month-01";
    $to = $last_day;

    $att_stmt = $pdo->prepare("SELECT * FROM attendance_daily_details WHERE emp_code=? AND work_date BETWEEN ? AND ?");
    $att_stmt->execute([$emp_code, $from, $to]);
    $attendance = [];
    foreach ($att_stmt as $row) {
        $attendance[$row['work_date']] = $row;
    }

    // جلب جدول الشفتات الفعلي لكل يوم، مع الاسم
    $shifts_map = [];
    $shift_assign = $pdo->prepare("SELECT sa.shift_date, s.start_time, s.end_time, s.tolerance_minutes, s.name
        FROM shift_assignments sa
        JOIN shift_days sd ON sa.shift_day_id=sd.id
        JOIN shifts s ON sd.shift_id=s.id
        WHERE sa.employee_id=? AND sa.shift_date BETWEEN ? AND ?");
    $shift_assign->execute([$employee_id, $from, $to]);
    foreach($shift_assign as $row) {
        $shifts_map[$row['shift_date']] = [
            'start_time'=>$row['start_time'],
            'end_time'=>$row['end_time'],
            'tolerance_minutes'=>(int)$row['tolerance_minutes'],
            'name'=>isset($row['name']) ? $row['name'] : '',
        ];
    }

    $assigned_dates = array_keys($shifts_map);

    // إجازات ومغادرات (تعديل الاستعلام ليغطي كل الإجازات التي تتقاطع مع الشهر)
    $leaves_map = [];
    $leaves_stmt = $pdo->prepare("SELECT start_date, end_date FROM leaves WHERE employee_id=? AND status='approved' AND (start_date <= ? AND end_date >= ?)");
    $leaves_stmt->execute([$employee_id, $to, $from]);
    foreach($leaves_stmt as $lv) {
        $start = $lv['start_date'];
        $end = $lv['end_date'];
        $period = new DatePeriod(new DateTime($start), new DateInterval('P1D'), (new DateTime($end))->modify('+1 day'));
        foreach ($period as $dt) {
            $leaves_map[$dt->format('Y-m-d')] = true;
        }
    }
    $exits_final_map = [];
    $exits_final_stmt = $pdo->prepare("SELECT date FROM exit_permissions WHERE employee_id=? AND status='approved' AND type='final' AND date BETWEEN ? AND ?");
    $exits_final_stmt->execute([$employee_id, $from, $to]);
    foreach($exits_final_stmt as $ep) {
        $exits_final_map[$ep['date']] = true;
    }
    $exits_temp_map = [];
    $exits_temp_stmt = $pdo->prepare("SELECT date, from_time, to_time, duration_requested FROM exit_permissions WHERE employee_id=? AND status='approved' AND type='temporary' AND date BETWEEN ? AND ?");
    $exits_temp_stmt->execute([$employee_id, $from, $to]);
    foreach($exits_temp_stmt as $ep) {
        $exits_temp_map[$ep['date']][] = [
            'from_time'=>$ep['from_time'],
            'to_time'=>$ep['to_time'],
            'duration'=>intval($ep['duration_requested'])
        ];
    }

    // الحضور والغياب والتأخير
    $present_dates = [];
    $absent_dates = [];
    $late_dates = [];
    $early_dates = [];
    $total_late = 0;
    $total_early = 0;

    foreach ($assigned_dates as $date) {
        $shift = $shifts_map[$date];
        $is_leave = isset($leaves_map[$date]);
        $is_exit_final = isset($exits_final_map[$date]);
        $is_excused = $is_leave || $is_exit_final;

        // تم التعديل هنا: شرط الحضور يعتبر الموظف مداوم إذا كان له دخول أو خروج أو present=1
        if (
            isset($attendance[$date]) &&
            (
                !empty($attendance[$date]['first_in']) ||
                !empty($attendance[$date]['last_out']) ||
                $attendance[$date]['present']
            )
        ) {
            $present_dates[] = $date;

            $first_in = $attendance[$date]['first_in'];
            $late_minutes = 0;
            if ($shift['start_time'] && $first_in) {
                $sched = strtotime("$date {$shift['start_time']}");
                $actual = strtotime($first_in);
                $tolerance = $shift['tolerance_minutes'];
                $after_tolerance = $sched + $tolerance * 60;
                if ($actual > $after_tolerance) {
                    $late_minutes = intval(($actual - $after_tolerance) / 60);
                    if(isset($exits_temp_map[$date])) {
                        foreach($exits_temp_map[$date] as $exit) {
                            $exit_from = strtotime($date.' '.$exit['from_time']);
                            $exit_to = strtotime($date.' '.$exit['to_time']);
                            if(!$exit['to_time']) continue;
                            if ($actual >= $exit_from && $actual <= $exit_to) {
                                $late_minutes -= intval($exit['duration']);
                                if($late_minutes < 0) $late_minutes = 0;
                            }
                        }
                    }
                    $total_late += $late_minutes;
                    if($late_minutes > 0) $late_dates[] = ['date'=>$date, 'minutes'=>$late_minutes];
                }
            }
            $last_out = $attendance[$date]['last_out'];
            $early_minutes = 0;
            // تصحيح احتساب الخروج المبكر: فقط إذا كان وقت الخروج مسجلاً وبقي الموظف في الدوام أكثر من 30 دقيقة
            if ($shift['end_time'] && $last_out && strtotime($last_out) > 0 && $first_in) {
                $sched_end = strtotime("$date {$shift['end_time']}");
                $actual_out = strtotime($last_out);
                $actual_in = strtotime($first_in);
                // تجاهل الخروج المبكر إذا حضر وخرج مباشرة (أقل من نصف ساعة)
                if (($actual_out - $actual_in) > 30*60) {
                    if ($actual_out < $sched_end) {
                        $early_minutes = intval(($sched_end - $actual_out)/60);
                        // خصم دقائق المغادرة المؤقتة من الخروج المبكر
                        if(isset($exits_temp_map[$date])) {
                            foreach($exits_temp_map[$date] as $exit) {
                                $exit_from = strtotime($date.' '.$exit['from_time']);
                                $exit_to = strtotime($date.' '.$exit['to_time']);
                                if(!$exit['to_time']) continue;
                                if ($actual_out >= $exit_from && $actual_out <= $exit_to) {
                                    $early_minutes -= intval($exit['duration']);
                                    if($early_minutes < 0) $early_minutes = 0;
                                }
                            }
                        }
                        $total_early += $early_minutes;
                        if($early_minutes > 0) $early_dates[] = ['date'=>$date, 'minutes'=>$early_minutes];
                    }
                }
            }
        } else {
            if ($salary_type == "monthly") {
                if (!$is_excused) {
                    $absent_dates[] = $date;
                }
            } elseif ($salary_type == "daily") {
                if (!$is_leave) {
                    $absent_dates[] = $date;
                }
            }
        }
    }
    $actual_days_worked = count($present_dates);
    $num_absent_days = count($absent_dates);

    // دقائق العمل المطلوبة (استثناء الجمعه)
    $required_minutes = 0;
    $work_minutes_per_day = [];
    foreach ($assigned_dates as $date) {
        // استثنِ أيام الجمعة (5 في date('N'))
        if (date('N', strtotime($date)) == 5) continue;
        $shift = $shifts_map[$date];
        if ($shift['start_time'] && $shift['end_time']) {
            $start = strtotime($date.' '.$shift['start_time']);
            $end = strtotime($date.' '.$shift['end_time']);
            if ($end <= $start) $end = strtotime('+1 day', $end); // دعم الشفت الليلي
            $day_minutes = intval(($end - $start)/60);
            $required_minutes += $day_minutes;
            $work_minutes_per_day[$date] = $day_minutes;
        }
    }

    // العلاوات والخصومات
    $bonuses_stmt = $pdo->prepare("SELECT * FROM bonuses WHERE employee_id=? AND MONTH(bonus_date)=? AND YEAR(bonus_date)=?");
    $bonuses_stmt->execute([$employee_id, $mon, $year]);
    $total_additions = 0;
    $total_deductions = 0;
    $bonuses_rows = [];
    foreach ($bonuses_stmt as $b) {
        $bonuses_rows[] = $b;
        if ($b['bonus_type'] == 'addition')
            $total_additions += $b['amount'];
        else
            $total_deductions += $b['amount'];
    }

    // الوقت الإضافي
    $overtime_stmt = $pdo->prepare("SELECT * FROM overtime WHERE employee_id=? AND MONTH(overtime_date)=? AND YEAR(overtime_date)=?");
    $overtime_stmt->execute([$employee_id, $mon, $year]);
    $overtime_amount = 0;
    $overtime_rows = [];
    foreach ($overtime_stmt as $ot) {
        $minutes = 0;
        if (!empty($ot['from_time']) && !empty($ot['to_time'])) {
            $from = $ot['overtime_date'].' '.$ot['from_time'];
            $to   = $ot['overtime_date'].' '.$ot['to_time'];
            $minutes = diff_minutes($from, $to);
            $hours = $minutes / 60;
        } else {
            $hours = $ot['hours'];
            $minutes = $hours * 60;
        }
        $rate = $ot['rate'] ?? ($salary_type=='monthly' ? ($base_salary/($required_minutes/60*22)) : ($salary_amount/8));
        $amount = $hours * $rate;
        $overtime_rows[] = [
            'id' => $ot['id'],
            'date' => $ot['overtime_date'],
            'from_time' => $ot['from_time'],
            'to_time' => $ot['to_time'],
            'minutes' => $minutes,
            'hours' => $hours,
            'rate' => $rate,
            'amount' => $amount,
        ];
        $overtime_amount += $amount;
    }

    // --- حساب الراتب النهائي ---

    // خصم التأخير والخروج المبكر وخصم الغياب
    $deduction = 0;
    $absence_deduction = 0;
    $insurance_deduction = 0;
    $days_double_worked = [];

    // للساعات الفعلية وجدول الشفتات المنجزة
    $actual_work_minutes = 0;
    $worked_shifts = [];
    $total_exit_minutes = 0; // مجموع دقائق المغادرة المؤقتة

    foreach ($present_dates as $date) {
        $minutes = $work_minutes_per_day[$date] ?? 0;
        $actual_work_minutes += $minutes;
        $shift = $shifts_map[$date] ?? null;
        $shift_name = isset($shift['name']) ? $shift['name'] : '';
        $worked_shifts[] = [
            'date' => $date,
            'minutes' => $minutes,
            'hours' => round($minutes/60,2),
            'shift_name' => $shift_name,
        ];
        // اجمع دقائق المغادرة المؤقتة
        if (isset($exits_temp_map[$date])) {
            foreach ($exits_temp_map[$date] as $exit) {
                $total_exit_minutes += $exit['duration'];
            }
        }
    }
    // الساعات المنجزة = مجموع الدقائق - التأخير - المغادرة المبكرة
    $effective_minutes = $actual_work_minutes - $total_late - $total_early;
    if ($effective_minutes < 0) $effective_minutes = 0;
    $actual_hours = round($effective_minutes / 60, 2);

    // الصيغة ساعات:دقائق
    $hours_display = floor($effective_minutes / 60);
    $minutes_display = $effective_minutes % 60;
    $actual_hours_hm = sprintf('%02d:%02d', $hours_display, $minutes_display);

    if ($salary_type == "monthly") {
        // شهري: الخصم والتأخير من الأساسي، الضمان من الأساسي
        $days_in_month = date('t', strtotime("$month-01"));
        $work_hours_per_day = isset($employee['work_hours_per_day']) && $employee['work_hours_per_day'] > 0
            ? $employee['work_hours_per_day']
            : 8;
        $minutes_per_day = $work_hours_per_day * 60;
        $per_minute = ($minutes_per_day > 0) ? (($base_salary / $days_in_month) / $minutes_per_day) : 0;
        $deduction = ($total_late + $total_early) * $per_minute;
        $day_salary = $base_salary / $days_in_month;
        $absence_deduction = $num_absent_days * $day_salary;
        $deduction += $absence_deduction;
        // خصم الضمان
        if ($is_insured && $base_salary > 0) {
            $insurance_deduction = $base_salary * 0.075; // 7.5%
        }
        $salary_after_deductions = $base_salary - $deduction - $insurance_deduction;

    } else { // daily (مياومة)
        // حساب الراتب الفعلي (احتمال عمل مضاعف لبعض الأيام)
        $base_salary_total = 0;
        foreach ($present_dates as $date) {
            $shift = $shifts_map[$date] ?? null;
            if ($shift && $shift['start_time'] && $shift['end_time']) {
                $start = strtotime($date.' '.$shift['start_time']);
                $end = strtotime($date.' '.$shift['end_time']);
                if ($end <= $start) $end = strtotime('+1 day', $end);
                $hours = ($end - $start) / 3600;
                if ($hours >= 16) {
                    $base_salary_total += ($salary_amount * 2);
                    $days_double_worked[] = $date;
                } else {
                    $base_salary_total += $salary_amount;
                }
            } else {
                $base_salary_total += $salary_amount;
            }
        }
        $base_salary = $base_salary_total;

        // التأخير والمغادرة المبكرة تخصم من راتب الضمان إذا مشترك، وإلا من اليومية!
        $work_hours_per_day = isset($employee['work_hours_per_day']) && $employee['work_hours_per_day'] > 0
            ? $employee['work_hours_per_day']
            : 8;
        if ($is_insured && $insurance_salary > 0) {
            // فقط استخدم راتب الضمان لحساب قيمة الدقيقة، ولا تخصم الضمان أبداً من الراتب النهائي
            $per_minute = ($insurance_salary / 30) / $work_hours_per_day / 60;
        } else {
            $per_minute = ($salary_amount / $work_hours_per_day) / 60;
        }
        $deduction = ($total_late + $total_early) * $per_minute;

        // لا تخصم الضمان نفسه من الراتب النهائي للمياومة
        $insurance_deduction = 0;
        $salary_after_deductions = $base_salary - $deduction;
    }

    // الراتب النهائي الشهري = الراتب الإجمالي - الخصومات (المحتسبة على الأساسي) - الضمان + العلاوات + الإضافي - خصومات أخرى
    if ($salary_type == "monthly") {
        $net_salary = $gross_salary - $deduction - $insurance_deduction + $total_additions + $overtime_amount - $total_deductions;
    } else {
        $net_salary = $salary_after_deductions + $total_additions + $overtime_amount - $total_deductions;
    }

    return [
        'employee' => $employee,
        'month' => $month,
        'salary_type' => $salary_type,
        'base_salary' => round($base_salary,2),
        'gross_salary' => round($gross_salary,2),
        'insurance_salary' => round($insurance_salary,2),
        'is_insured' => $is_insured,
        'actual_days_worked' => $actual_days_worked,
        'required_minutes' => $required_minutes,
        'required_hours' => round($required_minutes/60,2),
        'actual_hours' => $actual_hours, // صيغة عشرية
        'actual_hours_hm' => $actual_hours_hm, // صيغة ساعات:دقائق
        'total_exit_minutes' => $total_exit_minutes,
        'worked_shifts' => $worked_shifts,
        'total_late_minutes' => $total_late,
        'total_early_minutes' => $total_early,
        'total_additions' => round($total_additions,2),
        'total_deductions' => round($total_deductions,2),
        'overtime_amount' => round($overtime_amount,2),
        'deduction' => round($deduction,2),
        'insurance_deduction' => round($insurance_deduction,2),
        'num_absent_days' => $num_absent_days,
        'absence_deduction' => round($absence_deduction,2),
        'absent_dates' => $absent_dates,
        'late_dates' => $late_dates,
        'early_leave_dates' => $early_dates,
        'net_salary' => round($net_salary,2),
        'days_double_worked' => $days_double_worked,
        'bonuses_rows' => $bonuses_rows,
        'overtime_rows' => $overtime_rows,
        'employee' => $employee,
        'salary_type' => $salary_type,
        'base_salary' => $base_salary,
        'gross_salary' => $gross_salary,
    ];
}
?>