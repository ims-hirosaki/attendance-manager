<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * wp_kousoku_log CSVインポーター。
 *
 * phpMyAdmin のCSVエクスポート（全カラム）を対象とし、
 * 通常は既存の crew_code + work_date をスキップする。
 */
class AM_Kousoku_CSV_Importer {

    const MAX_FILE_SIZE = 20971520; // 20 MB
    const MAX_ROWS      = 100000;

    const COLUMNS = [
        'id', 'crew_code', 'work_date', 'start_time', 'end_time', 'end_next_day',
        'drive_min', 'drive_overlap_min', 'cargo_min', 'cargo_overlap_min',
        'break_min', 'break_overlap_min', 'kousoku_subtotal_min',
        'kousoku_overlap_min', 'kousoku_total_min', 'kousoku_cumul_min',
        'drive_avg_before_min', 'drive_avg_after_min', 'rest_min',
        'actual_work_min', 'overtime_min', 'midnight_min',
        'overtime_midnight_min', 'remarks1', 'remarks2', 'created_at', 'updated_at',
    ];

    const INPUT_COLUMNS = [
        'crew_code', 'work_date', 'start_time', 'end_time', 'end_next_day',
        'drive_min', 'drive_overlap_min', 'cargo_min', 'cargo_overlap_min',
        'break_min', 'break_overlap_min', 'kousoku_subtotal_min',
        'kousoku_overlap_min', 'kousoku_total_min', 'kousoku_cumul_min',
        'drive_avg_before_min', 'drive_avg_after_min', 'rest_min',
        'actual_work_min', 'overtime_min', 'midnight_min',
        'overtime_midnight_min', 'remarks1', 'remarks2',
    ];

    const JAPANESE_HEADERS = [
        '乗務員コード'             => 'crew_code',
        '日付'                     => 'work_date',
        '始業時刻'                 => 'start_time',
        '終業時刻'                 => 'end_time',
        '終業翌日フラグ'           => 'end_next_day',
        '運転時間（分）'           => 'drive_min',
        '重複運転時間（分）'       => 'drive_overlap_min',
        '荷役時間（分）'           => 'cargo_min',
        '重複荷役時間（分）'       => 'cargo_overlap_min',
        '休憩時間（分）'           => 'break_min',
        '重複休憩時間（分）'       => 'break_overlap_min',
        '拘束時間小計（分）'       => 'kousoku_subtotal_min',
        '重複拘束時間小計（分）'   => 'kousoku_overlap_min',
        '拘束時間合計（分）'       => 'kousoku_total_min',
        '拘束時間累計（分）'       => 'kousoku_cumul_min',
        '前運転平均（分）'         => 'drive_avg_before_min',
        '後運転平均（分）'         => 'drive_avg_after_min',
        '休息時間（分）'           => 'rest_min',
        '実働時間（分）'           => 'actual_work_min',
        '時間外時間（分）'         => 'overtime_min',
        '深夜時間（分）'           => 'midnight_min',
        '時間外深夜時間（分）'     => 'overtime_midnight_min',
        '摘要1'                    => 'remarks1',
        '摘要2'                    => 'remarks2',
    ];

    const REQUIRED_INT_COLUMNS = [
        'id', 'end_next_day', 'drive_min', 'kousoku_subtotal_min',
        'kousoku_total_min', 'kousoku_cumul_min', 'drive_avg_after_min',
        'actual_work_min',
    ];

    const NULLABLE_INT_COLUMNS = [
        'drive_overlap_min', 'cargo_min', 'cargo_overlap_min', 'break_min',
        'break_overlap_min', 'kousoku_overlap_min', 'drive_avg_before_min',
        'rest_min', 'overtime_min', 'midnight_min', 'overtime_midnight_min',
    ];

    /** 管理画面からのアップロードを処理する。 */
    public static function handle_import() {
        if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) {
            wp_die( esc_html__( '権限がありません。', 'attendance-manager' ), '', [ 'response' => 403 ] );
        }

        check_admin_referer( 'am_kousoku_csv_import', 'am_kousoku_csv_nonce' );
        wp_raise_memory_limit( 'admin' );
        if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 300 );

        $overwrite = ! empty( $_POST['overwrite_existing'] );
        $result    = [
            'success'   => false,
            'mode'      => $overwrite ? 'overwrite' : 'skip',
            'inserted'  => 0,
            'updated'   => 0,
            'skipped'   => 0,
            'processed' => 0,
            'message'   => '',
        ];

        try {
            $file   = isset( $_FILES['kousoku_csv'] ) ? $_FILES['kousoku_csv'] : null;
            $result = self::import_uploaded_file( $file, $overwrite );
        } catch ( Throwable $e ) {
            $result['message'] = $e->getMessage();
        }

        set_transient( self::result_key(), $result, 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=attendance-manager-kousoku-import&am_imported=1' ) );
        exit;
    }

    /** 直前の取込結果を一度だけ返す。 */
    public static function consume_result() {
        $key    = self::result_key();
        $result = get_transient( $key );
        if ( false !== $result ) delete_transient( $key );
        return false === $result ? null : $result;
    }

    /** 日本語見出しの入力用ひな形をダウンロードする。 */
    public static function download_template() {
        if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) {
            wp_die( esc_html__( '権限がありません。', 'attendance-manager' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'am_kousoku_csv_template' );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="kousoku_import_template.csv"' );

        $output = fopen( 'php://output', 'wb' );
        if ( false === $output ) wp_die( 'ひな形CSVを生成できませんでした。' );
        fwrite( $output, "\xEF\xBB\xBF" );
        fputcsv( $output, array_keys( self::JAPANESE_HEADERS ) );
        fclose( $output );
        exit;
    }

    private static function result_key() {
        return 'am_kousoku_csv_import_' . get_current_user_id();
    }

    private static function import_uploaded_file( $file, $overwrite ) {
        if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
            throw new RuntimeException( 'CSVファイルを選択してください。' );
        }

        $upload_error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ( UPLOAD_ERR_OK !== $upload_error ) {
            throw new RuntimeException( self::upload_error_message( $upload_error ) );
        }

        $filename = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
        if ( 'csv' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
            throw new RuntimeException( '拡張子が .csv のファイルを選択してください。' );
        }

        $size = isset( $file['size'] ) ? (int) $file['size'] : 0;
        if ( $size <= 0 || $size > self::MAX_FILE_SIZE ) {
            throw new RuntimeException( 'CSVファイルは20MB以下にしてください。' );
        }
        if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
            throw new RuntimeException( 'アップロードされたファイルを確認できませんでした。' );
        }

        $handle = fopen( $file['tmp_name'], 'rb' );
        if ( false === $handle ) {
            throw new RuntimeException( 'CSVファイルを開けませんでした。' );
        }

        global $wpdb;
        $table       = $wpdb->prefix . 'kousoku_log';
        $transaction = false;

        try {
            $header = self::read_csv_row( $handle );
            if ( null === $header ) throw new RuntimeException( 'CSVファイルが空です。' );
            $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
            $header    = array_map( 'trim', $header );
            $header    = self::normalize_header( $header );
            self::validate_table_columns( $table );

            if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
                self::db_failure( 1, 'トランザクションを開始できませんでした。' );
            }
            $transaction = true;

            $inserted  = 0;
            $updated   = 0;
            $skipped   = 0;
            $processed = 0;
            $line      = 1;

            while ( null !== ( $values = self::read_csv_row( $handle ) ) ) {
                $line++;
                if ( self::is_blank_row( $values ) ) continue;
                if ( ++$processed > self::MAX_ROWS ) {
                    throw new RuntimeException( 'データ行が100,000件を超えています。ファイルを分割してください。' );
                }
                if ( count( $values ) !== count( $header ) ) {
                    throw new RuntimeException( sprintf( '%d行目: カラム数がヘッダーと一致しません。', $line ) );
                }

                $raw       = array_combine( $header, $values );
                $crew_code = self::required_text( $raw['crew_code'], 'crew_code', $line, 20 );
                $work_date = self::date_value( $raw['work_date'], 'work_date', $line );
                $existing  = $wpdb->get_var( $wpdb->prepare(
                    "SELECT `id` FROM `{$table}` WHERE `crew_code` = %s AND `work_date` = %s LIMIT 1",
                    $crew_code,
                    $work_date
                ) );
                self::throw_if_db_error( $line );

                if ( null !== $existing && ! $overwrite ) {
                    $skipped++;
                    continue;
                }

                $data = self::normalize_row( $raw, $line );

                if ( null !== $existing ) {
                    // 既存レコードの主キーと業務キーは変更しない。
                    unset( $data['id'], $data['crew_code'], $data['work_date'] );
                    if ( false === $wpdb->update( $table, $data, [ 'id' => (int) $existing ] ) ) {
                        self::db_failure( $line, '既存行の上書きに失敗しました。' );
                    }
                    $updated++;
                    continue;
                }

                if ( isset( $data['id'] ) ) {
                    $id_owner = $wpdb->get_var( $wpdb->prepare(
                        "SELECT `id` FROM `{$table}` WHERE `id` = %d LIMIT 1",
                        $data['id']
                    ) );
                    self::throw_if_db_error( $line );
                    if ( null !== $id_owner ) {
                        throw new RuntimeException( sprintf( '%d行目: id=%d は別レコードで使用されています。', $line, $data['id'] ) );
                    }
                }

                if ( false === $wpdb->insert( $table, $data ) ) {
                    self::db_failure( $line, '新規行の追加に失敗しました。' );
                }
                $inserted++;
            }

            if ( false === $wpdb->query( 'COMMIT' ) ) {
                self::db_failure( $line, 'コミットに失敗しました。' );
            }
            $transaction = false;

            return [
                'success'   => true,
                'mode'      => $overwrite ? 'overwrite' : 'skip',
                'inserted'  => $inserted,
                'updated'   => $updated,
                'skipped'   => $skipped,
                'processed' => $processed,
                'message'   => 'CSVの取込が完了しました。',
            ];
        } catch ( Throwable $e ) {
            if ( $transaction ) $wpdb->query( 'ROLLBACK' );
            throw $e;
        } finally {
            fclose( $handle );
        }
    }

    private static function read_csv_row( $handle ) {
        $row = fgetcsv( $handle, 0, ',', '"', '\\' );
        return false === $row ? null : $row;
    }

    private static function is_blank_row( $row ) {
        return 1 === count( $row ) && ( null === $row[0] || '' === trim( (string) $row[0] ) );
    }

    private static function normalize_header( $header ) {
        if ( count( $header ) !== count( array_unique( $header ) ) ) {
            throw new RuntimeException( 'CSVヘッダーに重複したカラムがあります。' );
        }

        $mapped = [];
        foreach ( $header as $column ) {
            $mapped[] = isset( self::JAPANESE_HEADERS[ $column ] )
                ? self::JAPANESE_HEADERS[ $column ]
                : $column;
        }
        if ( count( $mapped ) !== count( array_unique( $mapped ) ) ) {
            throw new RuntimeException( '同じ意味のCSVヘッダーが重複しています。' );
        }

        $is_input = ! array_diff( self::INPUT_COLUMNS, $mapped )
            && ! array_diff( $mapped, self::INPUT_COLUMNS );
        $is_full = ! array_diff( self::COLUMNS, $mapped )
            && ! array_diff( $mapped, self::COLUMNS );
        if ( ! $is_input && ! $is_full ) {
            throw new RuntimeException( 'CSVヘッダーがひな形と一致しません。画面から最新のひな形をダウンロードしてください。' );
        }
        return $mapped;
    }

    private static function validate_table_columns( $table ) {
        global $wpdb;
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
        if ( $wpdb->last_error ) {
            throw new RuntimeException( 'wp_kousoku_log の構造を確認できません: ' . $wpdb->last_error );
        }
        $missing = array_values( array_diff( self::COLUMNS, (array) $columns ) );
        if ( $missing ) {
            throw new RuntimeException( 'テーブルに必要なカラムがありません: ' . implode( ', ', $missing ) );
        }
    }

    private static function normalize_row( $raw, $line ) {
        $data = [
            'crew_code'    => self::required_text( $raw['crew_code'], 'crew_code', $line, 20 ),
            'work_date'    => self::date_value( $raw['work_date'], 'work_date', $line ),
            'start_time'   => self::time_value( $raw['start_time'], 'start_time', $line ),
            'end_time'     => self::time_value( $raw['end_time'], 'end_time', $line ),
            'remarks1'     => self::nullable_text( $raw['remarks1'], 'remarks1', $line ),
            'remarks2'     => self::nullable_text( $raw['remarks2'], 'remarks2', $line ),
        ];

        if ( array_key_exists( 'id', $raw ) ) {
            $data['id'] = self::integer_value( $raw['id'], 'id', $line, false );
        }
        foreach ( [ 'created_at', 'updated_at' ] as $column ) {
            if ( array_key_exists( $column, $raw ) ) {
                $data[ $column ] = self::datetime_value( $raw[ $column ], $column, $line );
            }
        }

        foreach ( self::REQUIRED_INT_COLUMNS as $column ) {
            if ( 'id' === $column ) continue;
            $data[ $column ] = self::integer_value( $raw[ $column ], $column, $line, false );
        }
        foreach ( self::NULLABLE_INT_COLUMNS as $column ) {
            $data[ $column ] = self::integer_value( $raw[ $column ], $column, $line, true );
        }

        if ( isset( $data['id'] ) && $data['id'] <= 0 ) {
            throw new RuntimeException( sprintf( '%d行目: id は1以上で入力してください。', $line ) );
        }
        if ( ! in_array( $data['end_next_day'], [ 0, 1 ], true ) ) {
            throw new RuntimeException( sprintf( '%d行目: end_next_day は0または1で入力してください。', $line ) );
        }

        // CSVの並び順に揃え、予期しないキーがDBへ渡らないようにする。
        $ordered = [];
        foreach ( self::COLUMNS as $column ) {
            if ( array_key_exists( $column, $data ) ) $ordered[ $column ] = $data[ $column ];
        }
        return $ordered;
    }

    private static function null_value( $value ) {
        if ( null === $value ) return null;
        $value = (string) $value;
        return '' === $value || 'NULL' === strtoupper( trim( $value ) ) ? null : $value;
    }

    private static function required_text( $value, $column, $line, $max_length ) {
        $value = self::null_value( $value );
        if ( null === $value || '' === trim( $value ) ) {
            throw new RuntimeException( sprintf( '%d行目: %s は必須です。', $line, $column ) );
        }
        $value = trim( $value );
        self::validate_utf8( $value, $column, $line );
        if ( function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) > $max_length : strlen( $value ) > $max_length ) {
            throw new RuntimeException( sprintf( '%d行目: %s が長すぎます。', $line, $column ) );
        }
        return $value;
    }

    private static function nullable_text( $value, $column, $line ) {
        $value = self::null_value( $value );
        if ( null === $value ) return null;
        self::validate_utf8( $value, $column, $line );
        return str_replace( "\0", '', $value );
    }

    private static function integer_value( $value, $column, $line, $nullable ) {
        $value = self::null_value( $value );
        if ( null === $value ) {
            if ( $nullable ) return null;
            throw new RuntimeException( sprintf( '%d行目: %s は必須です。', $line, $column ) );
        }
        if ( ! preg_match( '/^-?\d+$/', trim( $value ) ) ) {
            throw new RuntimeException( sprintf( '%d行目: %s は整数で入力してください。', $line, $column ) );
        }
        return (int) $value;
    }

    private static function date_value( $value, $column, $line ) {
        $value = self::null_value( $value );
        $date  = null === $value ? false : DateTime::createFromFormat( '!Y-m-d', trim( $value ) );
        if ( ! $date || $date->format( 'Y-m-d' ) !== trim( $value ) ) {
            throw new RuntimeException( sprintf( '%d行目: %s は YYYY-MM-DD 形式で入力してください。', $line, $column ) );
        }
        return $date->format( 'Y-m-d' );
    }

    private static function time_value( $value, $column, $line ) {
        $value = self::null_value( $value );
        if ( null === $value || ! preg_match( '/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim( $value ), $m ) ) {
            throw new RuntimeException( sprintf( '%d行目: %s は HH:MM:SS 形式で入力してください。', $line, $column ) );
        }
        $hour = (int) $m[1];
        $min  = (int) $m[2];
        $sec  = isset( $m[3] ) ? (int) $m[3] : 0;
        if ( $hour > 23 || $min > 59 || $sec > 59 ) {
            throw new RuntimeException( sprintf( '%d行目: %s の時刻が不正です。', $line, $column ) );
        }
        return sprintf( '%02d:%02d:%02d', $hour, $min, $sec );
    }

    private static function datetime_value( $value, $column, $line ) {
        $value = self::null_value( $value );
        $date  = null === $value ? false : DateTime::createFromFormat( '!Y-m-d H:i:s', trim( $value ) );
        if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== trim( $value ) ) {
            throw new RuntimeException( sprintf( '%d行目: %s は YYYY-MM-DD HH:MM:SS 形式で入力してください。', $line, $column ) );
        }
        return $date->format( 'Y-m-d H:i:s' );
    }

    private static function validate_utf8( $value, $column, $line ) {
        if ( 1 !== preg_match( '//u', $value ) ) {
            throw new RuntimeException( sprintf( '%d行目: %s がUTF-8ではありません。', $line, $column ) );
        }
    }

    private static function throw_if_db_error( $line ) {
        global $wpdb;
        if ( $wpdb->last_error ) {
            throw new RuntimeException( sprintf( '%d行目: %s', $line, $wpdb->last_error ) );
        }
    }

    private static function db_failure( $line, $fallback ) {
        global $wpdb;
        if ( $wpdb->last_error ) {
            throw new RuntimeException( sprintf( '%d行目: %s', $line, $wpdb->last_error ) );
        }
        throw new RuntimeException( sprintf( '%d行目: %s', $line, $fallback ) );
    }

    private static function upload_error_message( $error ) {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'サーバーのアップロード上限を超えています。',
            UPLOAD_ERR_FORM_SIZE  => 'フォームのアップロード上限を超えています。',
            UPLOAD_ERR_PARTIAL    => 'CSVファイルのアップロードが途中で中断されました。',
            UPLOAD_ERR_NO_FILE    => 'CSVファイルを選択してください。',
            UPLOAD_ERR_NO_TMP_DIR => 'サーバーの一時フォルダーがありません。',
            UPLOAD_ERR_CANT_WRITE => 'サーバーに一時ファイルを書き込めませんでした。',
            UPLOAD_ERR_EXTENSION  => 'サーバー設定によりアップロードが停止されました。',
        ];
        return isset( $messages[ $error ] ) ? $messages[ $error ] : 'CSVファイルのアップロードに失敗しました。';
    }
}
