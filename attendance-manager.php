<?php
/**
 * Plugin Name: 勤怠管理
 * Description: 長距離ドライバー・事務・地場の勤怠データを管理するプラグイン
 * Version:     1.2.1
 * Author:      有限会社たんぽぽ運送
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'AM_VERSION' ) )    define( 'AM_VERSION',    '1.2.1' );
if ( ! defined( 'AM_PLUGIN_DIR' ) ) define( 'AM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'AM_PLUGIN_URL' ) ) define( 'AM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AM_PLUGIN_DIR . 'includes/class-am-db.php';
require_once AM_PLUGIN_DIR . 'includes/class-am-compute-chokyo.php';
require_once AM_PLUGIN_DIR . 'includes/class-am-compute-jiba.php';
require_once AM_PLUGIN_DIR . 'includes/class-am-ajax.php';
require_once AM_PLUGIN_DIR . 'includes/class-am-kousoku-csv-importer.php';
require_once AM_PLUGIN_DIR . 'includes/class-am-summary-csv-exporter.php';

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
        add_action( 'wp_ajax_am_hosei_setting_save',         [ 'AM_Ajax', 'hosei_setting_save' ] );

        // --- 集計一覧 AJAX ---
        add_action( 'wp_ajax_am_summary_list_get',           [ 'AM_Ajax', 'summary_list_get' ] );
        add_action( 'admin_post_am_summary_csv_export',      [ 'AM_Summary_CSV_Exporter', 'download' ] );

        // --- 拘束時間CSV取込 ---
        add_action( 'admin_post_am_kousoku_csv_import',      [ 'AM_Kousoku_CSV_Importer', 'handle_import' ] );
        add_action( 'admin_post_am_kousoku_csv_template',    [ 'AM_Kousoku_CSV_Importer', 'download_template' ] );
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
            `employee_id`       INT UNSIGNED     NULL DEFAULT NULL,
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
            UNIQUE KEY `uq_crew_month` (`crew_code`(20), `year_month`),
            KEY `idx_employee_month` (`employee_id`, `year_month`)
        ) {$charset};" );

        // 長距離用勤怠ログ
        dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}am_chokyo_kintai_log` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id`    INT UNSIGNED NULL DEFAULT NULL,
            `crew_code`      VARCHAR(20)  NOT NULL,
            `work_date`      DATE         NOT NULL,
            `kintai_type`    VARCHAR(20)  NOT NULL DEFAULT '',
            `furikae_label`  VARCHAR(30)  NOT NULL DEFAULT '',
            `is_manual`      TINYINT(1)   NOT NULL DEFAULT 0,
            `jiba`           TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '地場フラグ',
            `hosei_min`      INT          NOT NULL DEFAULT 10 COMMENT '補正時間(点呼など)分',
            `hayatai_min`    INT          NOT NULL DEFAULT 0,
            `note`           VARCHAR(100) NOT NULL DEFAULT '',
            `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_crew_date` (`crew_code`(20), `work_date`),
            KEY `idx_employee_date` (`employee_id`, `work_date`)
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
            `hosei_min`      INT          NULL DEFAULT NULL COMMENT '補正時間(点呼など)分 NULL=既定',
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

        // 補正時間の適用開始月：未設定なら導入月とし、過去月の集計を変えない
        if ( get_option( 'am_hosei_start_month', null ) === null ) {
            add_option( 'am_hosei_start_month', date( 'Y-m' ) );
        }

        // 長距離：補正時間（点呼など・分）
        $chokyo_log = $wpdb->prefix . 'am_chokyo_kintai_log';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$chokyo_log}'" )
            && ! $wpdb->get_var( "SHOW COLUMNS FROM `{$chokyo_log}` LIKE 'hosei_min'" ) ) {
            $wpdb->query( "ALTER TABLE `{$chokyo_log}` ADD `hosei_min` INT NOT NULL DEFAULT 10 COMMENT '補正時間(点呼など)分' AFTER `jiba`" );
        }

        // 地場・事務：補正時間（NULL=既定。長距離フラグON時は10分）
        $jiba_log = $wpdb->prefix . 'am_jiba_kintai_log';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$jiba_log}'" )
            && ! $wpdb->get_var( "SHOW COLUMNS FROM `{$jiba_log}` LIKE 'hosei_min'" ) ) {
            $wpdb->query( "ALTER TABLE `{$jiba_log}` ADD `hosei_min` INT NULL DEFAULT NULL COMMENT '補正時間(点呼など)分 NULL=既定' AFTER `chokyo`" );
        }

        $this->migrate_chokyo_employee_ids();
    }

    /**
     * 長距離の保存データへ社員IDを追加し、一意に解決できる旧行だけを補完する。
     */
    private function migrate_chokyo_employee_ids() {
        global $wpdb;
        $log_table   = $wpdb->prefix . 'am_chokyo_kintai_log';
        $carry_table = $wpdb->prefix . 'am_chokyo_carryover';

        foreach ( array( $log_table => 'work_date', $carry_table => 'year_month' ) as $table => $suffix ) {
            if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) continue;
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'employee_id'" ) ) {
                $wpdb->query( "ALTER TABLE `{$table}` ADD `employee_id` INT UNSIGNED NULL DEFAULT NULL AFTER `id`" );
            }
            $index_name = $suffix === 'work_date' ? 'idx_employee_date' : 'idx_employee_month';
            if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index_name ) ) ) {
                $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `{$index_name}` (`employee_id`, `{$suffix}`)" );
            }
        }

        $history = $wpdb->prefix . 'emp_crew_code_history';
        $has_history = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $history ) ) === $history;
        if ( $has_history ) {
            $wpdb->query(
                "UPDATE `{$log_table}` target
                 INNER JOIN (
                     SELECT source.id, MIN(h.employee_id) AS employee_id
                     FROM `{$log_table}` source
                     INNER JOIN `{$history}` h
                       ON h.crew_code COLLATE utf8mb4_unicode_520_ci = source.crew_code COLLATE utf8mb4_unicode_520_ci
                     WHERE source.employee_id IS NULL
                       AND (h.valid_from IS NULL OR h.valid_from <= source.work_date)
                       AND (h.valid_to IS NULL OR h.valid_to >= source.work_date)
                     GROUP BY source.id
                     HAVING COUNT(DISTINCT h.employee_id) = 1
                 ) mapped ON mapped.id = target.id
                 SET target.employee_id = mapped.employee_id
                 WHERE target.employee_id IS NULL"
            );
            $wpdb->query(
                "UPDATE `{$carry_table}` target
                 INNER JOIN (
                     SELECT source.id, MIN(h.employee_id) AS employee_id
                     FROM `{$carry_table}` source
                     INNER JOIN `{$history}` h
                       ON h.crew_code COLLATE utf8mb4_unicode_520_ci = source.crew_code COLLATE utf8mb4_unicode_520_ci
                     WHERE source.employee_id IS NULL
                       AND (h.valid_from IS NULL OR h.valid_from <= LAST_DAY(STR_TO_DATE(CONCAT(source.year_month, '-01'), '%Y-%m-%d')))
                       AND (h.valid_to IS NULL OR h.valid_to >= STR_TO_DATE(CONCAT(source.year_month, '-01'), '%Y-%m-%d'))
                     GROUP BY source.id
                     HAVING COUNT(DISTINCT h.employee_id) = 1
                 ) mapped ON mapped.id = target.id
                 SET target.employee_id = mapped.employee_id
                 WHERE target.employee_id IS NULL"
            );
        }

        // 履歴テーブルがまだない旧構成だけ、現行masterから一意に補完する。
        if ( ! $has_history ) {
            foreach ( array( $log_table, $carry_table ) as $table ) {
                $wpdb->query(
                    "UPDATE `{$table}` target
                     INNER JOIN (
                         SELECT crew_code, MIN(id) AS employee_id
                         FROM `{$wpdb->prefix}emp_master`
                         WHERE crew_code IS NOT NULL AND crew_code <> ''
                         GROUP BY crew_code
                         HAVING COUNT(*) = 1
                     ) mapped ON mapped.crew_code COLLATE utf8mb4_unicode_520_ci = target.crew_code COLLATE utf8mb4_unicode_520_ci
                     SET target.employee_id = mapped.employee_id
                     WHERE target.employee_id IS NULL"
                );
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
            'attendance-manager', '拘束時間CSV取込', '拘束CSV取込',
            'manage_custom_plugin_settings', 'attendance-manager-kousoku-import',
            [ $this, 'render_kousoku_import_page' ]
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
        $pages = [ 'attendance-manager', 'attendance-manager-jiba', 'attendance-manager-summary', 'attendance-manager-kousoku-import', 'attendance-manager-settings' ];
        if ( ! in_array( $page, $pages, true ) ) return;

        wp_enqueue_style(  'am-admin', AM_PLUGIN_URL . 'assets/css/admin.css', [], AM_VERSION . '.' . filemtime( AM_PLUGIN_DIR . 'assets/css/admin.css' ) );
        wp_enqueue_script( 'am-admin', AM_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], AM_VERSION . '.' . filemtime( AM_PLUGIN_DIR . 'assets/js/admin.js' ), true );
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

        $selected_employee_id = isset( $_GET['am_employee_id'] ) ? absint( $_GET['am_employee_id'] ) : 0;
        // 旧URL（am_crew）からの互換解決。
        if ( ! $selected_employee_id && isset( $_GET['am_crew'] ) ) {
            $selected_employee_id = AM_DB::get_employee_id_by_crew( sanitize_text_field( wp_unslash( $_GET['am_crew'] ) ) );
        }
        $selected_month = isset( $_GET['am_month'] ) ? sanitize_text_field( wp_unslash( $_GET['am_month'] ) ) : date( 'Y-m' );

        $emp_info = $monthly_rows = $weekly = $monthly_summary = null;
        $used_crew_codes = [];
        $monthly_rows = [];

        if ( $selected_employee_id && $selected_month !== '' ) {
            $emp_info = AM_DB::get_emp_info_by_id( $selected_employee_id );
            if ( ! $emp_info ) wp_die( '社員が見つかりません。', '', [ 'response' => 404 ] );
            $month_start = $selected_month . '-01';
            $month_end = date( 'Y-m-t', strtotime( $month_start ) );
            $code_history = AM_DB::get_crew_codes_for_period( $selected_employee_id, $month_start, $month_end );
            $used_crew_codes = array_values( array_unique( array_filter( array_map( function( $row ) {
                return $row['crew_code'] ?? '';
            }, $code_history ) ) ) );
            $monthly_rows    = AM_Compute_Chokyo::get_monthly_rows( $selected_employee_id, $selected_month, $emp_info['name'] );
            $weekly          = AM_Compute_Chokyo::get_weekly_summary( $selected_employee_id, $selected_month, $monthly_rows );
            if ( ! empty( $monthly_rows ) ) {
                $monthly_summary = AM_Compute_Chokyo::get_monthly_summary( $monthly_rows, $weekly, $selected_employee_id, $selected_month );
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
     * 拘束時間CSV取込
     * ------------------------------------------------------------- */
    public function render_kousoku_import_page() {
        if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) wp_die( esc_html__( '権限がありません。', 'attendance-manager' ), '', [ 'response' => 403 ] );
        $import_result = AM_Kousoku_CSV_Importer::consume_result();
        $template_url  = wp_nonce_url(
            admin_url( 'admin-post.php?action=am_kousoku_csv_template' ),
            'am_kousoku_csv_template'
        );
        include AM_PLUGIN_DIR . 'templates/kousoku-import-page.php';
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
        $hosei_start_month = AM_DB::get_hosei_start_month();
        $job_types = function_exists( 'emp_get_job_types' ) ? emp_get_job_types() : [];
        $mappings  = AM_DB::get_job_type_mappings();
        $unlinked_crew_codes = AM_DB::get_unlinked_crew_codes();
        $crew_migration_status = AM_DB::get_crew_migration_status();

        include AM_PLUGIN_DIR . 'templates/settings-page.php';
    }
}

new Tanpopo_AttendanceManager();

endif;
