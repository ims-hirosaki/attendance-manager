<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 最小限の xlsx リーダー（外部ライブラリ不要）。
 *
 * 拘束時間管理表 xlsx（pandas / xlsxwriter が出力する単純な構造）を読むためのもの。
 * 先頭シートの各行を [ 列記号 => 値 ] の配列で返す。
 *   - 数値セルは int / float（日付・時刻もExcelシリアル値のまま）
 *   - 文字列セルは string
 *   - 空セルは含めない
 */
class AM_XLSX_Reader {

    const MAX_FILE_SIZE = 20971520; // 20 MB
    const MAX_ROWS      = 100000;

    const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * @param  string $path xlsx ファイルのパス
     * @return array<int, array<string, mixed>> [ 行番号 => [ 列記号 => 値 ] ]
     * @throws RuntimeException
     */
    public static function read( $path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            throw new RuntimeException( 'ZipArchive が使えないため、xlsx を読み込めません。' );
        }
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            throw new RuntimeException( 'xlsx ファイルを開けません。' );
        }
        $size = (int) filesize( $path );
        if ( $size <= 0 || $size > self::MAX_FILE_SIZE ) {
            throw new RuntimeException( 'xlsx ファイルのサイズが不正です（20MB以下）。' );
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $path ) ) {
            throw new RuntimeException( 'xlsx ファイルを開けません（形式が不正です）。' );
        }

        $prev = libxml_use_internal_errors( true );
        try {
            $sheet_path = self::first_sheet_path( $zip );
            $shared     = self::shared_strings( $zip );
            $sheet_xml  = $zip->getFromName( $sheet_path );
            if ( false === $sheet_xml || '' === $sheet_xml ) {
                throw new RuntimeException( 'xlsx のシートを読み込めません。' );
            }
            return self::parse_sheet( $sheet_xml, $shared );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors( $prev );
            $zip->close();
        }
    }

    /** 先頭シートの XML パス（xl/worksheets/sheet1.xml など）。 */
    private static function first_sheet_path( ZipArchive $zip ) {
        $default = 'xl/worksheets/sheet1.xml';

        $wb_xml = $zip->getFromName( 'xl/workbook.xml' );
        if ( false === $wb_xml ) return $default;
        $wb = simplexml_load_string( $wb_xml, 'SimpleXMLElement', LIBXML_NONET );
        if ( false === $wb || ! isset( $wb->sheets->sheet[0] ) ) return $default;

        $attrs = $wb->sheets->sheet[0]->attributes( self::NS_REL );
        $rid   = isset( $attrs['id'] ) ? (string) $attrs['id'] : '';
        if ( '' === $rid ) return $default;

        $rels_xml = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
        if ( false === $rels_xml ) return $default;
        $rels = simplexml_load_string( $rels_xml, 'SimpleXMLElement', LIBXML_NONET );
        if ( false === $rels ) return $default;

        foreach ( $rels->Relationship as $rel ) {
            if ( (string) $rel['Id'] !== $rid ) continue;
            $target = (string) $rel['Target'];
            if ( '' === $target ) break;
            return ( '/' === $target[0] ) ? ltrim( $target, '/' ) : 'xl/' . $target;
        }
        return $default;
    }

    /** sharedStrings.xml → 文字列の配列。 */
    private static function shared_strings( ZipArchive $zip ) {
        $xml = $zip->getFromName( 'xl/sharedStrings.xml' );
        if ( false === $xml || '' === $xml ) return [];
        $sx = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET );
        if ( false === $sx ) return [];

        $out = [];
        foreach ( $sx->si as $si ) {
            if ( isset( $si->t ) ) {
                $out[] = (string) $si->t;
                continue;
            }
            // リッチテキスト（r/t）を連結。ふりがな（rPh）は含めない。
            $text = '';
            foreach ( $si->r as $run ) {
                $text .= (string) $run->t;
            }
            $out[] = $text;
        }
        return $out;
    }

    /** シートXMLを行配列へ。 */
    private static function parse_sheet( $xml, array $shared ) {
        $reader = new XMLReader();
        if ( ! $reader->XML( $xml, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
            throw new RuntimeException( 'xlsx のシートを解析できません。' );
        }

        $dom  = new DOMDocument();
        $rows = [];
        $auto_row = 0;

        while ( $reader->read() ) {
            if ( XMLReader::ELEMENT !== $reader->nodeType || 'row' !== $reader->localName ) continue;

            $node = $reader->expand( $dom );
            if ( ! $node instanceof DOMElement ) continue;

            $auto_row++;
            $row_no = $node->hasAttribute( 'r' ) ? (int) $node->getAttribute( 'r' ) : $auto_row;
            if ( $row_no > self::MAX_ROWS ) {
                $reader->close();
                throw new RuntimeException( 'xlsx の行数が多すぎます（' . self::MAX_ROWS . '行まで）。' );
            }

            $cells = [];
            foreach ( $node->childNodes as $c ) {
                if ( ! $c instanceof DOMElement || 'c' !== $c->localName ) continue;
                $ref = $c->getAttribute( 'r' );
                if ( ! preg_match( '/^([A-Z]+)\d+$/', $ref, $m ) ) continue;
                $value = self::cell_value( $c, $shared );
                if ( null === $value || '' === $value ) continue;
                $cells[ $m[1] ] = $value;
            }
            $rows[ $row_no ] = $cells;
        }
        $reader->close();

        ksort( $rows );
        return $rows;
    }

    /** セル1つの値を取り出す。 */
    private static function cell_value( DOMElement $c, array $shared ) {
        $type = $c->getAttribute( 't' );

        if ( 'inlineStr' === $type ) {
            $text = '';
            foreach ( $c->getElementsByTagName( 't' ) as $t ) {
                $text .= $t->textContent;
            }
            return $text;
        }

        $v = null;
        foreach ( $c->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'v' === $child->localName ) {
                $v = $child->textContent;
                break;
            }
        }
        if ( null === $v || '' === $v ) return null;

        switch ( $type ) {
            case 's':
                $idx = (int) $v;
                return array_key_exists( $idx, $shared ) ? $shared[ $idx ] : null;
            case 'str':
                return $v;
            case 'b':
                return ( '1' === $v ) ? 1 : 0;
            case 'e':
                return null;
            default: // 'n' または未指定＝数値
                return is_numeric( $v ) ? $v + 0 : $v;
        }
    }
}
