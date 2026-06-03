<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Compute_Chokyo — 長距離ドライバー用計算ロジック
 *
 * デフォルト: kousoku_log + tenrec_daily ベース
 * 地場フラグ ON 時: mat_attendance_daily に切り替え
 */
class AM_Compute_Chokyo {

    public static function format_min( $min ) {
        if ( $min === null || $min === '' ) return '';
        $min = (int) $min;
        if ( $min < 0 ) {
            return '-' . floor( abs($min) / 60 ) . ':' . str_pad( abs($min) % 60, 2, '0', STR_PAD_LEFT );
        }
        return floor( $min / 60 ) . ':' . str_pad( $min % 60, 2, '0', STR_PAD_LEFT );
    }

    private static function get_last_g_time( $entry ) {
        foreach ( [ 'g7_time', 'g5_time', 'g3_time' ] as $key ) {
            $val = trim( $entry[ $key ] ?? '' );
            if ( $val !== '' ) return $val;
        }
        return '';
    }

    /* ---------------------------------------------------------------
     * 月別日次データ生成
     * ------------------------------------------------------------- */
    public static function get_monthly_rows( $crew_code, $year_month, $driver_name ) {
        global $wpdb;

        $start_date = $year_month . '-01';
        $end_date   = date( 'Y-m-t', strtotime( $start_date ) );
        $ymd_start  = str_replace( '-', '', $start_date );
        $ymd_end    = str_replace( '-', '', $end_date );

        // kousoku_log
        $kousoku_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$wpdb->prefix}kousoku_log`
             WHERE crew_code COLLATE utf8mb4_unicode_520_ci = %s
               AND work_date BETWEEN %s AND %s
             ORDER BY work_date ASC",
            $crew_code, $start_date, $end_date
        ), ARRAY_A );
        $kousoku_by_date = [];
        foreach ( (array) $kousoku_rows as $r ) { $kousoku_by_date[ $r['work_date'] ] = $r; }

        // tenrec_daily
        $tenrec_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ymd, entries FROM `{$wpdb->prefix}tenrec_daily` WHERE ymd BETWEEN %s AND %s",
            $ymd_start, $ymd_end
        ), ARRAY_A );
        $tenrec_by_date = [];
        foreach ( (array) $tenrec_rows as $r ) {
            $date    = substr($r['ymd'],0,4).'-'.substr($r['ymd'],4,2).'-'.substr($r['ymd'],6,2);
            $entries = json_decode( $r['entries'], true );
            if ( ! is_array( $entries ) ) continue;
            foreach ( $entries as $entry ) {
                if ( trim( $entry['driver'] ?? '' ) === $driver_name ) {
                    $tenrec_by_date[ $date ] = $entry;
                    break;
                }
            }
        }

        // mat_attendance_daily（地場フラグON日用）
        $mat_by_date = [];
        $employee_code_for_mat = $wpdb->get_var( $wpdb->prepare(
            "SELECT employee_code FROM `{$wpdb->prefix}emp_master`
             WHERE crew_code COLLATE utf8mb4_unicode_520_ci = %s LIMIT 1",
            $crew_code
        ) );
        if ( $employee_code_for_mat && $employee_code_for_mat !== '―' ) {
            $mat_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT work_date, clock_in, clock_out, break_minutes
                 FROM `{$wpdb->prefix}mat_attendance_daily`
                 WHERE employee_code = %s AND work_date BETWEEN %s AND %s",
                $employee_code_for_mat, $start_date, $end_date
            ), ARRAY_A );
            foreach ( (array) $mat_rows as $mr ) { $mat_by_date[ $mr['work_date'] ] = $mr; }
        }

        $affiliation_id = AM_DB::get_affiliation_id_by_crew( $crew_code );
        $shitei_rules   = ( AM_DB::get_active_rules_by_affiliation() )[ $affiliation_id ] ?? [];
        $saved_kintai   = AM_DB::get_chokyo_saved_kintai( $crew_code, $year_month );
        $has_saved      = ! empty( $saved_kintai );

        $dow_ja = [ 'Sun'=>'日','Mon'=>'月','Tue'=>'火','Wed'=>'水','Thu'=>'木','Fri'=>'金','Sat'=>'土' ];
        $rows   = [];
        $cursor = new DateTime( $start_date );
        $last   = new DateTime( $end_date );

        // ---- パス1：kousoku_log をデフォルトとして全日付分生成 ----
        while ( $cursor <= $last ) {
            $date_str = $cursor->format('Y-m-d');
            $dow_num  = (int) $cursor->format('w');
            $dow      = $dow_ja[ $cursor->format('D') ];
            $is_sun   = $dow_num === 0;
            $is_sat   = $dow_num === 6;

            $k = $kousoku_by_date[ $date_str ] ?? null;
            $t = $tenrec_by_date[ $date_str ]  ?? null;

            $start_time = '';
            if ( $t ) $start_time = trim( $t['g1_time'] ?? '' );
            if ( $start_time === '' && $k ) $start_time = substr( $k['start_time'] ?? '', 0, 5 );

            $end_time = '';
            if ( $t ) $end_time = self::get_last_g_time( $t );
            if ( $end_time === '' && $k ) {
                $end_time = substr( $k['end_time'] ?? '', 0, 5 );
                if ( $k['end_next_day'] ?? 0 ) $end_time .= '(翌)';
            }

            $kousoku_min = $drive_min = $cargo_min = $labor_min = $break_calc_min = $overtime_min = $midnight_min = null;
            if ( $k ) {
                $kousoku_min    = (int) $k['kousoku_total_min'];
                $labor_min      = (int) $k['actual_work_min'];
                $drive_min      = (int) ( $k['drive_min'] ?? 0 );
                $cargo_min      = $k['cargo_min']    !== null ? (int) $k['cargo_min']    : null;
                $midnight_min   = $k['midnight_min'] !== null ? (int) $k['midnight_min'] : null;
                $break_calc_min = max( 0, $kousoku_min - $labor_min );
                $overtime_min   = $labor_min > 480 ? $labor_min - 480 : 0;
            }

            $is_shitei = self::is_shitei_holiday( $date_str, $dow_num, $shitei_rules );
            $default_kintai = $k !== null ? '出勤' : ( $is_sun ? '法定休' : ( $is_shitei ? '所定休' : '' ) );

            $rows[] = [
                'date' => $date_str, 'dow' => $dow, 'dow_num' => $dow_num,
                'is_sun' => $is_sun, 'is_sat' => $is_sat, 'is_shitei_holiday' => $is_shitei,
                'has_data' => $k !== null, 'default_kintai' => $default_kintai,
                'furikae_label' => '', 'is_manual' => false, 'jiba' => false,
                'hayatai_min' => 0, 'note' => '',
                'start_time' => $start_time, 'end_time' => $end_time,
                'kousoku_min' => $kousoku_min, 'labor_min' => $labor_min,
                'drive_min' => $drive_min, 'cargo_min' => $cargo_min,
                'break_calc_min' => $break_calc_min, 'overtime_min' => $overtime_min,
                'midnight_min' => $midnight_min,
            ];
            $cursor->modify('+1 day');
        }

        // ---- パス2：保存データ適用 + 地場フラグON日をMATに切り替え ----
        if ( $has_saved ) {
            foreach ( $rows as &$r ) {
                $saved = $saved_kintai[ $r['date'] ] ?? null;
                if ( $saved !== null ) {
                    $r['default_kintai'] = $saved['kintai_type'];
                    $r['furikae_label']  = $saved['furikae_label'];
                    $r['is_manual']      = (bool) $saved['is_manual'];
                    $r['jiba']           = (bool) ( $saved['jiba'] ?? false );
                    $r['hayatai_min']    = (int)  ( $saved['hayatai_min'] ?? 0 );
                    $r['note']           = $saved['note'] ?? '';
                }
            }
            unset( $r );

            foreach ( $rows as &$r ) {
                if ( ! ( $r['jiba'] ?? false ) ) continue;
                $mat = $mat_by_date[ $r['date'] ] ?? null;
                if ( ! $mat ) continue;

                $ci = $mat['clock_in']  ? substr( $mat['clock_in'],  0, 5 ) : '';
                $co = $mat['clock_out'] ? substr( $mat['clock_out'], 0, 5 ) : '';
                $bm = isset( $mat['break_minutes'] ) && $mat['break_minutes'] !== null ? (int) $mat['break_minutes'] : 0;

                $r['start_time'] = $ci;
                $r['end_time']   = $co;
                if ( $ci !== '' && $co !== '' ) {
                    list( $sh, $sm ) = array_map( 'intval', explode( ':', $ci ) );
                    list( $eh, $em ) = array_map( 'intval', explode( ':', $co ) );
                    $st = $sh * 60 + $sm; $et = $eh * 60 + $em;
                    if ( $et <= $st ) $et += 1440;
                    $kousoku = $et - $st; $labor = $kousoku - $bm;
                    $r['kousoku_min'] = $kousoku; $r['labor_min'] = max( 0, $labor );
                    $r['break_calc_min'] = $bm; $r['overtime_min'] = max( 0, $labor - 480 );
                } else {
                    $r['kousoku_min'] = $r['labor_min'] = $r['break_calc_min'] = $r['overtime_min'] = null;
                }
                $r['drive_min'] = $r['cargo_min'] = $r['midnight_min'] = null;
                $r['has_data']  = true;
            }
            unset( $r );

            $rows = self::check_alerts_only( $rows );
        } else {
            $rows = self::apply_auto_kintai( $rows );
        }

        // ---- パス3：休日勤務フラグ ----
        $furikae_covered = [];
        foreach ( $rows as $r ) {
            if ( $r['default_kintai'] === '法定振替休' && ! empty( $r['furikae_label'] ) ) {
                $furikae_covered[] = $r['furikae_label'];
            }
        }
        foreach ( $rows as &$r ) {
            $r['kyuujitsu_kinmu'] = false;
            if ( $r['is_sun'] && $r['default_kintai'] === '出勤' ) {
                $expected = date( 'm/d', strtotime( $r['date'] ) ) . '(日)の振替';
                if ( ! in_array( $expected, $furikae_covered, true ) ) $r['kyuujitsu_kinmu'] = true;
            }
        }
        unset( $r );

        return $rows;
    }

    private static function is_shitei_holiday( $date_str, $dow_num, $rules ) {
        if ( empty( $rules ) ) return false;
        foreach ( $rules as $rule ) {
            if ( (int)$rule['day_of_week'] !== $dow_num ) continue;
            $week_nums = array_map( 'intval', explode( ',', $rule['week_numbers'] ) );
            if ( in_array( self::week_of_month_for_dow( $date_str, $dow_num ), $week_nums, true ) ) return true;
        }
        return false;
    }

    private static function week_of_month_for_dow( $date_str, $dow_num ) {
        $count = 0; $cursor = new DateTime( substr( $date_str, 0, 7 ) . '-01' ); $target = new DateTime( $date_str );
        while ( $cursor <= $target ) { if ( (int)$cursor->format('w') === $dow_num ) $count++; $cursor->modify('+1 day'); }
        return $count;
    }

    public static function apply_auto_kintai( $rows ) {
        $warnings = [];
        $shitei_count = 0;
        foreach ( $rows as &$r ) {
            if ( $r['default_kintai'] === '所定休' ) { $shitei_count++; if ( $shitei_count > 2 ) $r['default_kintai'] = ''; }
        }
        unset( $r );
        foreach ( $rows as $i => $r ) {
            if ( $r['is_sun'] && $r['has_data'] && $r['default_kintai'] === '出勤' ) {
                $assigned = false;
                for ( $j = $i + 1; $j < count( $rows ); $j++ ) {
                    if ( $rows[$j]['default_kintai'] === '' && ! $rows[$j]['has_data'] ) {
                        $rows[$j]['default_kintai'] = '法定振替休';
                        $rows[$j]['furikae_label']  = date( 'm/d', strtotime( $r['date'] ) ) . '(日)の振替';
                        $assigned = true; break;
                    }
                }
                if ( ! $assigned ) $warnings[] = [ 'type' => 'error', 'message' => date( 'm/d', strtotime( $r['date'] ) ) . '(日)の振替休を割り当てられる日がありません' ];
            }
        }
        foreach ( $rows as $i => $r ) {
            if ( $r['is_shitei_holiday'] && $r['has_data'] && $r['default_kintai'] === '出勤' ) {
                $assigned = false;
                for ( $j = $i + 1; $j < count( $rows ); $j++ ) {
                    if ( $rows[$j]['default_kintai'] === '' && ! $rows[$j]['has_data'] ) {
                        $rows[$j]['default_kintai'] = '所定振替休';
                        $rows[$j]['furikae_label']  = date( 'm/d', strtotime( $r['date'] ) ) . 'の振替';
                        $assigned = true; break;
                    }
                }
                if ( ! $assigned ) $warnings[] = [ 'type' => 'error', 'message' => date( 'm/d', strtotime( $r['date'] ) ) . 'の振替休を割り当てられる日がありません' ];
            }
        }
        $houtei = $houtei_furi = $shitei = 0;
        foreach ( $rows as $r ) {
            if ( $r['default_kintai'] === '法定休' )     $houtei++;
            if ( $r['default_kintai'] === '法定振替休' ) $houtei_furi++;
            if ( $r['default_kintai'] === '所定休' )     $shitei++;
        }
        $total = $houtei + $houtei_furi;
        if ( $total < 4 || $total > 5 ) array_unshift( $warnings, [ 'type' => 'warn', 'message' => sprintf( '法定休の合計（法定休%d日＋法定振替休%d日＝%d日）が正常範囲（4〜5日）を外れています。', $houtei, $houtei_furi, $total ) ] );
        if ( $shitei > 2 ) array_unshift( $warnings, [ 'type' => 'warn', 'message' => '所定休が2日を超えています。' ] );
        if ( ! empty( $rows ) ) $rows[0]['_alerts'] = $warnings;
        return $rows;
    }

    public static function check_alerts_only( $rows ) {
        $houtei = $houtei_furi = $shitei = 0; $alerts = [];
        foreach ( $rows as $r ) {
            if ( $r['default_kintai'] === '法定休' )     $houtei++;
            if ( $r['default_kintai'] === '法定振替休' ) $houtei_furi++;
            if ( $r['default_kintai'] === '所定休' )     $shitei++;
        }
        $total = $houtei + $houtei_furi;
        if ( $total < 4 || $total > 5 ) $alerts[] = [ 'type' => 'warn', 'message' => sprintf( '法定休の合計（%d日）が正常範囲（4〜5日）を外れています。', $total ) ];
        if ( $shitei > 2 ) $alerts[] = [ 'type' => 'warn', 'message' => '所定休が2日を超えています。' ];
        if ( ! empty( $rows ) ) $rows[0]['_alerts'] = $alerts;
        return $rows;
    }

    public static function get_weekly_summary( $crew_code, $year_month, $monthly_rows ) {
        $month_start_str = $year_month . '-01';
        $month_end_str   = date( 'Y-m-t', strtotime( $month_start_str ) );
        $carryover       = AM_DB::get_chokyo_carryover( $crew_code, $year_month );
        return self::_build_weekly_static( $crew_code, $year_month, $monthly_rows, $month_start_str, $month_end_str, $carryover, 'chokyo' );
    }

    public static function _build_weekly_static( $key, $year_month, $monthly_rows, $month_start_str, $month_end_str, $carryover, $type ) {
        $carry_labor         = $carryover ? (int)$carryover['labor_min']         : 0;
        $carry_drive         = $carryover ? (int)$carryover['drive_min']         : 0;
        $carry_cargo         = $carryover ? (int)$carryover['cargo_min']         : 0;
        $carry_kousoku       = $carryover ? (int)$carryover['kousoku_min']       : 0;
        $carry_midnight      = $carryover ? (int)$carryover['midnight_min']      : 0;
        $carry_days          = $carryover ? (int)$carryover['days']              : 0;
        $carry_overtime      = $carryover ? (int)$carryover['overtime_min']      : 0;
        $carry_week_overtime = $carryover ? (int)$carryover['week_overtime_min'] : 0;

        $rows_by_date = [];
        foreach ( $monthly_rows as $r ) { $rows_by_date[ $r['date'] ] = $r; }

        $first_dow      = (int) date( 'w', strtotime( $month_start_str ) );
        $week_start_str = date( 'Y-m-d', strtotime( $month_start_str . ' -' . $first_dow . ' days' ) );
        $weeks = []; $week_index = 1;

        if ( $carry_days > 0 ) {
            $prev_end   = date( 'Y-m-t', strtotime( $month_start_str . ' -1 month' ) );
            $carry_start = date( 'Y-m-d', strtotime( $prev_end . ' -' . ( $carry_days - 1 ) . ' days' ) );
            $weeks[] = [
                'label' => '（前月繰越残業）', 'is_prev_carry' => true, 'is_carryover' => false,
                'disp_start' => date( 'Y/m/d', strtotime( $carry_start ) ), 'disp_end' => date( 'Y/m/d', strtotime( $prev_end ) ),
                'days' => $carry_days, 'kousoku_min' => $carry_kousoku, 'labor_min' => $carry_labor,
                'drive_min' => $carry_drive, 'cargo_min' => $carry_cargo, 'break_min' => $carry_kousoku - $carry_labor,
                'day_overtime_min' => $carry_overtime, 'week_overtime_min' => $carry_week_overtime,
                'confirmed_overtime' => 0, 'midnight_min' => $carry_midnight, 'carry_days' => 0,
            ];
        }

        while ( $week_start_str <= $month_end_str ) {
            $week_end_str = date( 'Y-m-d', strtotime( $week_start_str . ' +6 days' ) );
            $loop_end_str = min( $week_end_str, $month_end_str );
            $is_carryover  = strtotime( $week_start_str ) < strtotime( $month_start_str );
            $is_first_week = $is_carryover;

            $sum = array_fill_keys( [ 'kousoku_min','labor_min','drive_min','cargo_min','midnight_min','overtime_min','days' ], 0 );
            $c = new DateTime( max( $week_start_str, $month_start_str ) ); $le = new DateTime( $loop_end_str );
            while ( $c <= $le ) {
                $ds = $c->format('Y-m-d'); $r = $rows_by_date[ $ds ] ?? null;
                if ( $r && $r['has_data'] ) {
                    $sum['kousoku_min']  += (int)( $r['kousoku_min']  ?? 0 );
                    $sum['labor_min']    += (int)( $r['labor_min']    ?? 0 );
                    $sum['drive_min']    += (int)( $r['drive_min']    ?? 0 );
                    $sum['cargo_min']    += (int)( $r['cargo_min']    ?? 0 );
                    $sum['midnight_min'] += (int)( $r['midnight_min'] ?? 0 );
                    $sum['overtime_min'] += (int)( $r['overtime_min'] ?? 0 );
                    $sum['days']++;
                }
                $c->modify('+1 day');
            }

            $week_labor    = $sum['labor_min'] + ( $is_first_week ? $carry_labor : 0 );
            $week_overtime = $week_labor > 2400 ? $week_labor - 2400 : 0;
            $is_carry_out  = $week_end_str > $month_end_str && ! $is_carryover;
            $next_days     = 0;

            if ( $is_carry_out ) {
                $nc = new DateTime( date( 'Y-m-d', strtotime( $month_end_str . ' +1 day' ) ) );
                $ne = new DateTime( $week_end_str );
                while ( $nc <= $ne ) { $next_days++; $nc->modify('+1 day'); }
                $save_data = [
                    'labor_min' => $sum['labor_min'] - ( $is_first_week ? $carry_labor : 0 ),
                    'drive_min' => $sum['drive_min'] - ( $is_first_week ? $carry_drive : 0 ),
                    'cargo_min' => $sum['cargo_min'] - ( $is_first_week ? $carry_cargo : 0 ),
                    'kousoku_min' => $sum['kousoku_min'] - ( $is_first_week ? $carry_kousoku : 0 ),
                    'midnight_min' => $sum['midnight_min'] - ( $is_first_week ? $carry_midnight : 0 ),
                    'overtime_min' => $sum['overtime_min'],
                    'week_overtime_min' => $week_overtime,
                    'days' => $next_days,
                ];
                $next_month = date( 'Y-m', strtotime( $month_end_str . ' +1 month' ) );
                if ( $type === 'chokyo' ) AM_DB::save_chokyo_carryover( $key, $next_month, $save_data );
                else                     AM_DB::save_jiba_carryover( $key, $next_month, $save_data );
            }

            $day_overtime = $sum['overtime_min'];
            $confirmed    = max( $day_overtime, $week_overtime );

            $weeks[] = [
                'label'              => $is_carryover ? '（前月繰越）第' . $week_index . '週' : '第' . $week_index . '週',
                'is_prev_carry'      => false,
                'is_carryover'       => $is_carryover,
                'disp_start'         => date( 'Y/m/d', strtotime( max( $week_start_str, $month_start_str ) ) ),
                'disp_end'           => date( 'Y/m/d', strtotime( $loop_end_str ) ),
                'days'               => $sum['days'],
                'kousoku_min'        => $sum['kousoku_min']  - ( $is_first_week ? $carry_kousoku  : 0 ),
                'labor_min'          => $sum['labor_min']    - ( $is_first_week ? $carry_labor    : 0 ),
                'drive_min'          => $sum['drive_min']    - ( $is_first_week ? $carry_drive    : 0 ),
                'cargo_min'          => $sum['cargo_min']    - ( $is_first_week ? $carry_cargo    : 0 ),
                'break_min'          => ( $sum['kousoku_min'] - ( $is_first_week ? $carry_kousoku : 0 ) ) - ( $sum['labor_min'] - ( $is_first_week ? $carry_labor : 0 ) ),
                'midnight_min'       => $sum['midnight_min'] - ( $is_first_week ? $carry_midnight : 0 ),
                'day_overtime_min'   => $day_overtime,
                'week_overtime_min'  => $is_carry_out ? null : $week_overtime,
                'confirmed_overtime' => $is_carry_out ? null : $confirmed,
                'carry_days'         => $is_carry_out ? $next_days : 0,
            ];

            if ( ! $is_carryover ) $week_index++;
            $week_start_str = date( 'Y-m-d', strtotime( $week_start_str . ' +7 days' ) );
        }

        $total = array_fill_keys( [ 'kousoku_min','labor_min','drive_min','cargo_min','midnight_min','day_overtime_min','week_overtime_min','confirmed_overtime','days' ], 0 );
        foreach ( $weeks as $w ) {
            if ( $w['is_prev_carry'] ) continue;
            $total['kousoku_min']        += $w['kousoku_min'];
            $total['labor_min']          += $w['labor_min'];
            $total['drive_min']          += $w['drive_min'];
            $total['cargo_min']          += $w['cargo_min'];
            $total['midnight_min']       += $w['midnight_min'];
            $total['day_overtime_min']   += $w['day_overtime_min'];
            $total['week_overtime_min']  += $w['week_overtime_min']  ?? 0;
            $total['confirmed_overtime'] += $w['confirmed_overtime'] ?? 0;
            $total['days']               += $w['days'];
        }
        $total['break_min'] = $total['kousoku_min'] - $total['labor_min'];

        return [ 'weeks' => $weeks, 'total' => $total ];
    }

    public static function get_monthly_summary( $monthly_rows, $weekly, $crew_code, $year_month ) {
        $attendance = $absent = $holiday_work = $hayatai_min = 0;
        foreach ( $monthly_rows as $r ) {
            $kt = $r['default_kintai'] ?? '';
            if ( in_array( $kt, [ '出勤', '緊急出動' ], true ) ) $attendance++;
            if ( $kt === '欠勤' ) $absent++;
            if ( $r['kyuujitsu_kinmu'] ?? false ) $holiday_work++;
            $hayatai_min += (int) ( $r['hayatai_min'] ?? 0 );
        }
        $paidleave = AM_DB::get_paidleave_summary_by_crew( $crew_code, $year_month );
        return [
            'attendance'     => $attendance, 'absent' => $absent, 'holiday_work' => $holiday_work,
            'paid_consumed'  => $paidleave['consumed'], 'paid_remaining' => $paidleave['remaining'],
            'paid_has_data'  => $paidleave['has_data'],
            'labor_min'      => $weekly ? $weekly['total']['labor_min']          : null,
            'hayatai_min'    => $hayatai_min,
            'overtime_min'   => $weekly ? $weekly['total']['confirmed_overtime'] : null,
        ];
    }
}
