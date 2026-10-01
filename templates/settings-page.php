<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<div class="wrap am-wrap">

    <div class="am-page-header">
        <h1 class="am-page-title">
            <span class="dashicons dashicons-admin-settings"></span>
            設定
        </h1>
        <p class="am-page-desc">休日ルールの管理と、職種ごとの管理区分（長距離 / 地場・事務）を設定します。</p>
    </div>

    <!-- ============================================================
         セクション①：休日マスタ設定
         ============================================================ -->
    <div class="am-settings-section-heading">
        <span class="dashicons dashicons-calendar-alt"></span>
        休日マスタ設定
    </div>
    <p class="am-settings-section-desc">所属ごとの所定休日ルールを設定します。長距離・地場・事務で共通して参照されます。</p>

    <!-- ルール追加フォーム -->
    <div class="am-card">
        <div class="am-card-header"><span class="dashicons dashicons-plus-alt"></span> ルールの追加・編集</div>
        <div class="am-card-body">
            <div class="am-form-row">
                <div class="am-form-group">
                    <label class="am-label" for="hm-affiliation">所属</label>
                    <select id="hm-affiliation" class="am-select">
                        <option value="">― 所属を選択 ―</option>
                        <?php foreach ( $affiliations as $a ) : ?>
                        <option value="<?php echo esc_attr( $a->id ); ?>"><?php echo esc_html( $a->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="am-form-group">
                    <label class="am-label" for="hm-dow">所定休日（曜日）</label>
                    <select id="hm-dow" class="am-select">
                        <?php foreach ( $dow_labels as $i => $label ) : ?>
                        <option value="<?php echo $i; ?>"><?php echo esc_html( $label ); ?>曜日</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="am-form-group">
                    <label class="am-label" for="hm-weeks">対象週（カンマ区切り）</label>
                    <input type="text" id="hm-weeks" class="am-input-month" placeholder="例: 2,4" style="width:140px;">
                    <small style="color:#666;font-size:11px;">第何週かをカンマ区切りで入力（例：第2・第4週なら 2,4）</small>
                </div>
                <div class="am-form-group am-form-group--btn">
                    <button type="button" id="hm-btn-save" class="am-btn am-btn-primary">
                        <span class="dashicons dashicons-saved"></span> 保存
                    </button>
                    <button type="button" id="hm-btn-cancel" class="am-btn" style="display:none;background:#aaa;color:#fff;margin-left:8px;">キャンセル</button>
                </div>
            </div>
            <div id="hm-message" style="margin-top:12px;font-size:13px;"></div>
        </div>
    </div>

    <!-- 登録済みルール一覧 -->
    <div class="am-card">
        <div class="am-card-header"><span class="dashicons dashicons-list-view"></span> 登録済みルール一覧</div>
        <div class="am-card-body" style="padding:0;">
            <div class="am-table-wrap" style="border:none;border-radius:0;height:auto;">
                <table class="am-main-table" id="hm-rule-table">
                    <thead>
                        <tr><th>所属</th><th>所定休日（曜日）</th><th>対象週</th><th>状態</th><th>操作</th></tr>
                    </thead>
                    <tbody id="hm-rule-tbody">
                    <?php if ( empty( $rules ) ) : ?>
                        <tr><td colspan="5" style="text-align:center;color:#aaa;padding:24px;">登録済みルールはありません</td></tr>
                    <?php else : ?>
                        <?php foreach ( $rules as $rule ) : ?>
                        <tr data-id="<?php echo (int)$rule['id']; ?>">
                            <td><?php echo esc_html( $rule['affiliation_name'] ); ?></td>
                            <td><?php echo esc_html( $dow_labels[ (int)$rule['day_of_week'] ] ); ?>曜日</td>
                            <td>第<?php echo esc_html( implode( '・', explode( ',', $rule['week_numbers'] ) ) ); ?>週</td>
                            <td><span class="hm-status <?php echo $rule['is_active'] ? 'hm-active' : 'hm-inactive'; ?>"><?php echo $rule['is_active'] ? '有効' : '無効'; ?></span></td>
                            <td>
                                <button type="button" class="am-btn hm-btn-edit" style="height:30px;padding:0 12px;font-size:12px;background:#2e6da4;color:#fff;"
                                    data-id="<?php echo (int)$rule['id']; ?>" data-affil="<?php echo (int)$rule['affiliation_id']; ?>"
                                    data-dow="<?php echo (int)$rule['day_of_week']; ?>" data-weeks="<?php echo esc_attr( $rule['week_numbers'] ); ?>">編集</button>
                                <button type="button" class="am-btn hm-btn-toggle" style="height:30px;padding:0 12px;font-size:12px;background:<?php echo $rule['is_active'] ? '#aaa' : '#2c5f2e'; ?>;color:#fff;margin-left:4px;"
                                    data-id="<?php echo (int)$rule['id']; ?>" data-active="<?php echo (int)$rule['is_active']; ?>"><?php echo $rule['is_active'] ? '無効化' : '有効化'; ?></button>
                                <button type="button" class="am-btn hm-btn-delete" style="height:30px;padding:0 12px;font-size:12px;background:#d63638;color:#fff;margin-left:4px;"
                                    data-id="<?php echo (int)$rule['id']; ?>">削除</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============================================================
         セクション②：種別管理
         ============================================================ -->
    <div class="am-settings-section-heading" style="margin-top:32px;">
        <span class="dashicons dashicons-groups"></span>
        種別管理
    </div>
    <p class="am-settings-section-desc">職種ごとに「長距離」「地場・事務」どちらの管理表に表示するかを設定します。職種一覧は従業員マスタから自動取得されます。</p>

    <div class="am-card">
        <div class="am-card-header"><span class="dashicons dashicons-edit"></span> 職種と管理区分の設定</div>
        <div class="am-card-body" style="padding:0;">
            <div id="jt-loading" style="padding:24px;text-align:center;color:#888;">読み込み中...</div>
            <table class="am-main-table" id="jt-table" style="display:none;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding-left:20px;">職種名</th>
                        <th style="min-width:160px;">長距離</th>
                        <th style="min-width:160px;">地場・事務</th>
                        <th style="min-width:80px;">状態</th>
                    </tr>
                </thead>
                <tbody id="jt-tbody"></tbody>
            </table>
            <div id="jt-empty" style="display:none;padding:24px;text-align:center;color:#aaa;">
                従業員マスタに職種が登録されていません。<br>
                先に employee-manager で職種を登録してください。
            </div>
        </div>
        <div id="jt-message" style="padding:8px 20px;font-size:13px;font-weight:700;display:none;"></div>
    </div>

    <div class="am-settings-section-heading" style="margin-top:32px;">
        <span class="dashicons dashicons-clock"></span>
        補正時間（点呼など）
    </div>
    <p class="am-settings-section-desc">長距離の週集計の労働時間に補正時間を加算する開始月を設定します。この月より前の月は補正時間が0扱いとなり、過去の集計は変わりません。</p>
    <div class="am-card">
        <div class="am-card-header"><span class="dashicons dashicons-calendar-alt"></span> 適用開始月</div>
        <div class="am-card-body">
            <div class="am-form-row">
                <div class="am-form-group">
                    <label class="am-label" for="hosei-start-month">適用開始月</label>
                    <input type="month" id="hosei-start-month" class="am-input-month" value="<?php echo esc_attr( $hosei_start_month ); ?>">
                </div>
                <div class="am-form-group am-form-group--btn">
                    <button type="button" id="hosei-btn-save" class="am-btn am-btn-primary">
                        <span class="dashicons dashicons-saved"></span> 保存
                    </button>
                </div>
            </div>
            <div id="hosei-message" style="margin-top:12px;font-size:13px;"></div>
        </div>
    </div>

    <div class="am-settings-section-heading" style="margin-top:32px;">
        <span class="dashicons dashicons-admin-links"></span>
        未紐付け乗組員コード
    </div>
    <p class="am-settings-section-desc">拘束時間データに存在し、社員マスタまたは乗組員コード履歴へ紐付いていないコードです。社員情報管理の社員編集画面から「過去コードを追加」してください。</p>
    <div class="am-card">
        <div class="am-card-header"><span class="dashicons dashicons-database"></span> 移行状況</div>
        <div class="am-card-body">
            <div class="am-summary-grid">
                <div><strong><?php echo number_format_i18n( $crew_migration_status['unmigrated_logs'] ); ?></strong><span>未移行 勤怠編集行</span></div>
                <div><strong><?php echo number_format_i18n( $crew_migration_status['unmigrated_carryovers'] ); ?></strong><span>未移行 繰越行</span></div>
                <div><strong><?php echo number_format_i18n( $crew_migration_status['log_conflicts'] ); ?></strong><span>勤怠編集の衝突</span></div>
                <div><strong><?php echo number_format_i18n( $crew_migration_status['carryover_conflicts'] ); ?></strong><span>繰越の衝突</span></div>
            </div>
        </div>
    </div>
    <div class="am-card">
        <div class="am-card-body" style="padding:0;">
            <div class="am-table-wrap" style="border:none;border-radius:0;height:auto;">
                <table class="am-main-table">
                    <thead><tr><th>乗組員コード</th><th>最古日</th><th>最新日</th><th>データ件数</th></tr></thead>
                    <tbody>
                    <?php if ( empty( $unlinked_crew_codes ) ) : ?>
                        <tr><td colspan="4" style="text-align:center;color:#777;padding:24px;">未紐付けコードはありません</td></tr>
                    <?php else : foreach ( $unlinked_crew_codes as $code ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $code['crew_code'] ); ?></strong></td>
                            <td><?php echo esc_html( $code['first_date'] ); ?></td>
                            <td><?php echo esc_html( $code['last_date'] ); ?></td>
                            <td><?php echo number_format_i18n( (int) $code['row_count'] ); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div><!-- /.am-wrap -->
