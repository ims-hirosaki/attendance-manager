<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_DB — DB操作専用クラス（長距離・地場・事務 共通）
 */
class AM_DB {

    /* ---------------------------------------------------------------
     * 【長距離用】社員一覧（kousoku_log ベース）
     * ------------------------------------------------------------- */
    public static function get_employees_from_kousoku() {
        global $wpdb;
        $rows = $wpdb->get_results( "
            SELECT
                k.crew_code,
                COALESCE( m.name,          '（未登録）' ) AS name,
                COALESCE( m.employee_code, '―'          ) AS employee_code,
                COALESCE( jt.name,         ''           ) AS job_type_name,
                COALESCE( a.id,            0            ) AS affiliation_id,
                COALESCE( a.name,          '未所属'     ) AS affiliation_name
            FROM (
                SELECT DISTINCT crew_code
                FROM `{$wpdb->prefix}kousoku_log`
                WHERE crew_code IS NOT NULL AND crew_code <> ''
            ) k
            LEFT JOIN `{$wpdb->prefix}emp_master` m
                ON m.crew_code COLLATE utf8mb4_unicode_520_ci = k.crew_code COLLATE utf8mb4_unicode_520_ci
            LEFT JOIN `{$wpdb->prefix}mst_affiliation` a
                ON a.id = m.affiliation_id
            LEFT JOIN `{$wpdb->prefix}mst_job_type` jt
                ON jt.id = m.job_type_id
            ORDER BY CAST( COALESCE( NULLIF( m.employee_code, '―' ), '99999' ) AS UNSIGNED ) ASC
        ", ARRAY_A );
        return [
            'employees' => is_array( $rows ) ? $rows : [],
            'error'     => $wpdb->last_error,
        ];
    }

    /* ---------------------------------------------------------------
     * 【地場・事務用】社員一覧（mat_attendance_daily ベース）
     * ------------------------------------------------------------- */
    public static function get_employees_from_mat() {
        global $wpdb;
        $rows = $wpdb->get_results( "
            SELECT
                m.employee_code,
                COALESCE( m.name,  '（未登録）' ) AS name,
                COALESCE( jt.name, ''           ) AS job_type_name,
                COALESCE( a.id,    0            ) AS affiliation_id,
                COALESCE( a.name,  '未所属'     ) AS affiliation_name
            FROM (
                SELECT DISTINCT employee_code
                FROM `{$wpdb->prefix}mat_attendance_daily`
                WHERE employee_code IS NOT NULL AND employee_code <> ''
            ) d
            LEFT JOIN `{$wpdb->prefix}emp_master` m
                ON m.employee_code COLLATE utf8mb4_unicode_520_ci = d.employee_code COLLATE utf8mb4_unicode_520_ci
            LEFT JOIN `{$wpdb->prefix}mst_affiliation` a
                ON a.id = m.affiliation_id
            LEFT JOIN `{$wpdb->prefix}mst_job_type` jt
                ON jt.id = m.job_type_id
            ORDER BY CAST( COALESCE( NULLIF( m.employee_code, '' ), '99999' ) AS UNSIGNED ) ASC
        ", ARRAY_A );
        return [
            'employees' => is_array( $rows ) ? $rows : [],
            'error'     => $wpdb->last_error,
        ];
    }

    /* ---------------------------------------------------------------
     * 【長距離用】社員情報（crew_code 指定）
     * ------------------------------------------------------------- */
    public static function get_emp_info_by_crew( $crew_code ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "
            SELECT m.name, m.employee_code, m.crew_code,
                   COALESCE( a.name, '未所属' ) AS affiliation_name
            FROM `{$wpdb->prefix}emp_master` m
            LEFT JOIN `{$wpdb->prefix}mst_affiliation` a ON a.id = m.affiliation_id
            WHERE m.crew_code COLLATE utf8mb4_unicode_520_ci = %s
            LIMIT 1
        ", $crew_code ), ARRAY_A );
        return $row ?: [
            'name' => '（未登録）', 'employee_code' => '―',
            'crew_code' => $crew_code, 'affiliation_name' => '―',
        ];
    }

    /* ---------------------------------------------------------------
     * 【地場・事務用】社員情報（employee_code 指定）
     * ------------------------------------------------------------- */
    public static function get_emp_info_by_code( $employee_code ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "
            SELECT m.name, m.employee_code, m.crew_code,
                   COALESCE( a.name, '未所属' ) AS affiliation_name
            FROM `{$wpdb->prefix}emp_master` m
            LEFT JOIN `{$wpdb->prefix}mst_affiliation` a ON a.id = m.affiliation_id
            WHERE m.employee_code COLLATE utf8mb4_unicode_520_ci = %s
            LIMIT 1
        ", $employee_code ), ARRAY_A );
        return $row ?: [
            'name' => '（未登録）', 'employee_code' => $employee_code,
            'crew_code' => '', 'affiliation_name' => '―',
        ];
    }

    /* ---------------------------------------------------------------
     * affiliation_id 取得（crew_code 指定）
     * ------------------------------------------------------------- */
    public static function get_affiliation_id_by_crew( $crew_code ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT affiliation_id FROM `{$wpdb->prefix}emp_master`
             WHERE crew_code COLLATE utf8mb4_unicode_520_ci = %s LIMIT 1",
            $crew_code
        ) );
    }

    /* ---------------------------------------------------------------
     * affiliation_id 取得（employee_code 指定）
     * ------------------------------------------------------------- */
    public static function get_affiliation_id_by_code( $employee_code ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT affiliation_id FROM `{$wpdb->prefix}emp_master`
             WHERE employee_code COLLATE utf8mb4_unicode_520_ci = %s LIMIT 1",
            $employee_code
        ) );
    }

    /* ---------------------------------------------------------------
     * crew_code 取得（employee_code 指定）
     * ------------------------------------------------------------- */
    public static function get_crew_code_by_emp( $employee_code ) {
        global $wpdb;
        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT crew_code FROM `{$wpdb->prefix}emp_master`
             WHERE employee_code COLLATE utf8mb4_unicode_520_ci = %s LIMIT 1",
            $employee_code
        ) );
        return ( $val && $val !== '―' ) ? $val : '';
    }

    /* ---------------------------------------------------------------
     * 【長距離用】繰越データ取得
     * ------------------------------------------------------------- */
    public static function get_chokyo_carryover( $crew_code, $year_month ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$wpdb->prefix}am_chokyo_carryover`
             WHERE `crew_code` = %s AND `year_month` = %s",
            $crew_code, $year_month
        ), ARRAY_A );
    }

    /* ---------------------------------------------------------------
     * 【長距離用】繰越データ保存（UPSERT）
     * ------------------------------------------------------------- */
    public static function save_chokyo_carryover( $crew_code, $year_month, $data ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'am_chokyo_carryover';
        $existing = self::get_chokyo_carryover( $crew_code, $year_month );
        if ( $existing ) {
            $wpdb->update( $table, $data,
                [ 'crew_code' => $crew_code, 'year_month' => $year_month ], null, [ '%s', '%s' ] );
        } else {
            $wpdb->insert( $table, array_merge( $data,
                [ 'crew_code' => $crew_code, 'year_month' => $year_month ] ) );
        }
    }

    /* ---------------------------------------------------------------
     * 【地場・事務用】繰越データ取得
     * ------------------------------------------------------------- */
    public static function get_jiba_carryover( $employee_code, $year_month ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$wpdb->prefix}am_jiba_carryover`
             WHERE `employee_code` = %s AND `year_month` = %s",
            $employee_code, $year_month
        ), ARRAY_A );
    }

    /* ---------------------------------------------------------------
     * 【地場・事務用】繰越データ保存（UPSERT）
     * ------------------------------------------------------------- */
    public static function save_jiba_carryover( $employee_code, $year_month, $data ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'am_jiba_carryover';
        $existing = self::get_jiba_carryover( $employee_code, $year_month );
        if ( $existing ) {
            $wpdb->update( $table, $data,
                [ 'employee_code' => $employee_code, 'year_month' => $year_month ], null, [ '%s', '%s' ] );
        } else {
            $wpdb->insert( $table, array_merge( $data,
                [ 'employee_code' => $employee_code, 'year_month' => $year_month ] ) );
        }
    }

    /* ---------------------------------------------------------------
     * 【長距離用】保存済み勤怠取得
     * ------------------------------------------------------------- */
    public static function get_chokyo_saved_kintai( $crew_code, $year_month ) {
        global $wpdb;
        $start = $year_month . '-01';
        $end   = date( 'Y-m-t', strtotime( $start ) );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT work_date, kintai_type, furikae_label, is_manual, jiba, hayatai_min, note
             FROM `{$wpdb->prefix}am_chokyo_kintai_log`
             WHERE crew_code COLLATE utf8mb4_unicode_520_ci = %s
               AND work_date BETWEEN %s AND %s",
            $crew_code, $start, $end
        ), ARRAY_A );
        $map = [];
        foreach ( (array) $rows as $r ) { $map[ $r['work_date'] ] = $r; }
        return $map;
    }

    /* ---------------------------------------------------------------
     * 【地場・事務用】保存済み勤怠取得
     * ------------------------------------------------------------- */
    public static function get_jiba_saved_kintai( $employee_code, $year_month ) {
        global $wpdb;
        $start = $year_month . '-01';
        $end   = date( 'Y-m-t', strtotime( $start ) );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT work_date, kintai_type, furikae_label, is_manual, chokyo, hayatai_min, note
             FROM `{$wpdb->prefix}am_jiba_kintai_log`
             WHERE employee_code COLLATE utf8mb4_unicode_520_ci = %s
               AND work_date BETWEEN %s AND %s",
            $employee_code, $start, $end
        ), ARRAY_A );
        $map = [];
        foreach ( (array) $rows as $r ) { $map[ $r['work_date'] ] = $r; }
        return $map;
    }

    /* ---------------------------------------------------------------
     * 【共通】休日ルール取得（所属名付き）
     * ------------------------------------------------------------- */
    public static function get_holiday_rules() {
        global $wpdb;
        return $wpdb->get_results( "
            SELECT r.*, COALESCE( a.name, '（未設定）' ) AS affiliation_name
            FROM `{$wpdb->prefix}am_holiday_rules` r
            LEFT JOIN `{$wpdb->prefix}mst_affiliation` a ON a.id = r.affiliation_id
            ORDER BY a.sort_order ASC, r.day_of_week ASC
        ", ARRAY_A ) ?: [];
    }

    /* ---------------------------------------------------------------
     * 【共通】有効な休日ルールを affiliation_id キーで取得
     * ------------------------------------------------------------- */
    public static function get_active_rules_by_affiliation() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM `{$wpdb->prefix}am_holiday_rules` WHERE is_active = 1",
            ARRAY_A
        ) ?: [];
        $map = [];
        foreach ( $rows as $r ) { $map[ (int) $r['affiliation_id'] ][] = $r; }
        return $map;
    }

    /* ---------------------------------------------------------------
     * 【共通】有給サマリ取得（paid-leave-manager 連携）
     * ------------------------------------------------------------- */
    public static function get_paidleave_summary( $employee_code, $year_month ) {
        global $wpdb;
        $tbl_cons  = $wpdb->prefix . 'paidleave_consumptions';
        $tbl_grant = $wpdb->prefix . 'paidleave_grants';
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$tbl_cons}'" ) ) {
            return [ 'consumed' => 0, 'remaining' => 0, 'has_data' => false ];
        }
        $start    = $year_month . '-01';
        $end      = date( 'Y-m-t', strtotime( $start ) );
        $consumed = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE( SUM(consumed_days), 0 ) FROM `{$tbl_cons}`
             WHERE employee_code = %s AND consumed_date BETWEEN %s AND %s",
            $employee_code, $start, $end
        ) );
        $remaining = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE( SUM(remaining_days), 0 ) FROM `{$tbl_grant}`
             WHERE employee_code = %s AND is_expired = 0 AND expiry_date >= CURDATE() AND remaining_days > 0",
            $employee_code
        ) );
        return [ 'consumed' => $consumed, 'remaining' => $remaining, 'has_data' => true ];
    }

    /* ---------------------------------------------------------------
     * 【共通】有給消化日を日付キーで取得（paid-leave-manager 連携）
     * ------------------------------------------------------------- */
    public static function get_paidleave_consumed_dates( $employee_code, $year_month ) {
        global $wpdb;
        $table = $wpdb->prefix . 'paidleave_consumptions';
        if ( ! $employee_code || ! $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) ) {
            return [];
        }

        $start = $year_month . '-01';
        $end   = date( 'Y-m-t', strtotime( $start ) );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT consumed_date, SUM(consumed_days) AS consumed_days
             FROM `{$table}`
             WHERE employee_code = %s AND consumed_date BETWEEN %s AND %s
             GROUP BY consumed_date
             HAVING SUM(consumed_days) > 0",
            $employee_code, $start, $end
        ), ARRAY_A );

        $map = [];
        foreach ( (array) $rows as $row ) {
            $map[ $row['consumed_date'] ] = (float) $row['consumed_days'];
        }
        return $map;
    }

    /* ---------------------------------------------------------------
     * 【長距離用】有給サマリ取得（crew_code → employee_code 変換して参照）
     * ------------------------------------------------------------- */
    public static function get_paidleave_summary_by_crew( $crew_code, $year_month ) {
        global $wpdb;
        $employee_code = $wpdb->get_var( $wpdb->prepare(
            "SELECT employee_code FROM `{$wpdb->prefix}emp_master`
             WHERE crew_code COLLATE utf8mb4_unicode_520_ci = %s LIMIT 1",
            $crew_code
        ) );
        if ( ! $employee_code || $employee_code === '―' ) {
            return [ 'consumed' => 0, 'remaining' => 0, 'has_data' => false ];
        }
        return self::get_paidleave_summary( $employee_code, $year_month );
    }

    /* ---------------------------------------------------------------
     * 【種別管理】職種マッピング一覧取得
     * ------------------------------------------------------------- */
    public static function get_job_type_mappings() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT job_type_name, category FROM `{$wpdb->prefix}am_job_type_mapping`",
            ARRAY_A
        ) ?: [];
        $map = [];
        foreach ( $rows as $r ) {
            $map[ $r['job_type_name'] ] = $r['category'];
        }
        return $map;
    }

    /* ---------------------------------------------------------------
     * 【種別管理】職種マッピング保存（UPSERT）
     * ------------------------------------------------------------- */
    public static function save_job_type_mapping( $job_type_name, $category ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO `{$wpdb->prefix}am_job_type_mapping` (`job_type_name`, `category`)
             VALUES (%s, %s)
             ON DUPLICATE KEY UPDATE `category` = VALUES(`category`), `updated_at` = NOW()",
            $job_type_name, $category
        ) );
        return $wpdb->last_error ? false : true;
    }

    /* ---------------------------------------------------------------
     * 【長距離用】職種マッピングベースの社員一覧
     * 【地場・事務用】職種マッピングベースの社員一覧
     * category: 'chokyo' or 'jiba'
     * ------------------------------------------------------------- */
    public static function get_employees_by_category( $category ) {
        global $wpdb;

        // マッピング未設定の場合は従来ロジックにフォールバック
        $mapped_types = $wpdb->get_col( $wpdb->prepare(
            "SELECT job_type_name FROM `{$wpdb->prefix}am_job_type_mapping` WHERE category = %s",
            $category
        ) );

        if ( empty( $mapped_types ) ) {
            // マッピング未設定時：長距離はkousokuベース、地場はmatベース
            if ( $category === 'chokyo' ) return self::get_employees_from_kousoku();
            return self::get_employees_from_mat();
        }

        $placeholders = implode( ',', array_fill( 0, count( $mapped_types ), '%s' ) );

        if ( $category === 'chokyo' ) {
            // 長距離：emp_master の職種で絞り込み → crew_code で取得
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                        m.crew_code,
                        COALESCE( m.name,          '（未登録）' ) AS name,
                        COALESCE( m.employee_code, '―'          ) AS employee_code,
                        COALESCE( jt.name,         ''           ) AS job_type_name,
                        COALESCE( a.id,            0            ) AS affiliation_id,
                        COALESCE( a.name,          '未所属'     ) AS affiliation_name
                     FROM `{$wpdb->prefix}emp_master` m
                     LEFT JOIN `{$wpdb->prefix}mst_affiliation` a ON a.id = m.affiliation_id
                     LEFT JOIN `{$wpdb->prefix}mst_job_type` jt ON jt.id = m.job_type_id
                     WHERE jt.name IN ({$placeholders})
                       AND m.is_active = 1
                       AND m.crew_code IS NOT NULL AND m.crew_code <> ''
                     ORDER BY CAST( COALESCE( NULLIF( m.employee_code, '―' ), '99999' ) AS UNSIGNED ) ASC",
                    ...$mapped_types
                ),
                ARRAY_A
            );
        } else {
            // 地場・事務：emp_master の職種で絞り込み → employee_code で取得
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                        m.employee_code,
                        COALESCE( m.name, '（未登録）' ) AS name,
                        COALESCE( jt.name, ''           ) AS job_type_name,
                        COALESCE( a.id,   0            ) AS affiliation_id,
                        COALESCE( a.name, '未所属'     ) AS affiliation_name
                     FROM `{$wpdb->prefix}emp_master` m
                     LEFT JOIN `{$wpdb->prefix}mst_affiliation` a ON a.id = m.affiliation_id
                     LEFT JOIN `{$wpdb->prefix}mst_job_type` jt ON jt.id = m.job_type_id
                     WHERE jt.name IN ({$placeholders})
                       AND m.is_active = 1
                     ORDER BY CAST( COALESCE( NULLIF( m.employee_code, '' ), '99999' ) AS UNSIGNED ) ASC",
                    ...$mapped_types
                ),
                ARRAY_A
            );
        }

        return [
            'employees' => is_array( $rows ) ? $rows : [],
            'error'     => $wpdb->last_error,
        ];
    }
}
