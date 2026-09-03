<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="am-table-wrap am-weekly-wrap">
    <table class="am-weekly-table">
        <thead>
            <tr>
                <th class="wcol-label">期間</th><th class="wcol-date">開始日</th><th class="wcol-date">終了日</th>
                <th class="wcol-days">日数</th><th class="wcol-min">拘束時間</th><th class="wcol-min">労働時間</th>
                <th class="wcol-min">運転時間</th><th class="wcol-min">積卸時間</th><th class="wcol-min">休憩時間</th>
                <th class="wcol-min">深夜時間</th><th class="wcol-min">日残業</th><th class="wcol-min">週残業</th>
                <th class="wcol-min">確定残業</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $weekly['weeks'] as $w ) :
            $w_class = '';
            if ( $w['is_carryover'] )  $w_class = 'am-week-carryover';
            if ( $w['is_prev_carry'] ) $w_class = 'am-week-prev-carry';
        ?>
        <tr class="<?php echo $w_class; ?>">
            <td class="wcol-label"><?php echo esc_html( $w['label'] ); ?></td>
            <td class="wcol-date"><?php echo esc_html( $w['disp_start'] ); ?></td>
            <td class="wcol-date"><?php echo esc_html( $w['disp_end'] ); ?></td>
            <td class="wcol-days"><?php echo esc_html( $w['days'] ); ?>日</td>
            <?php if ( $w['is_prev_carry'] ) : ?>
            <td class="wcol-min am-cell-na">―</td>
            <td class="wcol-min" title="前月繰越分の労働時間。第1週計の週残業判定に加算されます。">
                <?php echo esc_html( Tanpopo_AttendanceManager::format_min( $w['labor_min'] ) ); ?>
            </td>
            <td class="wcol-min am-cell-na">―</td><td class="wcol-min am-cell-na">―</td>
            <td class="wcol-min am-cell-na">―</td><td class="wcol-min am-cell-na">―</td>
            <td class="wcol-min <?php echo ( (int)$w['day_overtime_min'] > 0 ) ? 'am-cell-over' : ''; ?>"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $w['day_overtime_min'] ) ); ?></td>
            <td class="wcol-min am-cell-na">―</td>
            <td class="wcol-min am-cell-na">―</td>
            <?php else : ?>
            <td class="wcol-min"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $w['kousoku_min'] ) ); ?></td>
            <td class="wcol-min"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $w['labor_min'] ) ); ?></td>
            <td class="wcol-min"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $w['drive_min'] ) ); ?></td>
            <td class="wcol-min"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $w['cargo_min'] ) ); ?></td>
            <td class="wcol-min"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $w['break_min'] ) ); ?></td>
            <td class="wcol-min"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $w['midnight_min'] ) ); ?></td>
            <td class="wcol-min <?php echo ( (int)$w['day_overtime_min'] > 0 ) ? 'am-cell-over' : ''; ?>"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $w['day_overtime_min'] ) ); ?></td>
            <?php if ( $w['is_carryover'] ) : ?>
            <td class="wcol-min"><span class="am-badge-carryover">次月繰越</span></td>
            <?php else : ?>
            <td class="wcol-min <?php echo ( (int)$w['week_overtime_min'] > 0 ) ? 'am-cell-over' : ''; ?>"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $w['week_overtime_min'] ) ); ?></td>
            <?php endif; ?>
            <td class="wcol-min <?php echo ( (int)$w['confirmed_overtime'] > 0 ) ? 'am-cell-over' : ''; ?> am-cell-confirmed"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $w['confirmed_overtime'] ) ); ?></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td class="wcol-label" colspan="4">合　計</td>
                <td class="wcol-min"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $weekly['total']['kousoku_min'] ) ); ?></td>
                <td class="wcol-min"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $weekly['total']['labor_min'] ) ); ?></td>
                <td class="wcol-min"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $weekly['total']['drive_min'] ) ); ?></td>
                <td class="wcol-min"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $weekly['total']['cargo_min'] ) ); ?></td>
                <td class="wcol-min"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $weekly['total']['break_min'] ) ); ?></td>
                <td class="wcol-min"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $weekly['total']['midnight_min'] ) ); ?></td>
                <td class="wcol-min <?php echo ( (int)$weekly['total']['day_overtime_min'] > 0 ) ? 'am-cell-over' : ''; ?>" title="1日～末日合計"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $weekly['total']['day_overtime_min'] ) ); ?></td>
                <td class="wcol-min <?php echo ( (int)$weekly['total']['week_overtime_min'] > 0 ) ? 'am-cell-over' : ''; ?>" title="1日～末日合計"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $weekly['total']['week_overtime_min'] ) ); ?></td>
                <td class="wcol-min <?php echo ( (int)$weekly['total']['confirmed_overtime'] > 0 ) ? 'am-cell-over' : ''; ?> am-cell-confirmed"><?php echo esc_html( Tanpopo_AttendanceManager::format_min( $weekly['total']['confirmed_overtime'] ) ); ?></td>
            </tr>
        </tfoot>
    </table>
</div>
