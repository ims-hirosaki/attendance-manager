<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Ajax — AJAXハンドラー専用クラス（長距離・地場・事務・休日マスタ 共通）
 */
class AM_Ajax {

    /* ===============================================================
       長距離 AJAX
       ============================================================= */

    public static function chokyo_kintai_save() {
        check_ajax_referer( 'am_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_custom_plugins' ) ) wp_die( -1 );

        global $wpdb;
        $table     = $wpdb->prefix . 'am_chokyo_kintai_log';
        $crew_code = sanitize_text_field( wp_unslash( $_POST['crew_code'] ?? '' ) );
        $rows_raw  = wp_unslash( $_POST['rows'] ?? [] );

        if ( ! $crew_code || ! is_array( $rows_raw ) ) {
            wp_send_json_error( [ 'message' => 'パラメータが不正です' ] );
        }

        $saved = 0;
        foreach ( $rows_raw as $row ) {
            $work_date     = sanitize_text_field( $row['date']          ?? '' );
            $kintai_type   = sanitize_text_field( $row['kintai_type']   ?? '' );
            $furikae_label = sanitize_text_field( $row['furikae_label'] ?? '' );
            $is_manual     = (int) ( $row['is_manual']   ?? 0 );
            $jiba          = (int) ( $row['jiba']        ?? 0 );
            $hayatai_min   = (int) ( $row['hayatai_min'] ?? 0 );
            $note          = sanitize_text_field( $row['note'] ?? '' );
            if ( ! $work_date ) continue;

            $wpdb->query( $wpdb->prepare(
                "INSERT INTO `{$table}`
                    (`crew_code`,`work_date`,`kintai_type`,`furikae_label`,`is_manual`,`jiba`,`hayatai_min`,`note`)
                 VALUES (%s,%s,%s,%s,%d,%d,%d,%s)
                 ON DUPLICATE KEY UPDATE
                    `kintai_type`=VALUES(`kintai_type`), `furikae_label`=VALUES(`furikae_label`),
                    `is_manual`=VALUES(`is_manual`), `jiba`=VALUES(`jiba`),
                    `hayatai_min`=VALUES(`hayatai_min`), `note`=VALUES(`note`), `updated_at`=NOW()",
                $crew_code, $work_date, $kintai_type, $furikae_label, $is_manual, $jiba, $hayatai_min, $note
            ) );
            $saved++;
        }
        wp_send_json_success( [ 'saved' => $saved ] );
    }

    public static function chokyo_get_monthly_summary() {
        check_ajax_referer( 'am_nonce', 'nonce' );
        if ( ! current_user_can( 'access_custom_plugins' ) ) wp_die( -1 );

        $crew_code  = sanitize_text_field( wp_unslash( $_POST['crew_code']  ?? '' ) );
        $year_month = sanitize_text_field( wp_unslash( $_POST['year_month'] ?? '' ) );
        if ( ! $crew_code || ! $year_month ) wp_send_json_error( [ 'message' => 'パラメータが不正です' ] );

        $emp_info     = AM_DB::get_emp_info_by_crew( $crew_code );
        $monthly_rows = AM_Compute_Chokyo::get_monthly_rows( $crew_code, $year_month, $emp_info['name'] );
        $weekly       = AM_Compute_Chokyo::get_weekly_summary( $crew_code, $year_month, $monthly_rows );
        $summary      = AM_Compute_Chokyo::get_monthly_summary( $monthly_rows, $weekly, $crew_code, $year_month );

        $summary['labor_str']    = AM_Compute_Chokyo::format_min( $summary['labor_min'] );
        $summary['hayatai_str']  = $summary['hayatai_min'] > 0 ? AM_Compute_Chokyo::format_min( $summary['hayatai_min'] ) : '';
        $summary['overtime_str'] = AM_Compute_Chokyo::format_min( $summary['overtime_min'] );
        $summary['unmatched_houtei_labor_str'] = AM_Compute_Chokyo::format_min( $summary['unmatched_houtei_labor_min'] );
        wp_send_json_success( $summary );
    }

    public static function chokyo_get_daily_rows() {
        check_ajax_referer( 'am_nonce', 'nonce' );
        if ( ! current_user_can( 'access_custom_plugins' ) ) wp_die( -1 );

        $crew_code  = sanitize_text_field( wp_unslash( $_POST['crew_code']  ?? '' ) );
        $year_month = sanitize_text_field( wp_unslash( $_POST['year_month'] ?? '' ) );
        if ( ! $crew_code || ! $year_month ) wp_send_json_error( [ 'message' => 'パラメータが不正です' ] );

        $emp_info     = AM_DB::get_emp_info_by_crew( $crew_code );
        $monthly_rows = AM_Compute_Chokyo::get_monthly_rows( $crew_code, $year_month, $emp_info['name'] );
        $alerts = $monthly_rows[0]['_alerts'] ?? [];
        $rows = [];
        foreach ( $monthly_rows as $r ) {
            $rows[] = [
                'date'         => $r['date'],
                'kintai_type'  => $r['default_kintai'] ?? '',
                'houtei_kinmu' => ! empty( $r['houtei_kinmu'] ),
                'shitei_kinmu' => ! empty( $r['shitei_kinmu'] ),
                'start_time'   => $r['start_time']      ?? '',
                'end_time'     => $r['end_time']         ?? '',
                'kousoku_min'  => AM_Compute_Chokyo::format_min( $r['kousoku_min'] ),
                'labor_min'    => AM_Compute_Chokyo::format_min( $r['labor_min'] ),
                'drive_min'    => AM_Compute_Chokyo::format_min( $r['drive_min'] ),
                'cargo_min'    => AM_Compute_Chokyo::format_min( $r['cargo_min'] ),
                'break_min'    => AM_Compute_Chokyo::format_min( $r['break_calc_min'] ),
                'midnight_min' => AM_Compute_Chokyo::format_min( $r['midnight_min'] ),
                'overtime_min' => AM_Compute_Chokyo::format_min( $r['overtime_min'] ),
            ];
        }
        wp_send_json_success( [ 'rows' => $rows, 'alerts' => $alerts ] );
    }

    public static function chokyo_get_weekly_rows() {
        check_ajax_referer( 'am_nonce', 'nonce' );
        if ( ! current_user_can( 'access_custom_plugins' ) ) wp_die( -1 );

        $crew_code  = sanitize_text_field( wp_unslash( $_POST['crew_code']  ?? '' ) );
        $year_month = sanitize_text_field( wp_unslash( $_POST['year_month'] ?? '' ) );
        if ( ! $crew_code || ! $year_month ) wp_send_json_error( [ 'message' => 'パラメータが不正です' ] );

        $emp_info     = AM_DB::get_emp_info_by_crew( $crew_code );
        $monthly_rows = AM_Compute_Chokyo::get_monthly_rows( $crew_code, $year_month, $emp_info['name'] );
        $weekly       = AM_Compute_Chokyo::get_weekly_summary( $crew_code, $year_month, $monthly_rows );
        wp_send_json_success( self::_format_weekly_result( $weekly ) );
    }

    /* ===============================================================
       地場・事務 AJAX
       ============================================================= */

    public static function jiba_kintai_save() {
        check_ajax_referer( 'am_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_custom_plugins' ) ) wp_die( -1 );

        global $wpdb;
        $table         = $wpdb->prefix . 'am_jiba_kintai_log';
        $employee_code = sanitize_text_field( wp_unslash( $_POST['employee_code'] ?? '' ) );
        $rows_raw      = wp_unslash( $_POST['rows'] ?? [] );

        if ( ! $employee_code || ! is_array( $rows_raw ) ) {
            wp_send_json_error( [ 'message' => 'パラメータが不正です' ] );
        }

        $saved = 0;
        foreach ( $rows_raw as $row ) {
            $work_date     = sanitize_text_field( $row['date']          ?? '' );
            $kintai_type   = sanitize_text_field( $row['kintai_type']   ?? '' );
            $furikae_label = sanitize_text_field( $row['furikae_label'] ?? '' );
            $is_manual     = (int) ( $row['is_manual']   ?? 0 );
            $chokyo        = (int) ( $row['chokyo']      ?? 0 );
            $hayatai_min   = (int) ( $row['hayatai_min'] ?? 0 );
            $note          = sanitize_text_field( $row['note'] ?? '' );
            if ( ! $work_date ) continue;

            $wpdb->query( $wpdb->prepare(
                "INSERT INTO `{$table}`
                    (`employee_code`,`work_date`,`kintai_type`,`furikae_label`,`is_manual`,`chokyo`,`hayatai_min`,`note`)
                 VALUES (%s,%s,%s,%s,%d,%d,%d,%s)
                 ON DUPLICATE KEY UPDATE
                    `kintai_type`=VALUES(`kintai_type`), `furikae_label`=VALUES(`furikae_label`),
                    `is_manual`=VALUES(`is_manual`), `chokyo`=VALUES(`chokyo`),
                    `hayatai_min`=VALUES(`hayatai_min`), `note`=VALUES(`note`), `updated_at`=NOW()",
                $employee_code, $work_date, $kintai_type, $furikae_label, $is_manual, $chokyo, $hayatai_min, $note
            ) );
            $saved++;
        }
        wp_send_json_success( [ 'saved' => $saved ] );
    }

    public static function jiba_get_monthly_summary() {
        check_ajax_referer( 'am_nonce', 'nonce' );
        if ( ! current_user_can( 'access_custom_plugins' ) ) wp_die( -1 );

        $employee_code = sanitize_text_field( wp_unslash( $_POST['employee_code'] ?? '' ) );
        $year_month    = sanitize_text_field( wp_unslash( $_POST['year_month']    ?? '' ) );
        if ( ! $employee_code || ! $year_month ) wp_send_json_error( [ 'message' => 'パラメータが不正です' ] );

        $emp_info     = AM_DB::get_emp_info_by_code( $employee_code );
        $monthly_rows = AM_Compute_Jiba::get_monthly_rows( $employee_code, $year_month, $emp_info['name'] );
        $weekly       = AM_Compute_Jiba::get_weekly_summary( $employee_code, $year_month, $monthly_rows );
        $summary      = AM_Compute_Jiba::get_monthly_summary( $monthly_rows, $weekly, $employee_code, $year_month );

        $summary['labor_str']    = AM_Compute_Chokyo::format_min( $summary['labor_min'] );
        $summary['hayatai_str']  = $summary['hayatai_min'] > 0 ? AM_Compute_Chokyo::format_min( $summary['hayatai_min'] ) : '';
        $summary['overtime_str'] = AM_Compute_Chokyo::format_min( $summary['overtime_min'] );
        $summary['unmatched_houtei_labor_str'] = AM_Compute_Chokyo::format_min( $summary['unmatched_houtei_labor_min'] );
        wp_send_json_success( $summary );
    }

    public static function jiba_get_daily_rows() {
        check_ajax_referer( 'am_nonce', 'nonce' );
        if ( ! current_user_can( 'access_custom_plugins' ) ) wp_die( -1 );

        $employee_code = sanitize_text_field( wp_unslash( $_POST['employee_code'] ?? '' ) );
        $year_month    = sanitize_text_field( wp_unslash( $_POST['year_month']    ?? '' ) );
        if ( ! $employee_code || ! $year_month ) wp_send_json_error( [ 'message' => 'パラメータが不正です' ] );

        $emp_info     = AM_DB::get_emp_info_by_code( $employee_code );
        $monthly_rows = AM_Compute_Jiba::get_monthly_rows( $employee_code, $year_month, $emp_info['name'] );
        $rows = [];
        foreach ( $monthly_rows as $r ) {
            $rows[] = [
                'date'         => $r['date'],
                'start_time'   => $r['start_time']      ?? '',
                'end_time'     => $r['end_time']         ?? '',
                'kousoku_min'  => AM_Compute_Chokyo::format_min( $r['kousoku_min'] ),
                'labor_min'    => AM_Compute_Chokyo::format_min( $r['labor_min'] ),
                'drive_min'    => AM_Compute_Chokyo::format_min( $r['drive_min'] ),
                'cargo_min'    => AM_Compute_Chokyo::format_min( $r['cargo_min'] ),
                'break_min'    => AM_Compute_Chokyo::format_min( $r['break_calc_min'] ),
                'midnight_min' => AM_Compute_Chokyo::format_min( $r['midnight_min'] ),
                'overtime_min' => AM_Compute_Chokyo::format_min( $r['overtime_min'] ),
            ];
        }
        wp_send_json_success( $rows );
    }

    public static function jiba_get_weekly_rows() {
        check_ajax_referer( 'am_nonce', 'nonce' );
        if ( ! current_user_can( 'access_custom_plugins' ) ) wp_die( -1 );

        $employee_code = sanitize_text_field( wp_unslash( $_POST['employee_code'] ?? '' ) );
        $year_month    = sanitize_text_field( wp_unslash( $_POST['year_month']    ?? '' ) );
        if ( ! $employee_code || ! $year_month ) wp_send_json_error( [ 'message' => 'パラメータが不正です' ] );

        $emp_info     = AM_DB::get_emp_info_by_code( $employee_code );
        $monthly_rows = AM_Compute_Jiba::get_monthly_rows( $employee_code, $year_month, $emp_info['name'] );
        $weekly       = AM_Compute_Jiba::get_weekly_summary( $employee_code, $year_month, $monthly_rows );
        wp_send_json_success( self::_format_weekly_result( $weekly ) );
    }

    /* ===============================================================
       週次データ整形（長距離・地場共通）
       ============================================================= */
    private static function _format_weekly_result( $weekly ) {
        $result = [];
        foreach ( $weekly['weeks'] as $w ) {
            $result[] = [
                'label'              => $w['label'],
                'is_prev_carry'      => $w['is_prev_carry'],
                'is_carryover'       => $w['is_carryover'],
                'carry_days'         => $w['carry_days'] ?? 0,
                'kousoku_min'        => AM_Compute_Chokyo::format_min( $w['kousoku_min'] ),
                'labor_min'          => AM_Compute_Chokyo::format_min( $w['labor_min'] ),
                'drive_min'          => AM_Compute_Chokyo::format_min( $w['drive_min'] ),
                'cargo_min'          => AM_Compute_Chokyo::format_min( $w['cargo_min'] ),
                'break_min'          => AM_Compute_Chokyo::format_min( $w['break_min'] ),
                'midnight_min'       => AM_Compute_Chokyo::format_min( $w['midnight_min'] ),
                'day_overtime_min'   => AM_Compute_Chokyo::format_min( $w['day_overtime_min'] ),
                'week_overtime_min'  => $w['is_carryover'] ? null : AM_Compute_Chokyo::format_min( $w['week_overtime_min'] ),
                'confirmed_overtime' => AM_Compute_Chokyo::format_min( $w['confirmed_overtime'] ),
                'is_carryover_badge' => $w['is_carryover'],
            ];
        }
        $total    = $weekly['total'];
        $result[] = [
            'label'              => '__total__',
            'kousoku_min'        => AM_Compute_Chokyo::format_min( $total['kousoku_min'] ),
            'labor_min'          => AM_Compute_Chokyo::format_min( $total['labor_min'] ),
            'drive_min'          => AM_Compute_Chokyo::format_min( $total['drive_min'] ),
            'cargo_min'          => AM_Compute_Chokyo::format_min( $total['cargo_min'] ),
            'break_min'          => AM_Compute_Chokyo::format_min( $total['break_min'] ),
            'midnight_min'       => AM_Compute_Chokyo::format_min( $total['midnight_min'] ),
            'day_overtime_min'   => AM_Compute_Chokyo::format_min( $total['day_overtime_min'] ),
            'week_overtime_min'  => AM_Compute_Chokyo::format_min( $total['week_overtime_min'] ),
            'confirmed_overtime' => AM_Compute_Chokyo::format_min( $total['confirmed_overtime'] ),
        ];
        return $result;
    }

    /* ===============================================================
       休日マスタ AJAX（共通）
       ============================================================= */

    public static function holiday_get_rules() {
        check_ajax_referer( 'am_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) wp_die( -1 );
        wp_send_json_success( AM_DB::get_holiday_rules() );
    }

    public static function holiday_save_rule() {
        check_ajax_referer( 'am_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) wp_die( -1 );

        global $wpdb;
        $table        = $wpdb->prefix . 'am_holiday_rules';
        $id           = isset( $_POST['id'] )             ? (int) $_POST['id']                                          : 0;
        $affil_id     = isset( $_POST['affiliation_id'] ) ? (int) $_POST['affiliation_id']                              : 0;
        $day_of_week  = isset( $_POST['day_of_week'] )    ? (int) $_POST['day_of_week']                                 : 0;
        $week_numbers = isset( $_POST['week_numbers'] )   ? sanitize_text_field( wp_unslash( $_POST['week_numbers'] ) ) : '';

        if ( ! $affil_id || $week_numbers === '' ) wp_send_json_error( [ 'message' => '所属と対象週は必須です' ] );

        $data = [ 'affiliation_id' => $affil_id, 'day_of_week' => $day_of_week, 'week_numbers' => $week_numbers, 'is_active' => 1 ];
        if ( $id > 0 ) { $wpdb->update( $table, $data, [ 'id' => $id ] ); }
        else            { $wpdb->insert( $table, $data ); $id = $wpdb->insert_id; }

        if ( $wpdb->last_error ) wp_send_json_error( [ 'message' => $wpdb->last_error ] );
        wp_send_json_success( [ 'id' => $id ] );
    }

    public static function holiday_delete_rule() {
        check_ajax_referer( 'am_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) wp_die( -1 );
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'am_holiday_rules', [ 'id' => (int) ( $_POST['id'] ?? 0 ) ] );
        wp_send_json_success();
    }

    public static function holiday_toggle_rule() {
        check_ajax_referer( 'am_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) wp_die( -1 );
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'am_holiday_rules',
            [ 'is_active' => (int) ( $_POST['is_active'] ?? 0 ) ],
            [ 'id'        => (int) ( $_POST['id']        ?? 0 ) ]
        );
        wp_send_json_success();
    }

    /* ===============================================================
       集計一覧 AJAX
       ============================================================= */

    public static function summary_list_get() {
        check_ajax_referer( 'am_nonce', 'nonce' );
        if ( ! current_user_can( 'access_custom_plugins' ) ) wp_die( -1 );

        $year_month = sanitize_text_field( wp_unslash( $_POST['year_month'] ?? '' ) );
        if ( ! $year_month ) wp_send_json_error( [ 'message' => 'パラメータが不正です' ] );

        $rows = [];

        // 長距離
        $chokyo_emps = AM_DB::get_employees_by_category( 'chokyo' );
        foreach ( $chokyo_emps['employees'] as $emp ) {
            $crew_code = $emp['crew_code'] ?? '';
            if ( $crew_code === '' ) continue;

            $emp_info     = AM_DB::get_emp_info_by_crew( $crew_code );
            $monthly_rows = AM_Compute_Chokyo::get_monthly_rows( $crew_code, $year_month, $emp_info['name'] );
            $weekly       = AM_Compute_Chokyo::get_weekly_summary( $crew_code, $year_month, $monthly_rows );
            $summary      = ! empty( $monthly_rows )
                ? AM_Compute_Chokyo::get_monthly_summary( $monthly_rows, $weekly, $crew_code, $year_month )
                : null;

            $rows[] = self::_format_summary_row( $emp['employee_code'], $emp['name'], 'chokyo', $summary );
        }

        // 地場・事務
        $jiba_emps = AM_DB::get_employees_by_category( 'jiba' );
        foreach ( $jiba_emps['employees'] as $emp ) {
            $employee_code = $emp['employee_code'] ?? '';
            if ( $employee_code === '' ) continue;

            $emp_info     = AM_DB::get_emp_info_by_code( $employee_code );
            $monthly_rows = AM_Compute_Jiba::get_monthly_rows( $employee_code, $year_month, $emp_info['name'] );
            $weekly       = AM_Compute_Jiba::get_weekly_summary( $employee_code, $year_month, $monthly_rows );
            $summary      = ! empty( $monthly_rows )
                ? AM_Compute_Jiba::get_monthly_summary( $monthly_rows, $weekly, $employee_code, $year_month )
                : null;

            $rows[] = self::_format_summary_row( $employee_code, $emp['name'], 'jiba', $summary );
        }

        wp_send_json_success( $rows );
    }

    private static function _format_summary_row( $employee_code, $name, $category, $summary ) {
        $labor_min    = $summary['labor_min']   ?? 0;
        $hayatai_min  = $summary['hayatai_min'] ?? 0;
        $overtime_min = $summary['overtime_min'] ?? 0;
        return [
            'employee_code'  => $employee_code,
            'name'           => $name,
            'category'       => $category,
            'attendance'     => $summary['attendance']     ?? 0,
            'absent'         => $summary['absent']         ?? 0,
            'holiday_work'   => $summary['holiday_work']   ?? 0,
            'unmatched_houtei_days' => $summary['unmatched_houtei_days'] ?? 0,
            'unmatched_houtei_labor_min' => $summary['unmatched_houtei_labor_min'] ?? 0,
            'paid_consumed'  => $summary['paid_consumed']  ?? 0,
            'paid_remaining' => $summary['paid_remaining'] ?? 0,
            'paid_has_data'  => $summary['paid_has_data']  ?? false,
            'labor_min'      => $labor_min,
            'hayatai_min'    => $hayatai_min,
            'overtime_min'   => $overtime_min,
            'labor_str'      => AM_Compute_Chokyo::format_min( $labor_min ),
            'hayatai_str'    => $hayatai_min > 0 ? AM_Compute_Chokyo::format_min( $hayatai_min ) : '',
            'overtime_str'   => AM_Compute_Chokyo::format_min( $overtime_min ),
            'unmatched_houtei_labor_str' => AM_Compute_Chokyo::format_min( $summary['unmatched_houtei_labor_min'] ?? 0 ),
        ];
    }

    /* ===============================================================
       種別管理 AJAX
       ============================================================= */

    /**
     * 職種一覧＋現在のマッピングを返す
     */
    public static function jobtype_get() {
        check_ajax_referer( 'am_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) wp_die( -1 );

        $job_types = function_exists( 'emp_get_job_types' ) ? emp_get_job_types() : [];
        $mappings  = AM_DB::get_job_type_mappings();

        $result = [];
        foreach ( $job_types as $jt ) {
            $name = $jt->name ?? '';
            $result[] = [
                'name'     => $name,
                'category' => $mappings[ $name ] ?? '',  // 未設定は空文字
            ];
        }
        wp_send_json_success( $result );
    }

    /**
     * 職種のマッピングを保存
     * POST: job_type_name, category（'chokyo' or 'jiba'）
     */
    public static function jobtype_save() {
        check_ajax_referer( 'am_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) wp_die( -1 );

        $job_type_name = sanitize_text_field( wp_unslash( $_POST['job_type_name'] ?? '' ) );
        $category      = sanitize_text_field( wp_unslash( $_POST['category']      ?? '' ) );

        if ( $job_type_name === '' ) {
            wp_send_json_error( [ 'message' => '職種名が空です' ] );
        }
        if ( ! in_array( $category, [ 'chokyo', 'jiba' ], true ) ) {
            wp_send_json_error( [ 'message' => '区分が不正です' ] );
        }

        $result = AM_DB::save_job_type_mapping( $job_type_name, $category );
        if ( $result ) {
            wp_send_json_success();
        } else {
            wp_send_json_error( [ 'message' => '保存に失敗しました' ] );
        }
    }
}
