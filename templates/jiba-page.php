<?php if ( ! defined( 'ABSPATH' ) ) exit;
$page_url = admin_url( 'admin.php?page=attendance-manager-jiba' );
?>

<div class="wrap am-wrap">

    <div class="am-page-header">
        <h1 class="am-page-title">
            <span class="dashicons dashicons-groups"></span>
            勤怠管理 | 地場・事務
        </h1>
        <p class="am-page-desc">打刻ツールのデータをもとに、地場・事務社員の月次勤怠データを確認できます。</p>
    </div>

    <?php if ( ! empty( $db_error ) ) : ?>
    <div class="am-notice am-notice-error"><strong>DBエラー：</strong><?php echo esc_html( $db_error ); ?></div>
    <?php endif; ?>

    <div class="am-card">
        <div class="am-card-header"><span class="dashicons dashicons-search"></span> 集計条件の選択</div>
        <div class="am-card-body">
            <form method="GET" action="<?php echo esc_url( $page_url ); ?>">
                <input type="hidden" name="page" value="attendance-manager-jiba">
                <?php
                $affil_map = [];
                foreach ( $employees as $emp ) {
                    $aid = (int) $emp['affiliation_id'];
                    if ( ! isset( $affil_map[ $aid ] ) ) $affil_map[ $aid ] = $emp['affiliation_name'];
                }
                ?>
                <?php if ( count( $affil_map ) > 1 ) : ?>
                <div class="am-affil-chips">
                    <button type="button" class="am-chip am-chip-active" data-affil="all">すべて</button>
                    <?php foreach ( $affil_map as $aid => $aname ) : ?>
                    <button type="button" class="am-chip" data-affil="<?php echo esc_attr( $aid ); ?>"><?php echo esc_html( $aname ); ?></button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="am-form-row">
                    <div class="am-form-group">
                        <label class="am-label" for="am-select-emp">社員名</label>
                        <select id="am-select-emp" name="am_emp" class="am-select">
                            <option value="">― 社員を選択 ―</option>
                            <?php foreach ( $employees as $emp ) : ?>
                            <option value="<?php echo esc_attr( $emp['employee_code'] ); ?>"
                                data-affil="<?php echo esc_attr( $emp['affiliation_id'] ); ?>"
                                <?php selected( $selected_emp, $emp['employee_code'] ); ?>>
                                [<?php echo esc_html( $emp['employee_code'] ); ?>] <?php echo esc_html( $emp['name'] ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="am-form-group">
                        <label class="am-label" for="am-select-month-jiba">対象月</label>
                        <input type="month" id="am-select-month-jiba" name="am_month" class="am-input-month" value="<?php echo esc_attr( $selected_month ); ?>">
                    </div>
                    <div class="am-form-group am-form-group--btn">
                        <button type="submit" id="am-btn-open-jiba" class="am-btn am-btn-primary" <?php echo ( $selected_emp === '' ) ? 'disabled' : ''; ?>>
                            <span class="dashicons dashicons-chart-bar"></span> 集計表を開く
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ( $emp_info !== null ) : ?>
    <div class="am-card">
        <?php
        $prev_month = date( 'Y-m', strtotime( $selected_month . '-01 -1 month' ) );
        $next_month = date( 'Y-m', strtotime( $selected_month . '-01 +1 month' ) );
        $prev_url   = esc_url( add_query_arg( [ 'page' => 'attendance-manager-jiba', 'am_emp' => $selected_emp, 'am_month' => $prev_month ], admin_url( 'admin.php' ) ) );
        $next_url   = esc_url( add_query_arg( [ 'page' => 'attendance-manager-jiba', 'am_emp' => $selected_emp, 'am_month' => $next_month ], admin_url( 'admin.php' ) ) );
        ?>
        <div class="am-card-header" style="justify-content:space-between;">
            <span style="display:flex;align-items:center;gap:12px;">
                <span class="dashicons dashicons-id-alt"></span>
                <a href="<?php echo $prev_url; ?>" class="am-btn am-btn-nav" title="前月"><span class="dashicons dashicons-arrow-left-alt2"></span></a>
                <span><?php echo esc_html( $selected_month ); ?>　<?php echo esc_html( $emp_info['name'] ); ?> さんの集計表（地場・事務）</span>
                <a href="<?php echo $next_url; ?>" class="am-btn am-btn-nav" title="翌月"><span class="dashicons dashicons-arrow-right-alt2"></span></a>
            </span>
            <button type="button" id="am-btn-save-jiba" class="am-btn am-btn-save"
                data-emp="<?php echo esc_attr( $selected_emp ); ?>"
                data-month="<?php echo esc_attr( $selected_month ); ?>">
                <span class="dashicons dashicons-saved"></span> 保存（更新）
            </button>
        </div>
        <div id="am-save-message-jiba" style="display:none;padding:8px 20px;font-size:13px;font-weight:700;"></div>
        <div class="am-card-body">

            <table class="am-info-table">
                <thead><tr><th>社員名</th><th>社員No.</th><th>所属</th></tr></thead>
                <tbody><tr>
                    <td><?php echo esc_html( $emp_info['name'] ); ?></td>
                    <td><?php echo esc_html( $emp_info['employee_code'] ); ?></td>
                    <td><?php echo esc_html( $emp_info['affiliation_name'] ); ?></td>
                </tr></tbody>
            </table>

            <?php $alerts = $monthly_rows[0]['_alerts'] ?? []; if ( ! empty( $alerts ) ) : ?>
            <div class="am-alerts">
                <?php foreach ( $alerts as $alert ) : ?>
                <div class="am-alert <?php echo $alert['type'] === 'error' ? 'am-alert-error' : 'am-alert-warn'; ?>">
                    <span class="dashicons <?php echo $alert['type'] === 'error' ? 'dashicons-warning' : 'dashicons-info'; ?>"></span>
                    <?php echo esc_html( $alert['message'] ); ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="am-table-wrap">
                <table class="am-main-table">
                    <thead>
                        <tr>
                            <th class="col-date">日付</th>
                            <th class="col-kintai">勤怠種別</th>
                            <th class="col-time">始業時刻</th>
                            <th class="col-time">終業時刻</th>
                            <th class="col-min">拘束時間</th>
                            <th class="col-min">労働時間</th>
                            <th class="col-min">運転時間</th>
                            <th class="col-min">積卸時間</th>
                            <th class="col-min">休憩時間</th>
                            <th class="col-min">深夜時間</th>
                            <th class="col-min">日残業</th>
                            <th class="col-jiba">長距離</th>
                            <th class="col-min">早退/遅刻</th>
                            <th class="col-note">備考</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $monthly_rows as $row ) :
                        $row_class  = '';
                        $kintai_val = $row['default_kintai'];
                        if ( $row['is_sun'] )         $row_class = 'am-row-sun';
                        elseif ( $row['is_sat'] )     $row_class = 'am-row-sat';
                        elseif ( ! $row['has_data'] ) $row_class = 'am-row-off';
                    ?>
                        <tr class="<?php echo $row_class; ?>"
                            data-date="<?php echo esc_attr( $row['date'] ); ?>"
                            data-auto="<?php echo $row['is_manual'] ? 'false' : 'true'; ?>"
                            data-furikae="<?php echo esc_attr( $row['furikae_label'] ); ?>">
                            <td class="col-date">
                                <span class="am-date-row">
                                    <?php echo esc_html( substr( $row['date'], 5 ) ); ?>
                                    <span class="am-dow"><?php echo esc_html( $row['dow'] ); ?></span>
                                </span>
                                <?php if ( ! empty( $row['houtei_kinmu'] ) ) : ?>
                                    <span class="am-badge-houtei-kinmu">法定休出勤</span>
                                <?php endif; ?>
                                <?php if ( ! empty( $row['shitei_kinmu'] ) ) : ?>
                                    <span class="am-badge-shitei-kinmu">所定休出勤</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-kintai">
                                <select class="am-kintai-select">
                                    <?php foreach ( $kintai_types as $kt ) : ?>
                                    <option value="<?php echo esc_attr( $kt ); ?>" <?php selected( $kintai_val, $kt ); ?>><?php echo esc_html( $kt ); ?></option>
                                    <?php endforeach; ?>
                                    <option value="" <?php selected( $kintai_val, '' ); ?>>―</option>
                                </select>
                            </td>
                            <td class="col-time"><?php echo esc_html( $row['start_time'] ?? '' ); ?></td>
                            <td class="col-time"><?php echo esc_html( $row['end_time']   ?? '' ); ?></td>
                            <td class="col-min"><?php echo esc_html( AM_Compute_Chokyo::format_min( $row['kousoku_min'] ) ); ?></td>
                            <td class="col-min"><?php echo esc_html( AM_Compute_Chokyo::format_min( $row['labor_min'] ) ); ?></td>
                            <td class="col-min"><?php echo esc_html( AM_Compute_Chokyo::format_min( $row['drive_min'] ) ); ?></td>
                            <td class="col-min"><?php echo esc_html( AM_Compute_Chokyo::format_min( $row['cargo_min'] ) ); ?></td>
                            <td class="col-min"><?php echo esc_html( AM_Compute_Chokyo::format_min( $row['break_calc_min'] ) ); ?></td>
                            <td class="col-min"><?php echo esc_html( AM_Compute_Chokyo::format_min( $row['midnight_min'] ) ); ?></td>
                            <td class="col-min <?php echo ( (int)( $row['overtime_min'] ?? 0 ) > 0 ) ? 'am-cell-over' : ''; ?>">
                                <?php echo esc_html( AM_Compute_Chokyo::format_min( $row['overtime_min'] ) ); ?>
                            </td>
                            <td class="col-jiba">
                                <label class="am-toggle">
                                    <input type="checkbox" class="am-chokyo-input" <?php echo ( $row['chokyo'] ?? false ) ? 'checked' : ''; ?>>
                                    <span class="am-toggle-slider"></span>
                                </label>
                            </td>
                            <td class="col-min">
                                <input type="text" class="am-hayatai-input"
                                    value="<?php echo $row['hayatai_min'] > 0 ? esc_attr( AM_Compute_Chokyo::format_min( $row['hayatai_min'] ) ) : ''; ?>"
                                    placeholder="0:00">
                            </td>
                            <td class="col-note">
                                <input type="text" class="am-note-input" value="<?php echo esc_attr( $row['note'] ?? '' ); ?>" placeholder="備考">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ( $weekly ) : ?>
            <?php include AM_PLUGIN_DIR . 'templates/_weekly-table.php'; ?>
            <?php endif; ?>

            <?php if ( $monthly_summary !== null ) : ?>
            <?php include AM_PLUGIN_DIR . 'templates/_monthly-summary.php'; ?>
            <?php endif; ?>

        </div>
    </div>
    <?php endif; ?>
</div>