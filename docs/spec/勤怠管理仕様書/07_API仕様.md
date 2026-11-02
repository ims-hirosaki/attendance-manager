---
doc_id: SPEC-07
title: API仕様（AJAX・admin-post エンドポイント）
audience: エンジニア
source_files: attendance-manager.php（フック登録）, includes/class-am-ajax.php, includes/class-am-kousoku-csv-importer.php, includes/class-am-summary-csv-exporter.php, assets/js/admin.js
source_version: 1.2.1
---

# 07 API仕様

## 1. 共通事項

### 1.1 AJAX（`wp_ajax_*`）【確定】

| 項目 | 内容 |
|---|---|
| URL | `admin-ajax.php`（JS では `amData.ajaxUrl`） |
| メソッド | **POST** |
| 対象 | **ログイン済み利用者のみ**（`wp_ajax_nopriv_*` は登録されていない） |
| 必須パラメータ | `action`（下表）、`nonce`（`amData.nonce` ＝ `wp_create_nonce('am_nonce')`） |
| nonce 検証 | `check_ajax_referer('am_nonce','nonce')`。失敗すると WordPress が中断（`-1` / 403） |
| 権限検証 | 各ハンドラ冒頭で `current_user_can()`。不足すると `wp_die(-1)`（レスポンス本文 `-1`） |
| 成功レスポンス | `{ "success": true, "data": … }` |
| 失敗レスポンス | `{ "success": false, "data": { "message": "…" } }` |
| 時間の形式 | `*_min` 系のうち `format_min` 済みの文字列は `H:MM`（例 `"10:05"`）。整数の `*_min` は分 |

`amData`（`wp_localize_script` で各管理ページに出力）:
```
amData = { defaultMonth: 'YYYY-MM'(今月), ajaxUrl, nonce, currentPage: <ページスラッグ> }
```

### 1.2 一覧

| ID | action | 区分 | 権限 | 概要 |
|---|---|---|---|---|
| API-01 | `am_chokyo_kintai_save` | 長距離 | edit_custom_plugins | 勤怠編集の保存 |
| API-02 | `am_chokyo_get_monthly_summary` | 長距離 | access_custom_plugins | 月間サマリ取得 |
| API-03 | `am_chokyo_get_daily_rows` | 長距離 | access_custom_plugins | 日次行の再取得 |
| API-04 | `am_chokyo_get_weekly_rows` | 長距離 | access_custom_plugins | 週計の再取得 |
| API-05 | `am_jiba_kintai_save` | 地場・事務 | edit_custom_plugins | 勤怠編集の保存 |
| API-06 | `am_jiba_get_monthly_summary` | 地場・事務 | access_custom_plugins | 月間サマリ取得 |
| API-07 | `am_jiba_get_daily_rows` | 地場・事務 | access_custom_plugins | 日次行の再取得 |
| API-08 | `am_jiba_get_weekly_rows` | 地場・事務 | access_custom_plugins | 週計の再取得 |
| API-09 | `am_holiday_get_rules` | 休日 | manage_custom_plugin_settings | 休日ルール一覧 |
| API-10 | `am_holiday_save_rule` | 休日 | manage_custom_plugin_settings | 休日ルール追加・更新 |
| API-11 | `am_holiday_delete_rule` | 休日 | manage_custom_plugin_settings | 休日ルール削除 |
| API-12 | `am_holiday_toggle_rule` | 休日 | manage_custom_plugin_settings | 有効/無効切替 |
| API-13 | `am_jobtype_get` | 種別 | manage_custom_plugin_settings | 職種一覧＋現在の区分 |
| API-14 | `am_jobtype_save` | 種別 | manage_custom_plugin_settings | 職種の区分を保存 |
| API-15 | `am_hosei_setting_save` | 設定 | manage_custom_plugin_settings | 補正時間の適用開始月を保存 |
| API-16 | `am_summary_list_get` | 集計一覧 | access_custom_plugins | 全社員の月間サマリ |
| API-17 | `am_summary_csv_export`（admin-post, GET） | 集計一覧 | access_custom_plugins | 集計CSVダウンロード |
| API-18 | `am_kousoku_csv_import`（admin-post, POST） | CSV取込 | manage_custom_plugin_settings | 拘束時間CSV取込 |
| API-19 | `am_kousoku_csv_template`（admin-post, GET） | CSV取込 | manage_custom_plugin_settings | 取込用ひな形ダウンロード |

## 2. 長距離

### API-01 `am_chokyo_kintai_save`
勤怠編集の保存（長距離）。

**リクエスト**
| パラメータ | 型 | 説明 |
|---|---|---|
| `employee_id` | int | 社員ID（`emp_master.id`）。`absint` |
| `rows[]` | 配列 | 表示月の全日分。各要素は下表 |

`rows[i]`:
| キー | 型 | 説明 |
|---|---|---|
| `date` | `YYYY-MM-DD` | 空ならスキップ |
| `kintai_type` | 文字列 | 勤怠種別（空=「―」） |
| `furikae_label` | 文字列 | 振替ラベル |
| `is_manual` | 0/1 | 1=手動設定 |
| `jiba` | 0/1 | 地場フラグ |
| `hosei_min` | int | 補正時間（負数は0へ）。省略時10 |
| `hayatai_min` | int | 早退・遅刻（分） |
| `note` | 文字列 | 備考 |

**処理**【確定】
1. 社員情報を取得し、`crew_code` が空なら失敗（「パラメータが不正です」）。
2. 送信日付の最小〜最大の期間で、対象社員の乗務員コード（履歴）を解決。
3. `START TRANSACTION`。各行について、`employee_id = X` **または** `(employee_id IS NULL AND crew_code IN (期間内コード))` の保存行を同日で検索。
   - 2件以上 → `ROLLBACK`、エラー「{日付} の保存済み勤怠が複数コードに存在するため、自動更新できません」。
   - 1件 → その行を UPDATE（旧コード行は `employee_id` と現行 `crew_code` に書き換わる）。0件 → INSERT。
   - 失敗 → `ROLLBACK`、エラー「保存に失敗しました：{DBエラー}」。
4. `COMMIT`。

**レスポンス**: `{ "saved": 保存件数 }`
**エラー**: `パラメータが不正です` / 上記の衝突・保存失敗

### API-02 `am_chokyo_get_monthly_summary`
**リクエスト**: `employee_id`, `year_month`（`YYYY-MM`）
**レスポンス（data）**:
```
attendance, absent, holiday_work,
unmatched_houtei_days, unmatched_houtei_labor_min, unmatched_houtei_labor_str,
paid_consumed, paid_remaining, paid_has_data,
labor_min, labor_str, hayatai_min, hayatai_str(0なら空文字), overtime_min, overtime_str
```
**エラー**: `パラメータが不正です` / `社員が見つかりません`
**副作用**: 内部で週集計を実行するため、**翌月繰越データが UPSERT されることがある**（BR-22）。

### API-03 `am_chokyo_get_daily_rows`
**リクエスト**: `employee_id`, `year_month`
**レスポンス（data）**: `{ rows: [...], alerts: [{type: 'error'|'warn', message}] }`

`rows[i]`:
| キー | 説明 |
|---|---|
| `date` | `YYYY-MM-DD` |
| `kintai_type` | 勤怠種別（自動判定＋手動反映後の値） |
| `houtei_kinmu` / `shitei_kinmu` | bool（バッジ用） |
| `start_time` / `end_time` | 文字列 |
| `kousoku_min` / `labor_min` / `drive_min` / `cargo_min` / `break_min` / `midnight_min` / `overtime_min` | `H:MM` 文字列 |
| `source_crew_code` | 実際にデータを取得した乗務員コード |
| `hosei_min` | int（有効日のみ値、無効日は 0） |
| `has_time` | bool（補正時間が入力可か） |

### API-04 `am_chokyo_get_weekly_rows`
**リクエスト**: `employee_id`, `year_month`
**レスポンス（data）**: 週オブジェクトの配列＋末尾に合計行（`label: "__total__"`）。
週オブジェクト: `label, is_prev_carry, is_carryover, carry_days, kousoku_min, labor_min, drive_min, cargo_min, break_min, midnight_min, day_overtime_min, week_overtime_min(繰越週はnull), confirmed_overtime, is_carryover_badge`（時間は `H:MM`）。

## 3. 地場・事務

### API-05 `am_jiba_kintai_save`
**リクエスト**: `employee_code`（文字列）、`rows[]`（API-01 と同形だが `jiba` の代わりに `chokyo`）
**処理**【確定】: 各行 `INSERT ... ON DUPLICATE KEY UPDATE`（キー: `employee_code + work_date`）。`hosei_min` は **`chokyo=1` の行のみ数値**、`chokyo=0` は `NULL`。**トランザクション無し**。
**レスポンス**: `{ saved }` ／ エラー: `パラメータが不正です` / `保存に失敗しました：{DBエラー}`（失敗時点で処理中断。それ以前の行は保存済み）

### API-06〜08
API-02〜04 の地場・事務版。パラメータは `employee_code`, `year_month`。
- API-07（日次）のレスポンスには **`kintai_type` と `source_crew_code` が含まれない**（JS はそのため勤怠種別セレクトを更新しない）。

## 4. 休日マスタ

### API-09 `am_holiday_get_rules`
**レスポンス**: ルール配列。各要素 `id, affiliation_id, day_of_week, week_numbers, is_active, updated_at, affiliation_name`（所属が無いと「（未設定）」）。並び: 所属の並び順→曜日。

### API-10 `am_holiday_save_rule`
**リクエスト**: `id`（0=新規）, `affiliation_id`, `day_of_week`, `week_numbers`（文字列。例 `2,4`）
**検証**: `affiliation_id` が 0、または `week_numbers` が空 → `所属と対象週は必須です`。
**挙動**: `id > 0` なら UPDATE、そうでなければ INSERT。**保存時は常に `is_active = 1`**（無効だったルールを編集すると有効に戻る）。DBエラー（UNIQUE違反など）は `last_error` をメッセージに返す。
**未検証**: `day_of_week` の範囲、`week_numbers` の書式は**検証しない**（数字以外は 0 扱いで評価時に一致しない）。
**レスポンス**: `{ id }`

### API-11 `am_holiday_delete_rule`: `id` → 削除。常に success。
### API-12 `am_holiday_toggle_rule`: `id`, `is_active`（0/1）→ 更新。常に success。

## 5. 種別管理・設定

### API-13 `am_jobtype_get`
**レスポンス**: `[ { name: 職種名, category: 'chokyo'|'jiba'|'' } ]`（`emp_get_job_types()` が無いと空配列）。

### API-14 `am_jobtype_save`
**リクエスト**: `job_type_name`, `category`（`chokyo`|`jiba` のみ）
**エラー**: `職種名が空です` / `区分が不正です` / `保存に失敗しました`
（解除用のAPIは無い）

### API-15 `am_hosei_setting_save`
**リクエスト**: `start_month`（`YYYY-MM`、正規表現で検証）
**エラー**: `適用開始月は YYYY-MM 形式で指定してください`
**レスポンス**: `{ start_month }`

## 6. 集計一覧

### API-16 `am_summary_list_get`
**リクエスト**: `year_month`
**処理**: 長距離（`employee_id` のある社員のみ）→地場・事務の順に、各社員の月次計算を実行（社員数が多いと時間がかかる）。
**レスポンス（data）**: 配列。各要素:
```
employee_code, name, category('chokyo'|'jiba'), special_badge('お盆出勤あり'|'お正月出勤あり'|''),
attendance, absent, holiday_work, unmatched_houtei_days, unmatched_houtei_labor_min, unmatched_houtei_labor_str,
paid_consumed, paid_remaining, paid_has_data,
labor_min, hayatai_min, overtime_min, labor_str, hayatai_str, overtime_str
```
**副作用**: 全社員分の翌月繰越データが UPSERT される（BR-22）。

### API-17 集計CSV（`admin-post.php?action=am_summary_csv_export`）
| 項目 | 内容 |
|---|---|
| メソッド | GET |
| パラメータ | `year_month`（`YYYY-MM`、`/\A\d{4}-(0[1-9]|1[0-2])\z/` で検証）、`_wpnonce`（アクション `am_summary_csv_export`） |
| 権限 | access_custom_plugins |
| エラー | 権限なし 403 / 月が不正 400（`対象月が不正です。`） |
| レスポンス | `Content-Type: text/csv; charset=UTF-8`、ファイル名 `attendance-summary-YYYY-MM.csv`、先頭にUTF-8 BOM |
| 仕様 | 08 参照 |

## 7. 拘束時間CSV

### API-18 取込（`admin-post.php`, POST）
| 項目 | 内容 |
|---|---|
| フォーム項目 | `action=am_kousoku_csv_import`、`am_kousoku_csv_nonce`（アクション `am_kousoku_csv_import`）、`kousoku_csv`（ファイル）、`overwrite_existing`（任意。1=上書き） |
| 権限 | manage_custom_plugin_settings |
| 結果の受け渡し | 結果を transient `am_kousoku_csv_import_{ユーザーID}`（5分）に保存し、`admin.php?page=attendance-manager-kousoku-import&am_imported=1` へリダイレクト。ページ表示時に1度だけ読み出して削除 |
| 仕様 | 08 参照 |

### API-19 ひな形（`admin-post.php?action=am_kousoku_csv_template`, GET）
`_wpnonce`（アクション `am_kousoku_csv_template`）。`kousoku_import_template.csv`（UTF-8 BOM付き、日本語見出し24列のみ・データ行なし）。

## 8. 画面（GET）のURLパラメータ

| 画面 | URL | パラメータ |
|---|---|---|
| 長距離 | `admin.php?page=attendance-manager` | `am_employee_id`（社員ID）、`am_month`（YYYY-MM、既定=今月）、旧 `am_crew`（乗務員コード。互換用） |
| 地場・事務 | `admin.php?page=attendance-manager-jiba` | `am_emp`（社員コード）、`am_month` |
| 集計一覧 | `admin.php?page=attendance-manager-summary` | `am_month` |
| 拘束CSV取込 | `admin.php?page=attendance-manager-kousoku-import` | `am_imported`（結果表示用） |
| 設定 | `admin.php?page=attendance-manager-settings` | なし |

- 長距離画面で `am_employee_id` に該当社員が無い場合は HTTP 404「社員が見つかりません。」。
- 権限が無い場合は HTTP 403「権限がありません。」。

## 9. 入力サニタイズの方針【確定】
- 文字列は `sanitize_text_field( wp_unslash(...) )`、IDは `absint` / `(int)`、SQLは `$wpdb->prepare()`。
- 動的な `IN (...)` はコード数分のプレースホルダーを生成して `prepare` に渡す（値の直接連結はしない）。
- 出力は PHP テンプレートでは `esc_html` / `esc_attr`、JS では `$('<span>').text(x).html()` でエスケープ。
- 例外: `holiday_save_rule` の一部、`jiba_kintai_save` の `hosei_min`（`$wpdb->prepare('%d', …)` で整数化した文字列をSQLへ埋め込み）。
