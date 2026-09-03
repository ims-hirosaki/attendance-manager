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
        // MAT v3.2.0 以降は公開API（mat_get_daily_by_month）から丸め値・残業・深夜込みで取得する。
        // 未導入の環境では従来どおり直接 SELECT する。
        $mat_by_date = [];
        $employee_code_for_mat = $wpdb->get_var( $wpdb->prepare(
            "SELECT employee_code FROM `{$wpdb->prefix}emp_master`
             WHERE crew_code COLLATE utf8mb4_unicode_520_ci = %s LIMIT 1",
            $crew_code
        ) );
        if ( $employee_code_for_mat && $employee_code_for_mat !== '―' ) {
            if ( function_exists( 'mat_get_daily_by_month' ) ) {
                $mat_by_date = (array) mat_get_daily_by_month( $employee_code_for_mat, $year_month );
            } else {
                $mat_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT work_date, clock_in, clock_out, break_minutes
                     FROM `{$wpdb->prefix}mat_attendance_daily`
                     WHERE employee_code = %s AND work_date BETWEEN %s AND %s",
                    $employee_code_for_mat, $start_date, $end_date
                ), ARRAY_A );
                foreach ( (array) $mat_rows as $mr ) { $mat_by_date[ $mr['work_date'] ] = $mr; }
            }
        }

        $affiliation_id = AM_DB::get_affiliation_id_by_crew( $crew_code );
        $shitei_rules   = ( AM_DB::get_active_rules_by_affiliation() )[ $affiliation_id ] ?? [];
        $saved_kintai   = AM_DB::get_chokyo_saved_kintai( $crew_code, $year_month );
        $paidleave_dates = AM_DB::get_paidleave_consumed_dates( $employee_code_for_mat, $year_month );

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

            // 始業時刻（tenrec優先、なければkousoku）
            $start_time = '';
            if ( $t ) $start_time = trim( $t['g1_time'] ?? '' );
            if ( $start_time === '' && $k ) $start_time = substr( $k['start_time'] ?? '', 0, 5 );

            // 終業時刻（tenrec優先、なければkousoku）
            // 日跨ぎは 10:19(翌) ではなく 34:19 形式で表示（driver-reportと同じ）
            $end_time_raw = '';
            if ( $t ) $end_time_raw = self::get_last_g_time( $t );
            if ( $end_time_raw === '' && $k ) $end_time_raw = substr( $k['end_time'] ?? '', 0, 5 );

            $end_time    = $end_time_raw;
            $kousoku_min = $drive_min = $cargo_min = $labor_min = $break_calc_min = $overtime_min = $midnight_min = null;

            if ( $start_time !== '' && $end_time_raw !== '' ) {
                list( $sh, $sm ) = array_map( 'intval', explode( ':', $start_time ) );
                list( $eh, $em ) = array_map( 'intval', explode( ':', $end_time_raw ) );
                $start_total = $sh * 60 + $sm;
                $end_total   = $eh * 60 + $em;

                // 日跨ぎ判定（end_next_day フラグ または 終業 <= 始業）
                $is_next_day = ( $k && ( $k['end_next_day'] ?? 0 ) ) || ( $end_total <= $start_total );
                if ( $is_next_day ) {
                    $end_time  = ( $eh + 24 ) . ':' . str_pad( $em, 2, '0', STR_PAD_LEFT );
                    $end_total += 1440;
                }

                $kousoku_min = $end_total - $start_total;
            }

            if ( $k ) {
                // 運転時間・積卸時間
                $drive_min = isset( $k['drive_min'] ) && $k['drive_min'] !== null ? (int) $k['drive_min'] : null;
                $cargo_min = isset( $k['cargo_min'] ) && $k['cargo_min'] !== null ? (int) $k['cargo_min'] : null;

                // 労働時間 = 運転時間 + 積卸時間（driver-reportと同じロジック）
                if ( $drive_min !== null && $cargo_min === null ) {
                    $labor_min = $drive_min;
                } elseif ( $drive_min !== null && $cargo_min !== null ) {
                    $labor_min = $drive_min + $cargo_min;
                } else {
                    $labor_min = 0;
                }

                // 会社の手当計算ルールに合わせ、時間外深夜は深夜・残業の双方へ算入する。
                $regular_midnight_min  = isset( $k['midnight_min'] ) ? (int) $k['midnight_min'] : null;
                $overtime_source_min   = isset( $k['overtime_min'] ) ? (int) $k['overtime_min'] : null;
                $overtime_midnight_min = isset( $k['overtime_midnight_min'] ) ? (int) $k['overtime_midnight_min'] : null;

                $midnight_min = ( $regular_midnight_min !== null || $overtime_midnight_min !== null )
                    ? (int) ( $regular_midnight_min ?? 0 ) + (int) ( $overtime_midnight_min ?? 0 )
                    : null;
                $break_calc_min = $kousoku_min !== null ? max( 0, $kousoku_min - $labor_min ) : null;
                $overtime_min = ( $overtime_source_min !== null || $overtime_midnight_min !== null )
                    ? (int) ( $overtime_source_min ?? 0 ) + (int) ( $overtime_midnight_min ?? 0 )
                    : max( 0, $labor_min - 480 );
            }

            $is_shitei = self::is_shitei_holiday( $date_str, $dow_num, $shitei_rules );

            // has_data: kousoku_log または tenrec_daily どちらかにデータがあれば true
            $has_data = ( $k !== null ) || ( $t !== null );
            $default_kintai = $has_data ? '出勤' : ( $is_sun ? '法定休' : ( $is_shitei ? '所定休' : '' ) );

            $rows[] = [
                'date' => $date_str, 'dow' => $dow, 'dow_num' => $dow_num,
                'is_sun' => $is_sun, 'is_sat' => $is_sat, 'is_shitei_holiday' => $is_shitei,
                'has_data' => $has_data, 'default_kintai' => $default_kintai,
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
        // is_manual / jiba / hayatai_min / note は常に保存値を適用
        // kintai_type / furikae_label は is_manual = true のときのみ保存値を優先
        foreach ( $rows as &$r ) {
            $saved = $saved_kintai[ $r['date'] ] ?? null;
            if ( $saved === null ) continue;

            $r['is_manual']   = (bool) $saved['is_manual'];
            $r['jiba']        = (bool) ( $saved['jiba'] ?? false );
            $r['hayatai_min'] = (int)  ( $saved['hayatai_min'] ?? 0 );
            $r['note']        = $saved['note'] ?? '';

            if ( $r['is_manual'] ) {
                $r['default_kintai'] = $saved['kintai_type'];
                $r['furikae_label']  = $saved['furikae_label'];
            }
        }
        unset( $r );

        foreach ( $rows as &$r ) {
            if ( ! ( $r['jiba'] ?? false ) ) continue;
            $mat = $mat_by_date[ $r['date'] ] ?? null;
            if ( ! $mat ) continue;

            if ( isset( $mat['kousoku_minutes'] ) ) {
                // ---- MAT 公開API 版（v3.2.0〜） ----
                $r['start_time']     = $mat['rounded_clock_in']  !== '' ? $mat['rounded_clock_in']  : $mat['clock_in'];
                $r['end_time']       = $mat['rounded_clock_out'] !== '' ? $mat['rounded_clock_out'] : $mat['clock_out'];
                $r['kousoku_min']    = $mat['kousoku_minutes'];
                $r['labor_min']      = $mat['labor_minutes'];
                $r['break_calc_min'] = (int) ( $mat['break_minutes'] ?? 0 );
                $r['overtime_min']   = $mat['overtime_minutes'];
                $r['midnight_min']   = $mat['midnight_minutes'] ?? null;
            } else {
                // ---- 旧実装（MAT が v3.2.0 未満のとき） ----
                $ci = $mat['clock_in']  ? substr( $mat['clock_in'],  0, 5 ) : '';
                $co = $mat['clock_out'] ? substr( $mat['clock_out'], 0, 5 ) : '';
                $bm = isset( $mat['break_minutes'] ) && $mat['break_minutes'] !== null ? (int) $mat['break_minutes'] : 0;

                $r['start_time'] = $ci;
                if ( $ci !== '' && $co !== '' ) {
                    list( $sh, $sm ) = array_map( 'intval', explode( ':', $ci ) );
                    list( $eh, $em ) = array_map( 'intval', explode( ':', $co ) );
                    $st = $sh * 60 + $sm; $et = $eh * 60 + $em;
                    // 日跨ぎ → 24時間形式で表示
                    if ( $et <= $st ) {
                        $co = ( $eh + 24 ) . ':' . str_pad( $em, 2, '0', STR_PAD_LEFT );
                        $et += 1440;
                    }
                    $r['end_time'] = $co;
                    $kousoku = $et - $st; $labor = $kousoku - $bm;
                    $r['kousoku_min'] = $kousoku; $r['labor_min'] = max( 0, $labor );
                    $r['break_calc_min'] = $bm; $r['overtime_min'] = max( 0, $labor - 480 );
                } else {
                    $r['end_time'] = $co;
                    $r['kousoku_min'] = $r['labor_min'] = $r['break_calc_min'] = $r['overtime_min'] = null;
                }
            }
            $r['drive_min'] = $r['cargo_min'] = null;
            $r['has_data']  = true;
        }
        unset( $r );

        // 承認済み有給の消化日を勤怠種別へ反映（手動設定行は保持）
        foreach ( $rows as &$r ) {
            if ( ! $r['is_manual'] && isset( $paidleave_dates[ $r['date'] ] ) ) {
                $r['default_kintai'] = '有給';
                $r['furikae_label']  = '';
            }
        }
        unset( $r );

        // パス2補完：保存データの自動補正（手動設定行はスキップ）
        // ① データなしなのに出勤になっている行 → 曜日・休日で再判定
        // ② データがあるのに法定休・所定休になっている行 → 出勤に補正
        // ③ kintai_type が空の行 → データ・曜日・所定休日から自動判定
        foreach ( $rows as &$r ) {
            if ( $r['is_manual'] ) continue; // 手動設定行はスキップ
            if ( $r['default_kintai'] === '出勤' && ! $r['has_data'] ) {
                // データがないのに出勤は矛盾（手動変更でない場合のみ補正）
                if ( $r['is_sun'] )                { $r['default_kintai'] = '法定休'; }
                elseif ( $r['is_shitei_holiday'] ) { $r['default_kintai'] = '所定休'; }
                else                               { $r['default_kintai'] = ''; }
                continue;
            }
            if ( $r['default_kintai'] !== '' ) {
                // データがあるのに法定休・所定休は矛盾（手動変更でない場合のみ補正）
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

        // has_saved に関わらず常に自動計算を実行（is_manual フラグで手動行を保護）
        $rows = self::apply_auto_kintai( $rows );

        // ---- パス3：休日出勤フラグ判定 ----
        // 法定休出勤・所定休出勤をそれぞれ独立したフラグで管理
        $houtei_kinmu_count = 0; // 法定休出勤数
        $shitei_kinmu_count = 0; // 所定休出勤数
        $houtei_furi_count  = 0; // 法定振替休数
        $shitei_furi_count  = 0; // 所定振替休数

        foreach ( $rows as &$r ) {
            $r['houtei_kinmu'] = false; // 法定休出勤バッジ
            $r['shitei_kinmu'] = false; // 所定休出勤バッジ

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

        // 法定休出勤と法定振替休を日付順に対応付ける。
        // 対応しない法定休出勤は、日別表示には残すが週次の残業計算から除外する。
        $rows = self::mark_unmatched_houtei_work( $rows );

        // 法定休出勤 ↔ 法定振替休 のペア確認
        if ( ! empty( $rows ) ) {
            $pair_alerts = $rows[0]['_alerts'] ?? [];
            if ( $houtei_kinmu_count !== $houtei_furi_count ) {
                $pair_alerts[] = [
                    'type'    => 'warn',
                    'message' => sprintf(
                        '法定休出勤（%d回）と法定振替休（%d日）の数が一致していません。',
                        $houtei_kinmu_count, $houtei_furi_count
                    ),
                ];
            }
            if ( $shitei_kinmu_count !== $shitei_furi_count ) {
                $pair_alerts[] = [
                    'type'    => 'warn',
                    'message' => sprintf(
                        '所定休出勤（%d回）と所定振替休（%d日）の数が一致していません。',
                        $shitei_kinmu_count, $shitei_furi_count
                    ),
                ];
            }
            $rows[0]['_alerts'] = $pair_alerts;
        }

        return $rows;
    }

    /**
     * 法定振替休の数だけ、古い法定休出勤から順に振替済みとして対応付ける。
     * 現行のペア警告と同じく、対応関係は月内の日数で判定する。
     */
    public static function mark_unmatched_houtei_work( $rows ) {
        $remaining_furikae = 0;
        foreach ( $rows as $r ) {
            if ( ( $r['default_kintai'] ?? '' ) === '法定振替休' ) $remaining_furikae++;
        }

        foreach ( $rows as &$r ) {
            $r['houtei_furikae_taken']      = false;
            $r['unmatched_houtei_kinmu']    = false;
            if ( empty( $r['houtei_kinmu'] ) ) continue;

            if ( $remaining_furikae > 0 ) {
                $r['houtei_furikae_taken'] = true;
                $remaining_furikae--;
            } else {
                $r['unmatched_houtei_kinmu'] = true;
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

        // ① 所定休 2日超チェック
        // ※ 超過分は '' に戻すが、is_shitei_holiday フラグは保持する
        $shitei_count = 0;
        foreach ( $rows as &$r ) {
            if ( $r['is_manual'] ) continue; // 手動設定行はスキップ
            if ( $r['default_kintai'] === '所定休' ) {
                $shitei_count++;
                if ( $shitei_count > 2 ) $r['default_kintai'] = '';
            }
        }
        unset( $r );

        // 振替先候補の判定ヘルパー
        // 条件：勤怠種別未設定 かつ データなし かつ 日曜でない かつ 所定休日でない かつ 手動設定でない
        $is_furikae_candidate = function( $row ) {
            return $row['default_kintai'] === ''
                && ! $row['has_data']
                && ! $row['is_sun']
                && ! $row['is_shitei_holiday']
                && ! $row['is_manual'];
        };

        // ② 法定振替休割当（日曜出勤の場合）
        foreach ( $rows as $i => $r ) {
            if ( $r['is_manual'] ) continue; // 手動設定行は振替元にしない
            if ( $r['is_sun'] && $r['has_data'] && $r['default_kintai'] === '出勤' ) {
                $assigned = false;
                for ( $j = $i + 1; $j < count( $rows ); $j++ ) {
                    if ( $is_furikae_candidate( $rows[$j] ) ) {
                        $rows[$j]['default_kintai'] = '法定振替休';
                        $rows[$j]['furikae_label']  = date( 'm/d', strtotime( $r['date'] ) ) . '(日)の振替';
                        $assigned = true;
                        break;
                    }
                }
                if ( ! $assigned ) {
                    $warnings[] = [
                        'type'    => 'error',
                        'message' => date( 'm/d', strtotime( $r['date'] ) ) . '(日)の振替休を割り当てられる日がありません',
                    ];
                }
            }
        }

        // ③ 所定振替休割当（所定休日出勤の場合）
        foreach ( $rows as $i => $r ) {
            if ( $r['is_manual'] ) continue; // 手動設定行は振替元にしない
            if ( $r['is_shitei_holiday'] && $r['has_data'] && $r['default_kintai'] === '出勤' ) {
                $assigned = false;
                for ( $j = $i + 1; $j < count( $rows ); $j++ ) {
                    if ( $is_furikae_candidate( $rows[$j] ) ) {
                        $rows[$j]['default_kintai'] = '所定振替休';
                        $rows[$j]['furikae_label']  = date( 'm/d', strtotime( $r['date'] ) ) . 'の振替';
                        $assigned = true;
                        break;
                    }
                }
                if ( ! $assigned ) {
                    $warnings[] = [
                        'type'    => 'error',
                        'message' => date( 'm/d', strtotime( $r['date'] ) ) . 'の振替休を割り当てられる日がありません',
                    ];
                }
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
        // 旧データは列が未設定のため、従来の労働時間を週40時間判定に使用する。
        $carry_overtime_labor = $carryover && isset( $carryover['overtime_labor_min'] )
            ? (int) $carryover['overtime_labor_min']
            : $carry_labor;

        $rows_by_date = [];
        foreach ( $monthly_rows as $r ) { $rows_by_date[ $r['date'] ] = $r; }

        // 月初の曜日から週の開始日を計算（日曜始まり）
        $first_dow      = (int) date( 'w', strtotime( $month_start_str ) );
        $week_start_str = date( 'Y-m-d', strtotime( $month_start_str . ' -' . $first_dow . ' days' ) );

        $weeks      = [];
        $week_index = 1;

        // 前月繰越残業行（carry_days > 0 のときのみ表示）
        if ( $carry_days > 0 ) {
            $prev_month_end = date( 'Y-m-t', strtotime( $month_start_str . ' -1 month' ) );
            $carry_start    = date( 'Y-m-d', strtotime( $prev_month_end . ' -' . ( $carry_days - 1 ) . ' days' ) );
            $weeks[] = [
                'label'              => '（前月繰越残業）',
                'is_prev_carry'      => true,
                'is_carryover'       => false,
                'disp_start'         => date( 'Y/m/d', strtotime( $carry_start ) ),
                'disp_end'           => date( 'Y/m/d', strtotime( $prev_month_end ) ),
                'days'               => $carry_days,
                'kousoku_min'        => $carry_kousoku,
                'labor_min'          => $carry_labor,
                'drive_min'          => $carry_drive,
                'cargo_min'          => $carry_cargo,
                'break_min'          => $carry_kousoku - $carry_labor,
                'day_overtime_min'   => $carry_overtime,
                'week_overtime_min'  => $carry_week_overtime,
                'confirmed_overtime' => 0,
                'midnight_min'       => $carry_midnight,
                'carry_days'         => 0,
            ];
        }

        while ( $week_start_str <= $month_end_str ) {
            $week_end_str = date( 'Y-m-d', strtotime( $week_start_str . ' +6 days' ) );
            $loop_end_str = ( $week_end_str <= $month_end_str ) ? $week_end_str : $month_end_str;

            // 週が月末をまたぐ（翌月への残業繰越）
            $is_carryover = ( $week_end_str > $month_end_str );

            // 第1週かつ前月からの繰越がある場合
            $is_first_week = ( $week_index === 1 );

            // sumの初期化
            $sum = array_fill_keys(
                [ 'kousoku_min','labor_min','overtime_labor_min','drive_min','cargo_min','midnight_min','overtime_min','days' ], 0
            );

            // 第1週に前月繰越分を加算（週残業計算のため）
            if ( $is_first_week && $carry_days > 0 ) {
                $sum['labor_min']    += $carry_labor;
                $sum['overtime_labor_min'] += $carry_overtime_labor;
                $sum['drive_min']    += $carry_drive;
                $sum['cargo_min']    += $carry_cargo;
                $sum['kousoku_min']  += $carry_kousoku;
                $sum['midnight_min'] += $carry_midnight;
            }

            // 当月分の日次データを加算
            $cursor_str = $week_start_str;
            while ( $cursor_str <= $loop_end_str ) {
                if ( $cursor_str >= $month_start_str ) {
                    $r = $rows_by_date[ $cursor_str ] ?? null;
                    if ( $r && $r['has_data'] ) {
                        $sum['kousoku_min']  += (int)( $r['kousoku_min']  ?? 0 );
                        $sum['labor_min']    += (int)( $r['labor_min']    ?? 0 );
                        $sum['drive_min']    += (int)( $r['drive_min']    ?? 0 );
                        $sum['cargo_min']    += (int)( $r['cargo_min']    ?? 0 );
                        $sum['midnight_min'] += (int)( $r['midnight_min'] ?? 0 );
                        // 振替なしの法定休出勤は実績時間には含めるが、残業判定には含めない。
                        if ( empty( $r['unmatched_houtei_kinmu'] ) ) {
                            $sum['overtime_labor_min'] += (int)( $r['labor_min'] ?? 0 );
                            $sum['overtime_min']       += (int)( $r['overtime_min'] ?? 0 );
                        }
                    }
                    $sum['days']++;
                }
                $cursor_str = date( 'Y-m-d', strtotime( $cursor_str . ' +1 day' ) );
            }

            // 週残業 = 週の労働時間合計が40時間（2400分）超の分
            $week_overtime      = $sum['overtime_labor_min'] > 2400 ? $sum['overtime_labor_min'] - 2400 : 0;
            $confirmed_overtime = max( $sum['overtime_min'], $week_overtime );

            // 表示用の当月分のみのnet値（繰越分を除く）
            $net_kousoku  = $sum['kousoku_min']  - ( $is_first_week ? $carry_kousoku  : 0 );
            $net_labor    = $sum['labor_min']    - ( $is_first_week ? $carry_labor    : 0 );
            $net_drive    = $sum['drive_min']    - ( $is_first_week ? $carry_drive    : 0 );
            $net_cargo    = $sum['cargo_min']    - ( $is_first_week ? $carry_cargo    : 0 );
            $net_midnight = $sum['midnight_min'] - ( $is_first_week ? $carry_midnight : 0 );

            // 翌月繰越保存（月末をまたぐ週のみ）
            if ( $is_carryover ) {
                // 前月側の日数（翌月の「前月繰越残業」行の開始日計算に使う）
                $prev_days = 0;
                $pc = new DateTime( max( $week_start_str, $month_start_str ) );
                $pe = new DateTime( $month_end_str );
                while ( $pc <= $pe ) { $prev_days++; $pc->modify( '+1 day' ); }

                $next_month = date( 'Y-m', strtotime( $month_end_str . ' +1 month' ) );
                $save_data  = [
                    'labor_min'         => $net_labor,
                    'overtime_labor_min' => $sum['overtime_labor_min'] - ( $is_first_week ? $carry_overtime_labor : 0 ),
                    'drive_min'         => $net_drive,
                    'cargo_min'         => $net_cargo,
                    'kousoku_min'       => $net_kousoku,
                    'midnight_min'      => $net_midnight,
                    'overtime_min'      => $sum['overtime_min'],
                    'week_overtime_min' => $week_overtime,
                    'days'              => $prev_days,  // ← 前月側の日数（04/26〜04/30 = 5日）
                ];
                if ( $type === 'chokyo' ) AM_DB::save_chokyo_carryover( $key, $next_month, $save_data );
                else                     AM_DB::save_jiba_carryover( $key, $next_month, $save_data );
            }

            $label = $is_carryover ? '（残業繰越）' : ( '第' . $week_index . '週計' );

            $weeks[] = [
                'label'              => $label,
                'is_prev_carry'      => false,
                'is_carryover'       => $is_carryover,
                'disp_start'         => date( 'Y/m/d', strtotime( max( $week_start_str, $month_start_str ) ) ),
                'disp_end'           => date( 'Y/m/d', strtotime( $loop_end_str ) ),
                'days'               => $sum['days'],
                'kousoku_min'        => $net_kousoku,
                'labor_min'          => $net_labor,
                'drive_min'          => $net_drive,
                'cargo_min'          => $net_cargo,
                'break_min'          => $net_kousoku - $net_labor,
                'midnight_min'       => $net_midnight,
                'day_overtime_min'   => $sum['overtime_min'],
                // 月間合計では月末時点の値を使う。画面の週行は従来どおり繰越バッジを表示する。
                'week_overtime_min'  => $week_overtime,
                'confirmed_overtime' => $is_carryover ? null : $confirmed_overtime,
                'carry_days'         => $is_carryover ? $prev_days : 0,
            ];

            if ( ! $is_carryover ) $week_index++;
            $week_start_str = date( 'Y-m-d', strtotime( $week_start_str . ' +7 days' ) );
        }

        // 月間合計
        // 前月繰越行は当月実績ではないため除外する。
        // 月末の残業繰越行も当月1日〜末日の実績として通常時間・日残業・週残業に含める。
        // 確定残業だけは翌月に週が確定してから合計する。
        $total = array_fill_keys(
            [ 'kousoku_min','labor_min','drive_min','cargo_min','midnight_min',
              'day_overtime_min','week_overtime_min','confirmed_overtime','days' ], 0
        );
        foreach ( $weeks as $w ) {
            if ( $w['is_prev_carry'] ) continue;
            $total['kousoku_min']        += $w['kousoku_min'];
            $total['labor_min']          += $w['labor_min'];
            $total['drive_min']          += $w['drive_min'];
            $total['cargo_min']          += $w['cargo_min'];
            $total['midnight_min']       += $w['midnight_min'];
            $total['days']               += $w['days'];
            $total['day_overtime_min']   += $w['day_overtime_min'];
            $total['week_overtime_min']  += $w['week_overtime_min'] ?? 0;

            if ( $w['is_carryover'] ) continue;

            $total['confirmed_overtime'] += $w['confirmed_overtime'] ?? 0;
        }
        $total['break_min'] = $total['kousoku_min'] - $total['labor_min'];

        return [ 'weeks' => $weeks, 'total' => $total ];
    }

    public static function get_monthly_summary( $monthly_rows, $weekly, $crew_code, $year_month ) {
        $attendance = $absent = $holiday_work = $hayatai_min = 0;
        $unmatched_houtei_days = $unmatched_houtei_labor_min = 0;
        foreach ( $monthly_rows as $r ) {
            $kt = $r['default_kintai'] ?? '';
            if ( in_array( $kt, [ '出勤', '緊急出動' ], true ) ) $attendance++;
            if ( $kt === '欠勤' ) $absent++;
            if ( ( $r['houtei_kinmu'] ?? false ) || ( $r['shitei_kinmu'] ?? false ) ) $holiday_work++;
            if ( ! empty( $r['unmatched_houtei_kinmu'] ) ) {
                $unmatched_houtei_days++;
                $unmatched_houtei_labor_min += (int) ( $r['labor_min'] ?? 0 );
            }
            $hayatai_min += (int) ( $r['hayatai_min'] ?? 0 );
        }
        $paidleave = AM_DB::get_paidleave_summary_by_crew( $crew_code, $year_month );
        return [
            'attendance'     => $attendance, 'absent' => $absent, 'holiday_work' => $holiday_work,
            'unmatched_houtei_days' => $unmatched_houtei_days,
            'unmatched_houtei_labor_min' => $unmatched_houtei_labor_min,
            'paid_consumed'  => $paidleave['consumed'], 'paid_remaining' => $paidleave['remaining'],
            'paid_has_data'  => $paidleave['has_data'],
            'labor_min'      => $weekly ? $weekly['total']['labor_min']          : null,
            'hayatai_min'    => $hayatai_min,
            'overtime_min'   => $weekly ? $weekly['total']['confirmed_overtime'] : null,
        ];
    }
}
