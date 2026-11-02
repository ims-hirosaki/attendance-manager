<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-am-kousoku-xlsx-importer.php';

/**
 * 拘束時間データの最新化（取得依頼）。
 *
 * 流れ:
 *  1. 画面右上の「最新データを取得」で月（前月／当月）を選んで依頼
 *     → 取得用フォルダ（warehouse）/requests/ に依頼ファイル kreq_YYYYMM_<ID>.json を作成
 *  2. 取得サーバー（5分ごとのポーラー）が依頼を検出 → 地球号から該当月の拘束時間管理表を取得
 *     → xlsx に変換 → FTP で warehouse に kousoku_kreq_<ID>.xlsx を転送（.part で送って改名）
 *  3. このクラスが xlsx の到着を検知（WP-Cron 5分ごと ＋ 画面表示中は15秒ごと）
 *     → wp_kousoku_log へ取り込み → キャッシュ破棄 → 履歴を「取り込み完了」に更新
 *
 * 取得サーバー側の処理は server-scripts/chikyu_request_poll.py を参照。
 */
class AM_Fetch_Request {

    const OPTION  = 'am_fetch_requests';
    const EVENT   = 'am_fetch_watch_event';
    const TIMEOUT = 10800; // 3時間で未到着ならタイムアウト
    const KEEP    = 20;    // 履歴の保持件数
    const CAP     = 'manage_custom_plugin_settings';

    /** 取得用フォルダ（取得サーバーが FTP で xlsx を置く場所）の既定値。 */
    const DEFAULT_DIR = '/home/xs969605/xs969605.xsrv.jp/warehouse/';

    private static $button_rendered = false;

    public static function init() {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedule' ] );
        add_action( self::EVENT,                  [ __CLASS__, 'process' ] );
        add_action( 'wp_ajax_am_fetch_request',   [ __CLASS__, 'ajax_request' ] );
        add_action( 'wp_ajax_am_fetch_status',    [ __CLASS__, 'ajax_status' ] );
        add_action( 'admin_footer',               [ __CLASS__, 'render_modal' ] );
    }

    public static function add_cron_schedule( $schedules ) {
        $schedules['am_5min'] = [ 'interval' => 300, 'display' => '5分ごと（拘束時間取得の確認）' ];
        return $schedules;
    }

    /* ---------------------------------------------------------------
     * フォルダ・月の決定
     * ------------------------------------------------------------- */

    /** 取得用フォルダ。定数 AM_WAREHOUSE_DIR ＞ オプション am_warehouse_dir ＞ 既定値。 */
    public static function base_dir() {
        if ( defined( 'AM_WAREHOUSE_DIR' ) ) {
            $dir = AM_WAREHOUSE_DIR;
        } else {
            $dir = get_option( 'am_warehouse_dir', self::DEFAULT_DIR );
        }
        $dir = (string) apply_filters( 'am_warehouse_dir', $dir );
        return trailingslashit( rtrim( trim( $dir ), '/\\' ) );
    }

    /** 依頼ファイルの置き場所（なければ作る）。作れなければ false。 */
    private static function requests_dir() {
        $base = self::base_dir();
        if ( ! is_dir( $base ) ) return false;
        $dir = $base . 'requests/';
        if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
        return ( is_dir( $dir ) && is_writable( $dir ) ) ? $dir : false;
    }

    private static function result_path( $id ) {
        return self::base_dir() . 'kousoku_kreq_' . $id . '.xlsx';
    }

    /** 選べる月：当月と前月（サイト設定のタイムゾーン基準）。 [ 'YYYY-MM' => ラベル ] */
    public static function month_options() {
        $now  = new DateTimeImmutable( 'now', wp_timezone() );
        $cur  = $now->format( 'Y-m' );
        $prev = $now->modify( 'first day of last month' )->format( 'Y-m' );
        return [
            $cur  => '当月（' . $cur . '）',
            $prev => '前月（' . $prev . '）',
        ];
    }

    private static function valid_month( $ym ) {
        return is_string( $ym ) && array_key_exists( $ym, self::month_options() );
    }

    /* ---------------------------------------------------------------
     * Ajax
     * ------------------------------------------------------------- */

    /** 取得依頼。 */
    public static function ajax_request() {
        check_ajax_referer( 'am_nonce', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) {
            wp_send_json_error( [ 'message' => '権限がありません。' ], 403 );
        }

        $ym = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';
        if ( ! self::valid_month( $ym ) ) {
            wp_send_json_error( [ 'message' => '取得できるのは当月と前月のみです。' ] );
        }

        $dir = self::requests_dir();
        if ( false === $dir ) {
            wp_send_json_error( [ 'message' => '取得用フォルダを使えません（' . self::base_dir() . '）。フォルダの場所と書き込み権限を確認してください。' ] );
        }

        $reqs = self::load();
        foreach ( $reqs as $r ) {
            if ( 'pending' === ( $r['status'] ?? '' ) && ( $r['month'] ?? '' ) === $ym ) {
                wp_send_json_error( [ 'message' => $ym . ' はすでに取得依頼中です。' ] );
            }
        }

        $id   = strtolower( wp_generate_password( 8, false, false ) );
        $file = 'kreq_' . str_replace( '-', '', $ym ) . '_' . $id . '.json';
        $body = wp_json_encode( [ 'id' => $id, 'month' => $ym, 'requested_at' => time() ] );
        if ( false === file_put_contents( $dir . $file, $body, LOCK_EX ) ) {
            wp_send_json_error( [ 'message' => '依頼ファイルを作成できませんでした（書き込み権限を確認してください）。' ] );
        }

        $reqs[] = [
            'id' => $id, 'month' => $ym, 'file' => $file,
            'requested_at' => time(), 'status' => 'pending', 'message' => '',
        ];
        self::save( array_slice( $reqs, -self::KEEP ) );
        self::schedule();

        wp_send_json_success( [ 'rows' => self::rows() ] );
    }

    /** 状況確認（xlsx 到着時は取り込みも実行）。 */
    public static function ajax_status() {
        check_ajax_referer( 'am_nonce', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) {
            wp_send_json_error( [ 'message' => '権限がありません。' ], 403 );
        }
        self::process();
        wp_send_json_success( [ 'rows' => self::rows() ] );
    }

    /* ---------------------------------------------------------------
     * 履歴の保存・Cron
     * ------------------------------------------------------------- */

    private static function load() {
        $reqs = get_option( self::OPTION, [] );
        return is_array( $reqs ) ? $reqs : [];
    }

    private static function save( array $reqs ) {
        update_option( self::OPTION, array_values( $reqs ), false );
    }

    private static function schedule() {
        if ( ! wp_next_scheduled( self::EVENT ) ) {
            wp_schedule_event( time() + 60, 'am_5min', self::EVENT );
        }
    }

    private static function unschedule() {
        $ts = wp_next_scheduled( self::EVENT );
        if ( $ts ) wp_unschedule_event( $ts, self::EVENT );
    }

    /* ---------------------------------------------------------------
     * 依頼の確認 → xlsx が届いていれば取り込み
     * ------------------------------------------------------------- */
    public static function process() {
        $reqs    = self::load();
        $pending = [];
        foreach ( $reqs as $i => $r ) {
            if ( 'pending' === ( $r['status'] ?? '' ) ) $pending[] = $i;
        }
        if ( empty( $pending ) ) {
            self::unschedule();
            return $reqs;
        }

        // 同時実行の防止
        if ( get_transient( 'am_fetch_lock' ) ) return $reqs;
        set_transient( 'am_fetch_lock', 1, 300 );

        try {
            $requests_dir = self::base_dir() . 'requests/';
            $imported_any = false;

            foreach ( $pending as $i ) {
                $r    = $reqs[ $i ];
                $id   = (string) ( $r['id'] ?? '' );
                $path = preg_match( '/^[a-z0-9]{6,16}$/', $id ) ? self::result_path( $id ) : '';
                if ( '' !== $path ) clearstatcache( true, $path );

                if ( '' !== $path && is_file( $path ) && ( time() - (int) filemtime( $path ) ) >= 3 ) {
                    // xlsx が届いた → 取り込み
                    try {
                        $res = AM_Kousoku_XLSX_Importer::import_file( $path );
                        $reqs[ $i ]['status']  = empty( $res['errors'] ) ? 'imported' : 'error';
                        $reqs[ $i ]['message'] = mb_substr( $res['message'], 0, 300 );
                        $imported_any = true;
                    } catch ( Throwable $e ) {
                        $reqs[ $i ]['status']  = 'error';
                        $reqs[ $i ]['message'] = mb_substr( $e->getMessage(), 0, 300 );
                    }
                    $reqs[ $i ]['finished_at'] = time();
                    @unlink( $path );
                    @unlink( $requests_dir . ( $r['file'] ?? '' ) );
                    continue;
                }

                if ( time() - (int) ( $r['requested_at'] ?? 0 ) > self::TIMEOUT ) {
                    $reqs[ $i ]['status']      = 'timeout';
                    $reqs[ $i ]['message']     = '取得サーバーからのデータが届きませんでした';
                    $reqs[ $i ]['finished_at'] = time();
                    @unlink( $requests_dir . ( $r['file'] ?? '' ) );
                }
            }

            if ( $imported_any && class_exists( 'AM_REST_API' ) ) {
                AM_REST_API::invalidate_cache();
            }

            self::save( $reqs );
            $still = array_filter( $reqs, function ( $r ) { return 'pending' === ( $r['status'] ?? '' ); } );
            if ( empty( $still ) ) self::unschedule();
        } finally {
            delete_transient( 'am_fetch_lock' );
        }
        return $reqs;
    }

    /* ---------------------------------------------------------------
     * 表示用の行データ
     * ------------------------------------------------------------- */
    public static function rows() {
        $labels = [
            'pending'  => '取得待ち（取得サーバーが処理中）',
            'imported' => '取り込み完了',
            'error'    => 'エラー',
            'timeout'  => 'タイムアウト',
        ];
        $rows = [];
        foreach ( array_reverse( self::load() ) as $r ) {
            $st     = $r['status'] ?? '';
            $rows[] = [
                'month'        => $r['month'] ?? '',
                'requested_at' => wp_date( 'm/d H:i', (int) ( $r['requested_at'] ?? 0 ) ),
                'status'       => $st,
                'status_label' => $labels[ $st ] ?? $st,
                'message'      => $r['message'] ?? '',
            ];
        }
        return array_slice( $rows, 0, 10 );
    }

    public static function can_fetch() {
        return current_user_can( self::CAP );
    }

    /* ---------------------------------------------------------------
     * 画面部品
     * ------------------------------------------------------------- */

    /** ページ右上のボタン（ページヘッダー内に置く）。 */
    public static function render_button() {
        if ( ! self::can_fetch() ) return;
        self::$button_rendered = true;
        echo '<div class="am-page-header-actions">'
           . '<button type="button" id="am-fetch-open" class="am-btn am-btn-header">'
           . '<span class="dashicons dashicons-update"></span><span class="am-fetch-open-label">最新データを取得</span>'
           . '</button></div>';
    }

    /** ポップアップ本体（ボタンを置いたページのフッターに出力）。 */
    public static function render_modal() {
        if ( ! self::$button_rendered || ! self::can_fetch() ) return;

        $options = '';
        $first   = true;
        foreach ( self::month_options() as $ym => $label ) {
            $options .= '<option value="' . esc_attr( $ym ) . '"' . ( $first ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
            $first = false;
        }
        ?>
        <div id="am-fetch-modal" class="am-modal" hidden>
            <div class="am-modal-box" role="dialog" aria-modal="true" aria-labelledby="am-fetch-title">
                <button type="button" class="am-modal-close" id="am-fetch-close" aria-label="閉じる">&times;</button>
                <h2 id="am-fetch-title">最新の拘束時間データを取得</h2>
                <p class="am-modal-desc">
                    指定した月の拘束時間管理表を地球号から取得し、届き次第、自動で取り込みます（目安：5〜10分）。
                    このウィンドウを閉じても処理は続きます。<br>
                    <span class="am-modal-note">※ 毎日 1:55〜3:30 は取得サーバーが停止しているため、この時間帯の依頼は 3:30 以降に処理されます。</span>
                </p>
                <div class="am-modal-row">
                    <select id="am-fetch-month" class="am-select"><?php echo $options; // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
                    <button type="button" id="am-fetch-btn" class="am-btn am-btn-primary">取得を依頼</button>
                    <span id="am-fetch-msg" class="am-modal-error"></span>
                </div>
                <table class="am-modal-table">
                    <thead><tr><th>対象月</th><th>依頼日時</th><th>状況</th><th>結果</th></tr></thead>
                    <tbody id="am-fetch-rows"></tbody>
                </table>
                <p id="am-fetch-reload" class="am-modal-reload" style="display:none;">
                    <a href="" class="am-btn am-btn-secondary">ページを更新して反映</a>
                </p>
            </div>
        </div>
        <?php
    }
}
