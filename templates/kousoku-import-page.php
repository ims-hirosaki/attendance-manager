<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<div class="wrap am-wrap">
    <div class="am-page-header">
        <h1 class="am-page-title">
            <span class="dashicons dashicons-database-import"></span>
            拘束時間CSV取込
        </h1>
        <p class="am-page-desc">phpMyAdminからエクスポートした wp_kousoku_log の全カラムCSVを取り込みます。</p>
    </div>

    <?php if ( $import_result ) : ?>
        <div class="notice <?php echo $import_result['success'] ? 'notice-success' : 'notice-error'; ?> is-dismissible am-import-notice">
            <p><strong><?php echo esc_html( $import_result['message'] ); ?></strong></p>
            <?php if ( $import_result['success'] ) : ?>
                <p>
                    処理対象: <?php echo number_format_i18n( $import_result['processed'] ); ?>件 / 
                    新規追加: <?php echo number_format_i18n( $import_result['inserted'] ); ?>件 / 
                    上書き: <?php echo number_format_i18n( $import_result['updated'] ); ?>件 / 
                    既存スキップ: <?php echo number_format_i18n( $import_result['skipped'] ); ?>件
                </p>
            <?php else : ?>
                <p>エラーが発生したため、今回の変更はすべて取り消されました。</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="am-card am-import-card">
        <div class="am-card-header">
            <span class="dashicons dashicons-upload"></span>
            CSVファイルを選択
        </div>
        <div class="am-card-body">
            <form id="am-kousoku-import-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="am_kousoku_csv_import">
                <?php wp_nonce_field( 'am_kousoku_csv_import', 'am_kousoku_csv_nonce' ); ?>

                <div class="am-import-file-row">
                    <label class="am-label" for="am-kousoku-csv">取込CSV</label>
                    <input type="file" id="am-kousoku-csv" name="kousoku_csv" accept=".csv,text/csv" required>
                    <span class="am-import-help">UTF-8・20MB以下・最大100,000行</span>
                </div>

                <div class="am-import-mode">
                    <strong>標準動作</strong>
                    <span>同じ乗務員コード・日付の行がDBに存在する場合はスキップします。</span>
                </div>

                <label class="am-import-overwrite" for="am-overwrite-existing">
                    <input type="checkbox" id="am-overwrite-existing" name="overwrite_existing" value="1">
                    <span>
                        <strong>既存行を上書きする</strong>
                        <small>イレギュラー対応時のみ選択してください。事前に wp_kousoku_log をバックアップし、内容を確認してから実行してください。既存行のIDは維持し、それ以外の全カラムをCSVの値で更新します。</small>
                    </span>
                </label>

                <div class="am-import-actions">
                    <button type="submit" id="am-kousoku-import-submit" class="am-btn am-btn-primary">
                        <span class="dashicons dashicons-database-import"></span>
                        CSVを取り込む
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="am-card am-import-notes">
        <div class="am-card-header">
            <span class="dashicons dashicons-info-outline"></span>
            取込仕様
        </div>
        <div class="am-card-body">
            <ul>
                <li>CSVヘッダーは id から updated_at まで、wp_kousoku_log の全27カラムが必要です。</li>
                <li>既存判定には crew_code と work_date の組み合わせを使用します。</li>
                <li>新規行ではCSVの id・作成日時・更新日時を含む全カラムを登録します。</li>
                <li>1行でも不正なデータやDBエラーがあった場合は、ファイル全体の取込を取り消します。</li>
            </ul>
        </div>
    </div>
</div>
