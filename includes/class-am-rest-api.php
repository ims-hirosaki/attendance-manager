<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 勤怠データ取得用 REST API（Excel / Power Query 連携用）
 *
 *   GET /wp-json/attendance-manager/v1/employees[?category=chokyo|jiba]
 *   GET /wp-json/attendance-manager/v1/timesheet?employee_code=XXX&year_month=YYYY-MM[&refresh=1]
 *
 * 認証は WordPress のアプリケーションパスワード（Basic認証）を想定する。
 * 権限は専用ケイパビリティ am_export_data、または既存の access_custom_plugins。
 */
class AM_REST_API {

    const NAMESPACE_V1   = 'attendance-manager/v1';
    const CAP_EXPORT     = 'am_export_data';
    const ROLE_API       = 'api_reader';
    const CACHE_TTL      = DAY_IN_SECONDS;
    const VERSION_OPTION = 'am_api_cache_version';

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
        add_action( 'init',          [ __CLASS__, 'ensure_role' ] );
    }

    /**
     * 「API取得」ロールを用意する（FTPデプロイでは有効化フックが走らないため init で確認）。
     * ロールは他のプラグインとも共有する想定。既に存在する場合は作り直さず、
     * このプラグイン用のケイパビリティだけを追加する。
     */
    public static function ensure_role() {
        $role = get_role( self::ROLE_API );
        if ( ! $role ) {
            add_role( self::ROLE_API, 'API取得', [ 'read' => true ] );
            $role = get_role( self::ROLE_API );
        }
        if ( $role && ! $role->has_cap( self::CAP_EXPORT ) ) {
            $role->add_cap( self::CAP_EXPORT );
        }
    }

    public static function register_routes() {
        register_rest_route( self::NAMESPACE_V1, '/employees', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_employees' ],
            'permission_callback' => [ __CLASS__, 'check_permission' ],
            'args'                => [
                'category' => [ 'type' => 'string', 'enum' => [ 'chokyo', 'jiba' ] ],
            ],
        ] );
        register_rest_route( self::NAMESPACE_V1, '/timesheet', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_timesheet' ],
            'permission_callback' => [ __CLASS__, 'check_permission' ],
            'args'                => [
                'employee_code' => [ 'type' => 'string', 'required' => true ],
                'year_month'    => [
                    'type'     => 'string',
                    'required' => true,
                    'pattern'  => '^\d{4}-(0[1-9]|1[0-2])$',
                ],
                'refresh'       => [ 'type' => 'boolean', 'default' => false ],
            ],
        ] );
    }

    public static function check_permission() {
        if ( current_user_can( self::CAP_EXPORT ) || current_user_can( 'access_custom_plugins' ) ) {
            return true;
        }
        return new WP_Error( 'am_forbidden', '権限がありません。', [ 'status' => is_user_logged_in() ? 403 : 401 ] );
    }

    /* ---------------------------------------------------------------
     * 社員一覧（軽量）
     * ------------------------------------------------------------- */
    public static function get_employees( WP_REST_Request $request ) {
        $categories = $request['category'] ? [ $request['category'] ] : [ 'chokyo', 'jiba' ];
        $list = [];
        foreach ( $categories as $category ) {
            foreach ( self::employees_in_category( $category ) as $emp ) {
                $list[] = [
                    'employee_code'    => $emp['employee_code'],
                    'name'             => $emp['name'],
                    'category'         => $category,
                    'job_type_name'    => $emp['job_type_name'] ?? '',
                    'affiliation_name' => $emp['affiliation_name'] ?? '',
                ];
            }
        }
        return rest_ensure_response( $list );
    }

    /* ---------------------------------------------------------------
     * 個人別月次勤怠（日次明細・週次・月次サマリ）
     * ------------------------------------------------------------- */
    public static function get_timesheet( WP_REST_Request $request ) {
        $employee_code = trim( (string) $request['employee_code'] );
        $year_month    = (string) $request['year_month'];

        $found = null;
        foreach ( [ 'chokyo', 'jiba' ] as $category ) {
            foreach ( self::employees_in_category( $category ) as $emp ) {
                if ( (string) $emp['employee_code'] === $employee_code ) {
                    $found = [ 'category' => $category, 'emp' => $emp ];
                    break 2;
                }
            }
        }
        if ( ! $found ) {
            return new WP_Error( 'am_not_found', '該当する社員が見つかりません。', [ 'status' => 404 ] );
        }

        // 前月以前は確定済みとみなして1日キャッシュする。当月・未来月はキャッシュしない。
        $cacheable = $year_month < current_time( 'Y-m' );
        $cache_key = 'am_api_ts_' . md5( self::cache_version() . '|' . $employee_code . '|' . $year_month );
        if ( $cacheable && ! $request['refresh'] ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                $cached['cached'] = true;
                return rest_ensure_response( $cached );
            }
        }

        if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 120 );

        $category = $found['category'];
        $emp      = $found['emp'];
        if ( 'chokyo' === $category ) {
            $key = (int) ( $emp['employee_id'] ?? 0 );
            if ( ! $key ) {
                return new WP_Error( 'am_no_employee_id', '社員IDを特定できません。', [ 'status' => 422 ] );
            }
            $compute = 'AM_Compute_Chokyo';
        } else {
            $key     = $employee_code;
            $compute = 'AM_Compute_Jiba';
        }

        $rows    = $compute::get_monthly_rows( $key, $year_month, $emp['name'] );
        $weekly  = $compute::get_weekly_summary( $key, $year_month, $rows );
        $summary = ! empty( $rows ) ? $compute::get_monthly_summary( $rows, $weekly, $key, $year_month ) : [];

        // 集計CSVと同じ定義の「点呼時間含む総労働時間」。
        $labor_incl_tenko = (int) ( $summary['labor_min'] ?? 0 );
        $job_type         = $emp['job_type_name'] ?? '';
        if ( $year_month < AM_DB::get_hosei_start_month() && in_array( $job_type, [ '長距離', '郵便' ], true ) ) {
            $labor_incl_tenko += 10 * (int) ( $summary['attendance'] ?? 0 );
        }
        $summary['labor_min_incl_tenko'] = $labor_incl_tenko;

        $data = [
            'employee_code' => $employee_code,
            'name'          => $emp['name'],
            'category'      => $category,
            'job_type_name' => $job_type,
            'year_month'    => $year_month,
            'daily'         => self::add_hm( array_values( (array) $rows ) ),
            'weekly'        => self::add_hm( $weekly ),
            'summary'       => self::add_hm( $summary ),
            'cached'        => false,
            'generated_at'  => current_time( 'c' ),
        ];

        if ( $cacheable ) {
            set_transient( $cache_key, $data, self::CACHE_TTL );
        }
        return rest_ensure_response( $data );
    }

    /* ---------------------------------------------------------------
     * キャッシュ無効化（勤怠保存・各種設定変更・CSV取込時に呼ぶ）
     * ------------------------------------------------------------- */
    public static function invalidate_cache() {
        update_option( self::VERSION_OPTION, (string) microtime( true ), false );
    }

    private static function cache_version() {
        return (string) get_option( self::VERSION_OPTION, '0' );
    }

    /* ---------------------------------------------------------------
     * helpers
     * ------------------------------------------------------------- */
    private static function employees_in_category( $category ) {
        $result = AM_DB::get_employees_by_category( $category );
        $out = [];
        foreach ( (array) ( $result['employees'] ?? [] ) as $emp ) {
            $code = (string) ( $emp['employee_code'] ?? '' );
            if ( '' === $code || '―' === $code ) continue;
            $out[] = $emp;
        }
        return $out;
    }

    /**
     * 「*_min」の整数値に、Excelで見やすい H:MM 表記（*_min_hm）を併記する。
     * 元の分単位の値はそのまま残す（補正時間 hosei_min も同様）。
     */
    private static function add_hm( $data ) {
        if ( ! is_array( $data ) ) return $data;
        foreach ( $data as $k => $v ) {
            if ( is_array( $v ) ) {
                $data[ $k ] = self::add_hm( $v );
            } elseif ( is_string( $k ) && '_min' === substr( $k, -4 ) && is_numeric( $v ) ) {
                $data[ $k . '_hm' ] = AM_Compute_Chokyo::format_min( (int) $v );
            }
        }
        return $data;
    }
}
