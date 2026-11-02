<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-am-xlsx-reader.php';

/**
 * 拘束時間管理表 xlsx → wp_kousoku_log インポーター。
 *
 * サーバー側の import_kousoku.php（毎朝6:00の自動取込）と同じ仕様を、プラグインに移したもの。
 *   - xlsx の1行目はヘッダー
 *   - 始業時刻が空の行（休日／非乗務日）はスキップ
 *   - crew_code + work_date をキーに UPSERT（何度実行しても同じ結果になる）
 *   - wp_emp_master に存在しない crew_code の行はスキップ（wp_emp_master には書き込まない）
 */
class AM_Kousoku_XLSX_Importer {

    /** xlsx の列位置（A列始まり）。 */
    const COL = [
        'crew_code'  => 'A',
        'work_date'  => 'C',
        'start'      => 'D',
        'end'        => 'E',
        'drive'      => 'F',
        'drive_ov'   => 'G',
        'cargo'      => 'H',
        'cargo_ov'   => 'I',
        'break'      => 'J',
        'break_ov'   => 'K',
        'kou_sub'    => 'L',
        'kou_sub_ov' => 'M',
        'kou_total'  => 'N',
        'kou_cumul'  => 'O',
        'avg_before' => 'P',
        'avg_after'  => 'Q',
        'rest'       => 'R',
        'actual'     => 'S',
        'overtime'   => 'T',
        'midnight'   => 'U',
        'ot_mid'     => 'V',
        'remarks1'   => 'W',
        'remarks2'   => 'X',
    ];

    /** INSERT する列 => プレースホルダ型。 */
    const COLUMNS = [
        'crew_code'             => '%s',
        'work_date'             => '%s',
        'start_time'            => '%s',
        'end_time'              => '%s',
        'end_next_day'          => '%d',
        'drive_min'             => '%d',
        'drive_overlap_min'     => '%d',
        'cargo_min'             => '%d',
        'cargo_overlap_min'     => '%d',
        'break_min'             => '%d',
        'break_overlap_min'     => '%d',
        'kousoku_subtotal_min'  => '%d',
        'kousoku_overlap_min'   => '%d',
        'kousoku_total_min'     => '%d',
        'kousoku_cumul_min'     => '%d',
        'drive_avg_before_min'  => '%d',
        'drive_avg_after_min'   => '%d',
        'rest_min'              => '%d',
        'actual_work_min'       => '%d',
        'overtime_min'          => '%d',
        'midnight_min'          => '%d',
        'overtime_midnight_min' => '%d',
        'remarks1'              => '%s',
        'remarks2'              => '%s',
    ];

    /* ---------------------------------------------------------------
     * 変換ユーティリティ（import_kousoku.php と同じ）
     * ------------------------------------------------------------- */

    /** セル値 → 分（int）。Excelの時刻（1日=1.0）も "H:MM" 文字列も受け付ける。 */
    public static function cell_to_min( $val ) {
        if ( null === $val || '' === $val ) return null;

        if ( is_int( $val ) || is_float( $val ) ) {
            return (int) round( (float) $val * 1440 );
        }
        if ( is_string( $val ) && preg_match( '/^(\d+):(\d{2})$/', trim( $val ), $m ) ) {
            return (int) $m[1] * 60 + (int) $m[2];
        }
        return null;
    }

    /** セル値 → "HH:MM:00"（TIME型）。24時間を超える分は当日内に収める。 */
    public static function cell_to_time_str( $val ) {
        $min = self::cell_to_min( $val );
        if ( null === $min ) return null;
        return sprintf( '%02d:%02d:00', intdiv( $min, 60 ) % 24, $min % 60 );
    }

    /** セル値 → "YYYY-MM-DD"。Excelシリアル値と "YYYY/M/D" 文字列に対応。 */
    public static function cell_to_date( $val ) {
        if ( null === $val || '' === $val ) return null;

        if ( is_numeric( $val ) ) {
            $days = (int) floor( (float) $val );
            if ( $days < 1 ) return null;
            $dt = new DateTimeImmutable( '1899-12-30 00:00:00', new DateTimeZone( 'UTC' ) );
            return $dt->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
        }
        if ( is_string( $val ) && preg_match( '/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', trim( $val ), $m ) ) {
            return sprintf( '%04d-%02d-%02d', $m[1], $m[2], $m[3] );
        }
        return null;
    }

    /**
     * 1行分のセル（列記号 => 値）を、DB列 => 値 の配列に変換する。
     *
     * @return array [ 'status' => 'ok'|'blank_start'|'bad_date', 'data' => array|null ]
     */
    public static function parse_row( array $cells ) {
        $get = function ( $key ) use ( $cells ) {
            $val = $cells[ self::COL[ $key ] ] ?? null;
            return ( null === $val || '' === $val ) ? null : $val;
        };

        // 始業時刻が空 → 休日／非乗務日の行
        $start_raw = $get( 'start' );
        if ( null === $start_raw ) {
            return [ 'status' => 'blank_start', 'data' => null ];
        }

        $work_date = self::cell_to_date( $get( 'work_date' ) );
        if ( null === $work_date ) {
            return [ 'status' => 'bad_date', 'data' => null ];
        }

        // 乗務員コード：数値→文字列（先頭の0などは落ちる。import_kousoku.php と同じ扱い）
        $crew_code = (string) (int) $get( 'crew_code' );

        // 日跨ぎ判定（終業分 < 始業分 → 翌日終業）
        $start_min    = self::cell_to_min( $start_raw );
        $end_raw      = $get( 'end' );
        $end_min      = self::cell_to_min( $end_raw );
        $end_next_day = ( null !== $end_min && null !== $start_min && $end_min < $start_min ) ? 1 : 0;

        $text = function ( $key ) use ( $get ) {
            $v = $get( $key );
            return null === $v ? null : (string) $v;
        };

        return [
            'status' => 'ok',
            'data'   => [
                'crew_code'             => $crew_code,
                'work_date'             => $work_date,
                'start_time'            => self::cell_to_time_str( $start_raw ),
                'end_time'              => self::cell_to_time_str( $end_raw ),
                'end_next_day'          => $end_next_day,
                'drive_min'             => self::cell_to_min( $get( 'drive' ) ) ?? 0,
                'drive_overlap_min'     => self::cell_to_min( $get( 'drive_ov' ) ),
                'cargo_min'             => self::cell_to_min( $get( 'cargo' ) ),
                'cargo_overlap_min'     => self::cell_to_min( $get( 'cargo_ov' ) ),
                'break_min'             => self::cell_to_min( $get( 'break' ) ),
                'break_overlap_min'     => self::cell_to_min( $get( 'break_ov' ) ),
                'kousoku_subtotal_min'  => self::cell_to_min( $get( 'kou_sub' ) ) ?? 0,
                'kousoku_overlap_min'   => self::cell_to_min( $get( 'kou_sub_ov' ) ),
                'kousoku_total_min'     => self::cell_to_min( $get( 'kou_total' ) ) ?? 0,
                'kousoku_cumul_min'     => self::cell_to_min( $get( 'kou_cumul' ) ) ?? 0,
                'drive_avg_before_min'  => self::cell_to_min( $get( 'avg_before' ) ),
                'drive_avg_after_min'   => self::cell_to_min( $get( 'avg_after' ) ) ?? 0,
                'rest_min'              => self::cell_to_min( $get( 'rest' ) ),
                'actual_work_min'       => self::cell_to_min( $get( 'actual' ) ) ?? 0,
                'overtime_min'          => self::cell_to_min( $get( 'overtime' ) ),
                'midnight_min'          => self::cell_to_min( $get( 'midnight' ) ),
                'overtime_midnight_min' => self::cell_to_min( $get( 'ot_mid' ) ),
                'remarks1'              => $text( 'remarks1' ),
                'remarks2'              => $text( 'remarks2' ),
            ],
        ];
    }

    /* ---------------------------------------------------------------
     * 取り込み本体
     * ------------------------------------------------------------- */

    /**
     * xlsx を読み込み、wp_kousoku_log へ UPSERT する。
     *
     * @return array [ rows, inserted, updated, skipped, errors, unknown_codes, message ]
     * @throws RuntimeException ファイル形式の不正など、取り込みを始められない場合
     */
    public static function import_file( $path ) {
        global $wpdb;

        if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );
        if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 300 );

        $rows = AM_XLSX_Reader::read( $path );

        // ヘッダー行の確認（別の形式のファイルを誤って取り込まない）
        $header = $rows[1] ?? [];
        if ( ( $header['A'] ?? '' ) !== '乗務員コード' || ( $header['C'] ?? '' ) !== '日付' ) {
            throw new RuntimeException( '拘束時間管理表の形式ではありません（見出し行が一致しません）。' );
        }

        $codes = $wpdb->get_col( "SELECT crew_code FROM `{$wpdb->prefix}emp_master` WHERE crew_code IS NOT NULL" );
        if ( $wpdb->last_error ) {
            throw new RuntimeException( '社員マスタを読み込めません: ' . $wpdb->last_error );
        }
        $valid_codes = array_flip( array_map( 'strval', (array) $codes ) );

        $table   = $wpdb->prefix . 'kousoku_log';
        $result  = [
            'rows'          => 0,
            'inserted'      => 0,
            'updated'       => 0,
            'skipped'       => 0,
            'errors'        => [],
            'unknown_codes' => [],
        ];

        foreach ( $rows as $row_no => $cells ) {
            if ( $row_no < 2 ) continue; // ヘッダー
            $result['rows']++;

            $parsed = self::parse_row( $cells );
            if ( 'ok' !== $parsed['status'] ) {
                $result['skipped']++;
                continue;
            }
            $data = $parsed['data'];

            if ( ! isset( $valid_codes[ $data['crew_code'] ] ) ) {
                $result['skipped']++;
                $result['unknown_codes'][ $data['crew_code'] ] = true;
                continue;
            }

            $sql = self::build_upsert_sql( $table, $data );
            $res = $wpdb->query( $sql );
            if ( false === $res ) {
                if ( count( $result['errors'] ) < 5 ) {
                    $result['errors'][] = sprintf( '%d行目(乗務員%s %s): %s', $row_no, $data['crew_code'], $data['work_date'], $wpdb->last_error );
                } elseif ( count( $result['errors'] ) === 5 ) {
                    $result['errors'][] = '…（以降のエラーは省略）';
                }
                continue;
            }
            // INSERT ... ON DUPLICATE KEY UPDATE の戻り値：1=新規、2=更新、0=変更なし
            if ( 1 === (int) $res ) {
                $result['inserted']++;
            } else {
                $result['updated']++;
            }
        }

        $result['unknown_codes'] = array_map( 'strval', array_keys( $result['unknown_codes'] ) );
        $result['message']       = self::summary( $result );
        return $result;
    }

    /** UPSERT 文を組み立てる（NULL は SQL の NULL として入れる）。 */
    private static function build_upsert_sql( $table, array $data ) {
        global $wpdb;

        $cols = [];
        $ph   = [];
        $args = [];
        foreach ( self::COLUMNS as $col => $type ) {
            $cols[] = "`{$col}`";
            if ( null === $data[ $col ] ) {
                $ph[] = 'NULL';
            } else {
                $ph[]   = $type;
                $args[] = $data[ $col ];
            }
        }

        $updates = [];
        foreach ( array_keys( self::COLUMNS ) as $col ) {
            if ( 'crew_code' === $col || 'work_date' === $col ) continue;
            $updates[] = "`{$col}` = VALUES(`{$col}`)";
        }
        $updates[] = '`updated_at` = CURRENT_TIMESTAMP';

        $sql = "INSERT INTO `{$table}` (" . implode( ', ', $cols ) . ') VALUES (' . implode( ', ', $ph ) . ') '
             . 'ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );

        return empty( $args ) ? $sql : $wpdb->prepare( $sql, ...$args );
    }

    /** 結果メッセージ（画面の履歴に表示する）。 */
    public static function summary( array $r ) {
        $msg = sprintf( '新規%d件・更新%d件・スキップ%d件', $r['inserted'], $r['updated'], $r['skipped'] );
        if ( ! empty( $r['unknown_codes'] ) ) {
            $list = array_slice( $r['unknown_codes'], 0, 5 );
            $more = count( $r['unknown_codes'] ) > 5 ? '他' : '';
            $msg .= '（社員マスタ未登録の乗務員コード: ' . implode( ',', $list ) . $more . '）';
        }
        if ( ! empty( $r['errors'] ) ) {
            $msg .= ' / エラー: ' . implode( ' ', $r['errors'] );
        }
        return $msg;
    }
}
