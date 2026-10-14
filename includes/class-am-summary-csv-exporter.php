<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 集計一覧用の一時的なCSV出力。
 *
 * 将来、全ツール共通のCSV出力に移行することを前提に、出力処理を
 * このクラス内に閉じ込める。
 */
class AM_Summary_CSV_Exporter {

    public static function download() {
        if ( ! current_user_can( 'access_custom_plugins' ) ) {
            wp_die( esc_html__( '権限がありません。', 'attendance-manager' ), '', [ 'response' => 403 ] );
        }

        check_admin_referer( 'am_summary_csv_export' );

        $year_month = sanitize_text_field( wp_unslash( $_GET['year_month'] ?? '' ) );
        if ( ! preg_match( '/\A\d{4}-(0[1-9]|1[0-2])\z/', $year_month ) ) {
            wp_die( esc_html__( '対象月が不正です。', 'attendance-manager' ), '', [ 'response' => 400 ] );
        }

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="attendance-summary-' . $year_month . '.csv"' );

        $output = fopen( 'php://output', 'w' );
        fwrite( $output, "\xEF\xBB\xBF" ); // Excelで日本語を開けるようUTF-8 BOMを付与。
        fputcsv( $output, [
            '氏名', '職種', '総労働時間（点呼時間含む）', '確定残業時間', '確定残業が60時間を超えた分の数値',
            '深夜時間', '出勤日数', '法定休日出勤日数', '有給消化日数', '法定休日労働時間', '積卸時間',
            '所定休日勤務実績_1', '所定休日勤務実績_1の労働時間',
            '所定休日勤務実績_2', '所定休日勤務実績_2の労働時間',
            '所定休日勤務実績_3', '所定休日勤務実績_3の労働時間',
            '所定休日勤務実績_4', '所定休日勤務実績_4の労働時間',
            '所定休日勤務実績_5', '所定休日勤務実績_5の労働時間',
        ] );

        self::write_category( $output, 'chokyo', $year_month );
        self::write_category( $output, 'jiba', $year_month );
        fclose( $output );
        exit;
    }

    private static function write_category( $output, $category, $year_month ) {
        $employees = AM_DB::get_employees_by_category( $category );
        foreach ( $employees['employees'] as $employee ) {
            if ( 'chokyo' === $category ) {
                // 長距離の計算処理は、乗務員コードではなく不変の社員IDを受け取る。
                $key = (int) ( $employee['employee_id'] ?? 0 );
                if ( ! $key ) continue;
                $compute = 'AM_Compute_Chokyo';
            } else {
                $key = $employee['employee_code'] ?? '';
                if ( '' === $key ) continue;
                $compute = 'AM_Compute_Jiba';
            }

            $rows     = $compute::get_monthly_rows( $key, $year_month, $employee['name'] );
            $weekly   = $compute::get_weekly_summary( $key, $year_month, $rows );
            $summary  = ! empty( $rows ) ? $compute::get_monthly_summary( $rows, $weekly, $key, $year_month ) : [];

            $shitei_records = [];
            foreach ( $rows as $row ) {
                if ( ! empty( $row['shitei_kinmu'] ) ) {
                    $shitei_records[] = [
                        $row['date'] ?? ( $row['work_date'] ?? '' ),
                        self::format_minutes( $row['labor_min'] ?? 0 ),
                    ];
                }
            }

            $total        = $weekly['total'] ?? [];
            $overtime_min = (int) ( $summary['overtime_min'] ?? 0 );
            $job_type     = $employee['job_type_name'] ?? '';
            $attendance   = (int) ( $summary['attendance'] ?? 0 );
            $labor_min    = (int) ( $summary['labor_min'] ?? 0 );
            // 補正時間の適用開始月以降は、週集計に各日の実際の補正時間が含まれている。
            // 適用開始月より前だけ、従来どおり出勤1日あたり10分の点呼時間を加算する。
            if ( $year_month < AM_DB::get_hosei_start_month()
                && in_array( $job_type, [ '長距離', '郵便' ], true ) ) {
                $labor_min += 10 * $attendance;
            }
            $record = [
                $employee['name'],
                $job_type,
                self::format_minutes( $labor_min ),
                self::format_minutes( $overtime_min ),
                self::format_minutes( max( 0, $overtime_min - 3600 ) ),
                self::format_minutes( $total['midnight_min'] ?? 0 ),
                $attendance,
                (int) ( $summary['unmatched_houtei_days'] ?? 0 ),
                ! empty( $summary['paid_has_data'] ) ? (float) $summary['paid_consumed'] : '',
                self::format_minutes( $summary['unmatched_houtei_labor_min'] ?? 0 ),
                self::format_minutes( $total['cargo_min'] ?? 0 ),
            ];
            for ( $i = 0; $i < 5; $i++ ) {
                $record = array_merge( $record, $shitei_records[$i] ?? [ '', '' ] );
            }
            fputcsv( $output, $record );
        }
    }

    private static function format_minutes( $minutes ) {
        $minutes = max( 0, (int) $minutes );
        // 分を時間単位の10進数に変換し、小数第2位を四捨五入して小数第1位まで出力する。
        return number_format( $minutes / 60, 1, '.', '' );
    }
}
