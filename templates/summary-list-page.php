<?php if ( ! defined( 'ABSPATH' ) ) exit;
$page_url = admin_url( 'admin.php?page=attendance-manager-summary' );
?>

<div class="wrap am-wrap">

    <div class="am-page-header">
        <h1 class="am-page-title">
            <span class="dashicons dashicons-list-view"></span>
            勤怠管理 | 集計一覧
        </h1>
        <p class="am-page-desc">全社員の月次勤怠サマリを一覧で確認できます。</p>
    </div>

    <div class="am-card">
        <div class="am-card-header"><span class="dashicons dashicons-search"></span> 対象月の選択</div>
        <div class="am-card-body">
            <div class="am-form-row">
                <div class="am-form-group">
                    <label class="am-label" for="am-sl-month">対象月</label>
                    <input type="month" id="am-sl-month" class="am-input-month" value="<?php echo esc_attr( $selected_month ); ?>">
                </div>
                <div class="am-form-group am-form-group--btn">
                    <button type="button" id="am-sl-load" class="am-btn am-btn-primary">
                        <span class="dashicons dashicons-search"></span> 読み込む
                    </button>
                </div>
                <div class="am-form-group am-form-group--btn">
                    <a id="am-sl-csv" class="am-btn am-btn-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=am_summary_csv_export&year_month=' . rawurlencode( $selected_month ) ), 'am_summary_csv_export' ) ); ?>">
                        <span class="dashicons dashicons-download"></span> CSV出力
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="am-card" id="am-sl-result">
        <div class="am-card-header"><span class="dashicons dashicons-list-view"></span> 集計一覧</div>
        <div id="am-sl-loading" style="display:none; padding:30px; text-align:center; color:#666;">
            <span class="dashicons dashicons-update am-spin"></span> 読み込み中...
        </div>
        <div id="am-sl-error" class="am-notice am-notice-error" style="display:none; margin:16px 20px;"></div>
        <div class="am-sl-table-wrap" style="display:none;" id="am-sl-table-wrap">
            <table class="am-sl-table" id="am-sl-table">
                <thead>
                    <tr>
                        <th>社員コード</th>
                        <th>社員名</th>
                        <th>出勤日数</th>
                        <th>欠勤日数</th>
                        <th>休日出勤日数</th>
                        <th title="振替休がない法定休日出勤の日数と実労働時間">振替なし法定休出勤</th>
                        <th>有給消化日数</th>
                        <th>有給残日数</th>
                        <th>労働時間</th>
                        <th>早退遅刻時間</th>
                        <th>確定残業時間</th>
                    </tr>
                </thead>
                <tbody id="am-sl-tbody"></tbody>
            </table>
        </div>
    </div>

</div>
