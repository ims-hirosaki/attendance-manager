<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Compute_Jiba — 地場・事務用計算ロジック
 *
 * デフォルト: mat_attendance_daily（打刻データ）ベース
 * 長距離フラグ ON 時: kousoku_log + tenrec_daily に切り替え
 */
class AM_Compute_Jiba {

    public static function format_min( $min ) {
        return AM_Compute_Chokyo::format_min( $min );
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
    public static function get_monthly_rows( $employee_code, $year_month, $emp_name ) {
        global $wpdb;

        $start_date = $year_month . '-01';
        $end_date   = date( 'Y-m-t', strtotime( $start_date ) );
        $ymd_start  = str_replace( '-', '', $start_date );
        $ymd_end    = str_replace( '-', '', $end_date );

        // mat_attendance_daily（デフォルト）
        $mat_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT work_date, clock_in, clock_out, break_minutes
             FROM `{$wpdb->prefix}mat_attendance_daily`
             WHERE employee_code COLLATE utf8mb4_unicode_520_ci = %s
               AND work_date BETWEEN %s AND %s",
            $employee_code, $start_date, $end_date
        ), ARRAY_A );
        $mat_by_date = [];
        foreach ( (array) $mat_rows as $mr ) { $mat_by_date[ $mr['work_date'] ] = $mr; }

        // kousoku_log（長距離フラグON日用）
        $crew_code = AM_DB::get_crew_code_by_emp( $employee_code );
        $kousoku_by_date = [];
        if ( $crew_code !== '' ) {
            $kousoku_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}kousoku_log`
                 WHERE crew_code COLLATE utf8mb4_unicode_520_ci = %s
                   AND work_date BETWEEN %s AND %s ORDER BY work_date ASC",
                $crew_code, $start_date, $end_date
            ), ARRAY_A );
            foreach ( (array) $kousoku_rows as $r ) { $kousoku_by_date[ $r['work_date'] ] = $r; }
        }

        // tenrec_daily（長距離フラグON日用）
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
                if ( trim( $entry['driver'] ?? '' ) === $emp_name ) { $tenrec_by_date[ $date ] = $entry; break; }
            }
        }

        $affiliation_id = AM_DB::get_affiliation_id_by_code( $employee_code );
        $shitei_rules   = ( AM_DB::get_active_rules_by_affiliation() )[ $affiliation_id ] ?? [];
        $saved_kintai   = AM_DB::get_jiba_saved_kintai( $employee_code, $year_month );
        // kintai_type が空でない行が1件以上ある場合のみ has_saved = true
        $has_saved = ! empty( array_filter( $saved_kintai, function( $r ) {
            return $r['kintai_type'] !== '';
        } ) );

        $dow_ja = [ 'Sun'=>'日','Mon'=>'月','Tue'=>'火','Wed'=>'水','Thu'=>'木','Fri'=>'金','Sat'=>'土' ];
        $rows = []; $cursor = new DateTime( $start_date ); $last = new DateTime( $end_date );

        // ---- パス1：MATデータをデフォルトとして全日付分生成 ----
        while ( $cursor <= $last ) {
            $date_str = $cursor->format('Y-m-d');
            $dow_num  = (int) $cursor->format('w');
            $dow      = $dow_ja[ $cursor->format('D') ];
            $is_sun   = $dow_num === 0;
            $is_sat   = $dow_num === 6;

            $mat = $mat_by_date[ $date_str ] ?? null;
            $start_time = $end_time = '';
            $kousoku_min = $labor_min = $break_calc_min = $overtime_min = null;

            if ( $mat ) {
                $ci = $mat['clock_in']  ? substr( $mat['clock_in'],  0, 5 ) : '';
                $co = $mat['clock_out'] ? substr( $mat['clock_out'], 0, 5 ) : '';
                $bm = isset( $mat['break_minutes'] ) && $mat['break_minutes'] !== null ? (int) $mat['break_minutes'] : 0;
                $start_time = $ci;
                if ( $ci !== '' && $co !== '' ) {
                    list( $sh, $sm ) = array_map( 'intval', explode( ':', $ci ) );
                    list( $eh, $em ) = array_map( 'intval', explode( ':', $co ) );
                    $st = $sh * 60 + $sm; $et = $eh * 60 + $em;
                    // 日跨ぎ → 24時間形式で表示
                    if ( $et <= $st ) {
                        $co = ( $eh + 24 ) . ':' . str_pad( $em, 2, '0', STR_PAD_LEFT );
                        $et += 1440;
                    }
                    $end_time = $co;
                    $kousoku = $et - $st; $labor = $kousoku - $bm;
                    $kousoku_min = $kousoku; $labor_min = max( 0, $labor );
                    $break_calc_min = $bm; $overtime_min = max( 0, $labor - 480 );
                } else {
                    $end_time = $co;
                }
            }

            $is_shitei = self::is_shitei_holiday( $date_str, $dow_num, $shitei_rules );
            // has_data: mat または chokyo フラグON時のkousoku どちらかにデータがあれば true
            $has_data = ( $mat !== null );
            $default_kintai = $has_data ? '出勤' : ( $is_sun ? '法定休' : ( $is_shitei ? '所定休' : '' ) );

            $rows[] = [
                'date' => $date_str, 'dow' => $dow, 'dow_num' => $dow_num,
                'is_sun' => $is_sun, 'is_sat' => $is_sat, 'is_shitei_holiday' => $is_shitei,
                'has_data' => $has_data, 'default_kintai' => $default_kintai,
                'furikae_label' => '', 'is_manual' => false, 'chokyo' => false,
                'hayatai_min' => 0, 'note' => '',
                'start_time' => $start_time, 'end_time' => $end_time,
                'kousoku_min' => $kousoku_min, 'labor_min' => $labor_min,
                'drive_min' => null, 'cargo_min' => null,
                'break_calc_min' => $break_calc_min, 'overtime_min' => $overtime_min,
                'midnight_min' => null,
            ];
            $cursor->modify('+1 day');
        }

        // ---- パス2：保存データ適用 + 長距離フラグON日を kousoku に切り替え ----
        if ( $has_saved ) {
            foreach ( $rows as &$r ) {
                $saved = $saved_kintai[ $r['date'] ] ?? null;
                if ( $saved !== null ) {
                    $r['default_kintai'] = $saved['kintai_type'];
                    $r['furikae_label']  = $saved['furikae_label'];
                    $r['is_manual']      = (bool) $saved['is_manual'];
                    $r['chokyo']         = (bool) ( $saved['chokyo'] ?? false );
                    $r['hayatai_min']    = (int)  ( $saved['hayatai_min'] ?? 0 );
                    $r['note']           = $saved['note'] ?? '';
                }
            }
            unset( $r );

            foreach ( $rows as &$r ) {
                if ( ! ( $r['chokyo'] ?? false ) ) continue;
                $k = $kousoku_by_date[ $r['date'] ] ?? null;
                $t = $tenrec_by_date[ $r['date'] ]  ?? null;

                $start_time = '';
                if ( $t ) $start_time = trim( $t['g1_time'] ?? '' );
                if ( $start_time === '' && $k ) $start_time = substr( $k['start_time'] ?? '', 0, 5 );

                $end_time = '';
                if ( $t ) $end_time = self::get_last_g_time( $t );
                if ( $end_time === '' && $k ) {
                    $end_time = substr( $k['end_time'] ?? '', 0, 5 );
                    if ( $k['end_next_day'] ?? 0 ) $end_time .= '(翌)';
                }

                $r['start_time'] = $start_time;
                if ( $k ) {
                    $drive_min = isset( $k['drive_min'] ) && $k['drive_min'] !== null ? (int) $k['drive_min'] : null;
                    $cargo_min = isset( $k['cargo_min'] ) && $k['cargo_min'] !== null ? (int) $k['cargo_min'] : null;
                    if ( $drive_min !== null && $cargo_min === null )      { $labor = $drive_min; }
                    elseif ( $drive_min !== null && $cargo_min !== null )  { $labor = $drive_min + $cargo_min; }
                    else                                                   { $labor = 0; }
                    // 始業・終業から拘束時間を計算（driver-reportと同じロジック）
                    $kousoku = null;
                    if ( $start_time !== '' && $end_time !== '' ) {
                        list( $sh2, $sm2 ) = array_map( 'intval', explode( ':', $start_time ) );
                        list( $eh2, $em2 ) = array_map( 'intval', explode( ':', $end_time ) );
                        $st2 = $sh2 * 60 + $sm2; $et2 = $eh2 * 60 + $em2;
                        if ( $et2 <= $st2 ) {
                            $end_time = ( $eh2 + 24 ) . ':' . str_pad( $em2, 2, '0', STR_PAD_LEFT );
                            $et2 += 1440;
                        }
                        $kousoku = $et2 - $st2;
                    }
                    $r['end_time']       = $end_time;
                    $r['kousoku_min']    = $kousoku;
                    $r['labor_min']      = $labor;
                    $r['drive_min']      = $drive_min;
                    $r['cargo_min']      = $cargo_min;
                    $r['midnight_min']   = $k['midnight_min'] !== null ? (int) $k['midnight_min'] : null;
                    $r['break_calc_min'] = $kousoku !== null ? max( 0, $kousoku - $labor ) : null;
                    $r['overtime_min']   = $labor > 480 ? $labor - 480 : 0;
                    $r['has_data']       = true;
                } else {
                    $r['end_time']    = $end_time;
                    $r['kousoku_min'] = $r['labor_min'] = $r['drive_min'] = $r['cargo_min'] = $r['break_calc_min'] = $r['overtime_min'] = $r['midnight_min'] = null;
                }
            }
            unset( $r );

            // パス2補完：保存データの自動補正
            // ① データなしなのに出勤になっている行 → 曜日・休日で再判定
            // ② データがあるのに法定休・所定休になっている行 → 出勤に補正
            // ③ kintai_type が空の行 → データ・曜日・所定休日から自動判定
            foreach ( $rows as &$r ) {
                if ( $r['default_kintai'] === '出勤' && ! $r['has_data'] ) {
                    // データがないのに出勤は矛盾 → 曜日・休日で再判定
                    if ( $r['is_sun'] )                { $r['default_kintai'] = '法定休'; }
                    elseif ( $r['is_shitei_holiday'] ) { $r['default_kintai'] = '所定休'; }
                    else                               { $r['default_kintai'] = ''; }
                    continue;
                }
                if ( $r['default_kintai'] !== '' ) {
                    // データがあるのに法定休・所定休は矛盾 → 出勤に補正
                    if ( $r['has_data'] && in_array( $r['default_kintai'], [ '法定休', '所定休' ], true ) ) {
                        $r['default_kintai'] = '出勤';
                    }
                    continue;
                }
                // kintai_type が空の行を自動判定
                if ( $r['has_data'] )          { $r['default_kintai'] = '出勤';   continue; }
                if ( $r['is_sun'] )            { $r['default_kintai'] = '法定休'; continue; }
                if ( $r['is_shitei_holiday'] ) { $r['default_kintai'] = '所定休'; }
            }
            unset( $r );

            $rows = AM_Compute_Chokyo::check_alerts_only( $rows );
        } else {
            $rows = AM_Compute_Chokyo::apply_auto_kintai( $rows );
        }

        // ---- パス3：法定休出勤・所定休出勤フラグ判定 ----
        $houtei_kinmu_count = 0;
        $shitei_kinmu_count = 0;
        $houtei_furi_count  = 0;
        $shitei_furi_count  = 0;

        foreach ( $rows as &$r ) {
            $r['houtei_kinmu'] = false;
            $r['shitei_kinmu'] = false;
            if ( $r['has_data'] && $r['default_kintai'] === '出勤' ) {
                if ( $r['is_sun'] ) {
                    $r['houtei_kinmu'] = true;
                    $houtei_kinmu_count++;
                } elseif ( $r['is_shitei_holiday'] ) {
                    $r['shitei_kinmu'] = true;
                    $shitei_kinmu_count++;
                }
            }
            if ( $r['default_kintai'] === '法定振替休' ) $houtei_furi_count++;
            if ( $r['default_kintai'] === '所定振替休' ) $shitei_furi_count++;
        }
        unset( $r );

        if ( ! empty( $rows ) ) {
            $pair_alerts = $rows[0]['_alerts'] ?? [];
            if ( $houtei_kinmu_count !== $houtei_furi_count ) {
                $pair_alerts[] = [ 'type' => 'warn', 'message' => sprintf( '法定休出勤（%d回）と法定振替休（%d日）の数が一致していません。', $houtei_kinmu_count, $houtei_furi_count ) ];
            }
            if ( $shitei_kinmu_count !== $shitei_furi_count ) {
                $pair_alerts[] = [ 'type' => 'warn', 'message' => sprintf( '所定休出勤（%d回）と所定振替休（%d日）の数が一致していません。', $shitei_kinmu_count, $shitei_furi_count ) ];
            }
            $rows[0]['_alerts'] = $pair_alerts;
        }

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

    public static function get_weekly_summary( $employee_code, $year_month, $monthly_rows ) {
        $month_start_str = $year_month . '-01';
        $month_end_str   = date( 'Y-m-t', strtotime( $month_start_str ) );
        $carryover       = AM_DB::get_jiba_carryover( $employee_code, $year_month );
        // _build_weekly は AM_Compute_Chokyo に共通実装されているため委譲
        return AM_Compute_Chokyo::_build_weekly_static( $employee_code, $year_month, $monthly_rows, $month_start_str, $month_end_str, $carryover, 'jiba' );
    }

    public static function get_monthly_summary( $monthly_rows, $weekly, $employee_code, $year_month ) {
        $attendance = $absent = $holiday_work = $hayatai_min = 0;
        foreach ( $monthly_rows as $r ) {
            $kt = $r['default_kintai'] ?? '';
            if ( in_array( $kt, [ '出勤', '緊急出動' ], true ) ) $attendance++;
            if ( $kt === '欠勤' ) $absent++;
            if ( ( $r['houtei_kinmu'] ?? false ) || ( $r['shitei_kinmu'] ?? false ) ) $holiday_work++;
            $hayatai_min += (int) ( $r['hayatai_min'] ?? 0 );
        }
        $paidleave = AM_DB::get_paidleave_summary( $employee_code, $year_month );
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