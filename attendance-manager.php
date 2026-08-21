<?php
/**
 * Plugin Name: 勤怠管理
 * Description: 長距離ドライバー・事務・地場の勤怠データを管理するプラグイン
 * Version:     1.1.1
 * Author:      有限会社たんぽぽ運送
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'AM_VERSION' ) )    define( 'AM_VERSION',    '1.1.1' );
if ( ! defined( 'AM_PLUGIN_DIR' ) ) define( 'AM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'AM_PLUGIN_URL' ) ) define( 'AM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AM_PLUGIN_DIR . 'includes/class-am-db.php';
require_once AM_PLUGIN_DIR . 'includes/class-am-compute-chokyo.php';
require_once AM_PLUGIN_DIR . 'includes/class-am-compute-jiba.php';
require_once AM_PLUGIN_DIR . 'includes/class-am-ajax.php';

if ( ! class_exists( 'Tanpopo_AttendanceManager' ) ) :

class Tanpopo_AttendanceManager {

    const KINTAI_TYPES = [ '出勤', '法定休', '法定振替休', '所定休', '所定振替休', '有給', '欠勤', '緊急出動' ];

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init',            [ $this, 'migrate_existing_tables' ] );
        register_activation_hook( __FILE__,  [ $this, 'activate' ] );

        // --- 長距離 AJAX ---
        add_action( 'wp_ajax_am_chokyo_kintai_save',         [ 'AM_Ajax', 'chokyo_kintai_save' ] );
        add_action( 'wp_ajax_am_chokyo_get_monthly_summary', [ 'AM_Ajax', 'chokyo_get_monthly_summary' ] );
        add_action( 'wp_ajax_am_chokyo_get_daily_rows',      [ 'AM_Ajax', 'chokyo_get_daily_rows' ] );
        add_action( 'wp_ajax_am_chokyo_get_weekly_rows',     [ 'AM_Ajax', 'chokyo_get_weekly_rows' ] );

        // --- 地場・事務 AJAX ---
        add_action( 'wp_ajax_am_jiba_kintai_save',           [ 'AM_Ajax', 'jiba_kintai_save' ] );
        add_action( 'wp_ajax_am_jiba_get_monthly_summary',   [ 'AM_Ajax', 'jiba_get_monthly_summary' ] );
        add_action( 'wp_ajax_am_jiba_get_daily_rows',        [ 'AM_Ajax', 'jiba_get_daily_rows' ] );
        add_action( 'wp_ajax_am_jiba_get_weekly_rows',       [ 'AM_Ajax', 'jiba_get_weekly_rows' ] );

        // --- 休日マスタ AJAX ---
        add_action( 'wp_ajax_am_holiday_get_rules',          [ 'AM_Ajax', 'holiday_get_rules' ] );
        add_action( 'wp_ajax_am_holiday_save_rule',          [ 'AM_Ajax', 'holiday_save_rule' ] );
        add_action( 'wp_ajax_am_holiday_delete_rule',        [ 'AM_Ajax', 'holiday_delete_rule' ] );
        add_action( 'wp_ajax_am_holiday_toggle_rule',        [ 'AM_Ajax', 'holiday_toggle_rule' ] );

        // --- 種別管理 AJAX ---
        add_action( 'wp_ajax_am_jobtype_get',                [ 'AM_Ajax', 'jobtype_get' ] );
        add_action( 'wp_ajax_am_jobtype_save',               [ 'AM_Ajax', 'jobtype_save' ] );

        // --- 集計一覧 AJAX ---
        add_action( 'wp_ajax_am_summary_list_get',           [ 'AM_Ajax', 'summary_list_get' ] );
    }

    public static function format_min( $min ) {
        return AM_Compute_Chokyo::format_min( $min );
    }

    /* ---------------------------------------------------------------
     * プラグイン有効化：テーブル作成
     * ------------------------------------------------------------- */
    public function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 長距離用繰越
        dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}am_chokyo_carryover` (
            `id`                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            `crew_code`         VARCHAR(20)      NOT NULL,
            `year_month`        CHAR(7)          NOT NULL,
            `labor_min`         INT              NOT NULL DEFAULT 0,
            `overtime_labor_min` INT             NULL DEFAULT NULL,
            `drive_min`         INT              NOT NULL DEFAULT 0,
            `cargo_min`         INT              NOT NULL DEFAULT 0,
            `kousoku_min`       INT              NOT NULL DEFAULT 0,
            `midnight_min`      INT              NOT NULL DEFAULT 0,
            `overtime_min`      INT              NOT NULL DEFAULT 0,
            `week_overtime_min` INT              NOT NULL DEFAULT 0,
            `days`              TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_crew_month` (`crew_code`(20), `year_month`)
        ) {$charset};" );

        // 長距離用勤怠ログ
        dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}am_chokyo_kintai_log` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `crew_code`      VARCHAR(20)  NOT NULL,
            `work_date`      DATE         NOT NULL,
            `kintai_type`    VARCHAR(20)  NOT NULL DEFAULT '',
            `furikae_label`  VARCHAR(30)  NOT NULL DEFAULT '',
            `is_manual`      TINYINT(1)   NOT NULL DEFAULT 0,
            `jiba`           TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '地場フラグ',
            `hayatai_min`    INT          NOT NULL DEFAULT 0,
            `note`           VARCHAR(100) NOT NULL DEFAULT '',
            `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_crew_date` (`crew_code`(20), `work_date`)
        ) {$charset};" );

        // 地場・事務用繰越
        dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}am_jiba_carryover` (
            `id`                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            `employee_code`     VARCHAR(20)      NOT NULL,
            `year_month`        CHAR(7)          NOT NULL,
            `labor_min`         INT              NOT NULL DEFAULT 0,
            `overtime_labor_min` INT             NULL DEFAULT NULL,
            `drive_min`         INT              NOT NULL DEFAULT 0,
            `cargo_min`         INT              NOT NULL DEFAULT 0,
            `kousoku_min`       INT              NOT NULL DEFAULT 0,
            `midnight_min`      INT              NOT NULL DEFAULT 0,
            `overtime_min`      INT              NOT NULL DEFAULT 0,
            `week_overtime_min` INT              NOT NULL DEFAULT 0,
            `days`              TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_emp_month` (`employee_code`(20), `year_month`)
        ) {$charset};" );

        // 地場・事務用勤怠ログ
        dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}am_jiba_kintai_log` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_code`  VARCHAR(20)  NOT NULL,
            `work_date`      DATE         NOT NULL,
            `kintai_type`    VARCHAR(20)  NOT NULL DEFAULT '',
            `furikae_label`  VARCHAR(30)  NOT NULL DEFAULT '',
            `is_manual`      TINYINT(1)   NOT NULL DEFAULT 0,
            `chokyo`         TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '長距離フラグ',
            `hayatai_min`    INT          NOT NULL DEFAULT 0,
            `note`           VARCHAR(100) NOT NULL DEFAULT '',
            `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_emp_date` (`employee_code`(20), `work_date`)
        ) {$charset};" );

        // 共通休日ルール
        dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}am_holiday_rules` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `affiliation_id` INT UNSIGNED NOT NULL,
            `day_of_week`    TINYINT      NOT NULL,
            `week_numbers`   VARCHAR(20)  NOT NULL,
            `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
            `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_affil_rule` (`affiliation_id`, `day_of_week`, `week_numbers`)
        ) {$charset};" );

        // 職種→管理区分マッピング
        dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}am_job_type_mapping` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `job_type_name` VARCHAR(50)  NOT NULL COMMENT '職種名（emp_masterのjob_type_nameと一致）',
            `category`      VARCHAR(10)  NOT NULL DEFAULT 'jiba' COMMENT 'chokyo=長距離 jiba=地場・事務',
            `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_job_type` (`job_type_name`)
        ) {$charset};" );
    }

    /* ---------------------------------------------------------------
     * マイグレーション
     * ------------------------------------------------------------- */
    public function migrate_existing_tables() {
        if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) return;
        global $wpdb;
        $tables = [
            $wpdb->prefix . 'am_chokyo_carryover',
            $wpdb->prefix . 'am_chokyo_kintai_log',
            $wpdb->prefix . 'am_jiba_carryover',
            $wpdb->prefix . 'am_jiba_kintai_log',
            $wpdb->prefix . 'am_holiday_rules',
            $wpdb->prefix . 'am_job_type_mapping',
        ];
        foreach ( $tables as $t ) {
            if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$t}'" ) ) {
                $this->activate();
                break;
            }
        }

        // 週40時間判定用の労働時間を、表示用の実労働時間と分けて保持する。
        foreach ( [ $wpdb->prefix . 'am_chokyo_carryover', $wpdb->prefix . 'am_jiba_carryover' ] as $table ) {
            if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" )
                && ! $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'overtime_labor_min'" ) ) {
                $wpdb->query( "ALTER TABLE `{$table}` ADD `overtime_labor_min` INT NULL DEFAULT NULL AFTER `labor_min`" );
            }
        }
    }

    /* ---------------------------------------------------------------
     * メニュー登録
     * ------------------------------------------------------------- */
    public function add_menu() {
        add_menu_page(
            '勤怠管理',
            '勤怠管理',
            'access_custom_plugins',
            'attendance-manager',
            [ $this, 'render_chokyo_page' ],
            'dashicons-calendar-alt',
            28
        );
        add_submenu_page(
            'attendance-manager', '長距離', '長距離',
            'access_custom_plugins', 'attendance-manager',
            [ $this, 'render_chokyo_page' ]
        );
        add_submenu_page(
            'attendance-manager', '地場・事務', '地場・事務',
            'access_custom_plugins', 'attendance-manager-jiba',
            [ $this, 'render_jiba_page' ]
        );
        add_submenu_page(
            'attendance-manager', '集計一覧', '集計一覧',
            'access_custom_plugins', 'attendance-manager-summary',
            [ $this, 'render_summary_list_page' ]
        );
        add_submenu_page(
            'attendance-manager', '設定', '設定',
            'manage_custom_plugin_settings', 'attendance-manager-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    /* ---------------------------------------------------------------
     * アセット読み込み
     * ------------------------------------------------------------- */
    public function enqueue_assets() {
        $page  = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
        $pages = [ 'attendance-manager', 'attendance-manager-jiba', 'attendance-manager-summary', 'attendance-manager-settings' ];
        if ( ! in_array( $page, $pages, true ) ) return;

        wp_enqueue_style(  'am-admin', AM_PLUGIN_URL . 'assets/css/admin.css', [], AM_VERSION );
        wp_enqueue_script( 'am-admin', AM_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], AM_VERSION, true );
        wp_localize_script( 'am-admin', 'amData', [
            'defaultMonth' => date( 'Y-m' ),
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'am_nonce' ),
            'currentPage'  => $page,
        ] );
    }

    /* ---------------------------------------------------------------
     * 長距離 集計表示
     * ------------------------------------------------------------- */
    public function render_chokyo_page() {
        if ( ! current_user_can( 'access_custom_plugins' ) ) wp_die( esc_html__( '権限がありません。', 'attendance-manager' ), '', array( 'response' => 403 ) );
        $result    = AM_DB::get_employees_by_category( 'chokyo' );
        $employees = $result['employees'];
        $db_error  = $result['error'];

        $selected_crew  = isset( $_GET['am_crew'] )  ? sanitize_text_field( wp_unslash( $_GET['am_crew'] ) )  : '';
        $selected_month = isset( $_GET['am_month'] ) ? sanitize_text_field( wp_unslash( $_GET['am_month'] ) ) : date( 'Y-m' );

        $emp_info = $monthly_rows = $weekly = $monthly_summary = null;
        $monthly_rows = [];

        if ( $selected_crew !== '' && $selected_month !== '' ) {
            $emp_info        = AM_DB::get_emp_info_by_crew( $selected_crew );
            $monthly_rows    = AM_Compute_Chokyo::get_monthly_rows( $selected_crew, $selected_month, $emp_info['name'] );
            $weekly          = AM_Compute_Chokyo::get_weekly_summary( $selected_crew, $selected_month, $monthly_rows );
            if ( ! empty( $monthly_rows ) ) {
                $monthly_summary = AM_Compute_Chokyo::get_monthly_summary( $monthly_rows, $weekly, $selected_crew, $selected_month );
            }
        }

        $kintai_types = self::KINTAI_TYPES;
        include AM_PLUGIN_DIR . 'templates/chokyo-page.php';
    }

    /* ---------------------------------------------------------------
     * 地場・事務 集計表示
     * ------------------------------------------------------------- */
    public function render_jiba_page() {
        if ( ! current_user_can( 'access_custom_plugins' ) ) wp_die( esc_html__( '権限がありません。', 'attendance-manager' ), '', array( 'response' => 403 ) );
        $result    = AM_DB::get_employees_by_category( 'jiba' );
        $employees = $result['employees'];
        $db_error  = $result['error'];

        $selected_emp   = isset( $_GET['am_emp'] )   ? sanitize_text_field( wp_unslash( $_GET['am_emp'] ) )   : '';
        $selected_month = isset( $_GET['am_month'] ) ? sanitize_text_field( wp_unslash( $_GET['am_month'] ) ) : date( 'Y-m' );

        $emp_info = $monthly_rows = $weekly = $monthly_summary = null;
        $monthly_rows = [];

        if ( $selected_emp !== '' && $selected_month !== '' ) {
            $emp_info        = AM_DB::get_emp_info_by_code( $selected_emp );
            $monthly_rows    = AM_Compute_Jiba::get_monthly_rows( $selected_emp, $selected_month, $emp_info['name'] );
            $weekly          = AM_Compute_Jiba::get_weekly_summary( $selected_emp, $selected_month, $monthly_rows );
            if ( ! empty( $monthly_rows ) ) {
                $monthly_summary = AM_Compute_Jiba::get_monthly_summary( $monthly_rows, $weekly, $selected_emp, $selected_month );
            }
        }

        $kintai_types = self::KINTAI_TYPES;
        include AM_PLUGIN_DIR . 'templates/jiba-page.php';
    }

    /* ---------------------------------------------------------------
     * 集計一覧
     * ------------------------------------------------------------- */
    public function render_summary_list_page() {
        if ( ! current_user_can( 'access_custom_plugins' ) ) wp_die( esc_html__( '権限がありません。', 'attendance-manager' ), '', array( 'response' => 403 ) );
        $selected_month = isset( $_GET['am_month'] ) ? sanitize_text_field( wp_unslash( $_GET['am_month'] ) ) : date( 'Y-m' );
        include AM_PLUGIN_DIR . 'templates/summary-list-page.php';
    }

    /* ---------------------------------------------------------------
     * 設定画面（休日マスタ + 種別管理）
     * ------------------------------------------------------------- */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) wp_die( esc_html__( '権限がありません。', 'attendance-manager' ), '', array( 'response' => 403 ) );
        global $wpdb;

        // 休日マスタ用データ
        if ( function_exists( 'emp_get_affiliations' ) ) {
            $affiliations = emp_get_affiliations();
        } else {
            $affiliations = $wpdb->get_results(
                "SELECT id, name FROM `{$wpdb->prefix}mst_affiliation` WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
            );
        }
        $rules      = AM_DB::get_holiday_rules();
        $dow_labels = [ '日', '月', '火', '水', '木', '金', '土' ];

        // 種別管理用データ
        $job_types = function_exists( 'emp_get_job_types' ) ? emp_get_job_types() : [];
        $mappings  = AM_DB::get_job_type_mappings();

        include AM_PLUGIN_DIR . 'templates/settings-page.php';
    }
}

new Tanpopo_AttendanceManager();

endif;
