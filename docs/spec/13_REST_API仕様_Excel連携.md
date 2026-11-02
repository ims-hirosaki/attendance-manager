---
doc_id: SPEC-13
title: REST API仕様（Excel / Power Query 連携）
audience: エンジニア、Excel 担当者、管理者
source_files: includes/class-am-rest-api.php, attendance-manager.php（読込・初期化）, includes/class-am-ajax.php・includes/class-am-kousoku-csv-importer.php（キャッシュ無効化の呼び出し）
source_version: 1.2.1（PR #27・#28 反映後）
related: SPEC-07（AJAX / admin-post）, SPEC-08（CSV）, docs/api-guidelines.md（社内ツール共通の API 発行ルール）
---

# 13 REST API仕様（Excel / Power Query 連携）

## 1. 目的と位置づけ【確定】

個人別の月次勤怠表を Excel で作成し、月初に前月分の実績を本人に確認してもらうため、システムが管理する勤怠データを **Excel（Power Query）から自動取得**するための REST API。

- 07（AJAX / admin-post）は**管理画面からの利用**が前提（ログイン Cookie ＋ nonce）。本章の REST API は **Excel など外部ツールからの利用**が前提（アプリケーションパスワードによる Basic 認証）。
- データの計算は管理画面と**同じ計算クラス**（`AM_Compute_Chokyo` / `AM_Compute_Jiba`）を使う。画面に表示される値と同じ「計算後の値」が返る。
- **取得専用（GET のみ）**。勤怠データの更新・削除はできない。
- 社内ツール共通の発行ルールは `docs/api-guidelines.md` を参照。

## 2. 共通事項

### 2.1 エンドポイント一覧【確定】

| ID | メソッド | URL | 概要 | 状態 |
|---|---|---|---|---|
| RAPI-01 | GET | `/wp-json/attendance-manager/v1/employees` | 社員一覧（軽量） | 運用中 |
| RAPI-02 | GET | `/wp-json/attendance-manager/v1/timesheet` | 1 人 × 1 か月の日次・週次・月次サマリ | 運用中 |
| RAPI-03 | GET | `/wp-json/attendance-manager/v1/timesheet-all` | 全従業員の月次勤怠を一括取得 | **試験的** |

- WordPress がサブディレクトリにある場合は `/サブディレクトリ/wp-json/...`。パーマリンクが「基本」の場合は `?rest_route=/attendance-manager/v1/...` の形になる。
- HTTPS 必須（アプリケーションパスワードは HTTPS でないと使えない設定が標準）。

### 2.2 認証・権限【確定】

| 項目 | 内容 |
|---|---|
| 認証方式 | WordPress の**アプリケーションパスワード**（Basic 認証。ユーザー名＋アプリケーションパスワード） |
| 必要な権限 | `am_export_data` **または** `access_custom_plugins`（どちらか一方でよい） |
| ロール | 「**API取得**」（スラッグ `api_reader`）。他のツールと共有する想定 |
| 未ログイン | 401（コード `am_forbidden`） |
| 権限不足 | 403（コード `am_forbidden`） |

- ロール「API取得」は、`init` フック（`AM_REST_API::ensure_role()`）で確認する。**無ければ作成**し、**既にあれば作り直さず**、ケイパビリティ `am_export_data` だけを追加する。FTP デプロイでは有効化フックが走らないため、`init` で確認する方式にしている。
- 「API取得」ロールが持つのは `read` ＋ `am_export_data` のみ。勤怠の保存・設定変更の権限（`edit_custom_plugins` / `manage_custom_plugin_settings`）は持たない。
- `access_custom_plugins` を持つ既存の利用者（管理画面の利用者）も、そのまま API を使える。
- ユーザー作成には**メールアドレスが必須**（実在しなくてもよいが、WordPress 全体で重複不可）。アプリケーションパスワードはユーザーのプロフィール画面で発行する（発行直後の 1 回しか表示されない）。

### 2.3 エラーレスポンス【確定】

WordPress REST API 標準の形式 `{ "code": "…", "message": "…", "data": { "status": 404 } }`。

| HTTP | code | 条件 |
|---|---|---|
| 400 | `rest_invalid_param` ほか | パラメータの型・形式・列挙値の不正（`year_month` が `YYYY-MM` でない、など） |
| 401 | `am_forbidden` | 未認証 |
| 403 | `am_forbidden` | 権限不足 |
| 404 | `am_not_found` | `employee_code` に該当する社員がいない（RAPI-02） |
| 422 | `am_no_employee_id` | 長距離の社員で `employee_id` を特定できない |

- RAPI-03 では、**社員単位のエラーは HTTP エラーにせず**、レスポンスの `errors` 配列に積んで処理を続行する（§3.3）。

### 2.4 共通のデータ規約【確定】

- **計算後の値**を返す。調整・補正がある項目は、合算後の値とは別に、調整分だけの項目も返す（例: `labor_min` と `hosei_min`）。
- 時間は**分単位の整数**（`*_min`）。さらに、`*_min` が数値のときは、同じ階層に **`H:MM` 表記の文字列 `*_min_hm`** を自動で併記する（例: `labor_min: 605` → `labor_min_hm: "10:05"`）。Excel で計算するときは `*_min` を使う。
- 項目名は英語のスネークケース。日本語のラベルは含めない。
- 対象月 `year_month` は `YYYY-MM` 形式（正規表現 `^\d{4}-(0[1-9]|1[0-2])$` で検証）。

### 2.5 対象となる社員【確定】

- 区分（`category`）は、`wp_am_job_type_mapping` の職種マッピングで決まる（06 参照）。マッピング未設定のときは、長距離は拘束時間ログベース、地場・事務は打刻データベースの従来ロジックにフォールバックする（`AM_DB::get_employees_by_category`）。
- **`employee_code` が空、または `―`（未登録）の社員は、一覧にも出ず、取得もできない。**
- 長距離は、在籍（`is_active = 1`）かつ乗務員コード（`crew_code`）が登録済みの社員が対象。地場・事務は在籍中の社員が対象。
- 識別子は、利用者向けに全区分共通で **`employee_code`（社員番号）** を使う。長距離の計算は不変の `employee_id`（社員マスタの ID）を使うため、API 内部で `employee_code` → `employee_id` に変換している（呼び出し側は意識しなくてよい）。

## 3. エンドポイント詳細

### 3.1 RAPI-01 社員一覧 `GET /employees`

| パラメータ | 型 | 必須 | 説明 |
|---|---|---|---|
| `category` | string | 任意 | `chokyo` または `jiba`。省略時は両方（長距離 → 地場・事務の順） |

レスポンス: 配列。

```json
[
  { "employee_code": "101", "name": "山田 太郎", "category": "chokyo",
    "job_type_name": "長距離", "affiliation_name": "本社" }
]
```

### 3.2 RAPI-02 個人別月次勤怠 `GET /timesheet`

| パラメータ | 型 | 必須 | 説明 |
|---|---|---|---|
| `employee_code` | string | 必須 | 社員番号 |
| `year_month` | string | 必須 | `YYYY-MM` |
| `refresh` | boolean | 任意 | `1` でキャッシュを使わず再計算（既定 false） |

レスポンス: オブジェクト。

| 項目 | 内容 |
|---|---|
| `employee_code` / `name` / `category` / `job_type_name` | 社員情報 |
| `year_month` | 対象月 |
| `daily` | 日次明細の配列（月の日数分。§4.1） |
| `weekly` | 週次集計（§4.2） |
| `summary` | 月次サマリ（§4.3） |
| `cached` | キャッシュから返したとき true |
| `generated_at` | 生成日時（ISO 8601、サイトのタイムゾーン） |

- 処理は 1 人分の計算のみで、管理画面の月次表示と同程度の負荷。
- サーバー側の実行時間上限として `set_time_limit(120)` を設定している（サーバー側の制限が優先される場合がある）。

### 3.3 RAPI-03 全従業員の一括取得（試験的） `GET /timesheet-all`

全員分の計算を行うため重い。試験段階の API であり、運用で使う前に `elapsed_sec` で実際の所要時間を確認すること。

| パラメータ | 型 | 必須 | 説明 |
|---|---|---|---|
| `year_month` | string | 必須 | `YYYY-MM` |
| `category` | string | 任意 | `chokyo` / `jiba`。省略時は両方 |
| `offset` | integer | 任意 | 何人目から返すか（既定 0）。分割取得用 |
| `limit` | integer | 任意 | 何人分返すか。`0` または省略で `offset` 以降の全員 |
| `refresh` | boolean | 任意 | `1` でキャッシュを使わず再計算 |

レスポンス: オブジェクト。

| 項目 | 内容 |
|---|---|
| `year_month` | 対象月 |
| `total_employees` | 対象の総人数（`offset` / `limit` 適用前） |
| `offset` | 指定した `offset` |
| `returned` | 今回、実際に返した人数 |
| `elapsed_sec` | 処理にかかった秒数 |
| `errors` | 失敗した社員の配列 `{ employee_code, name, message }`。空配列なら全員成功 |
| `employees` | 社員ごとの勤怠（中身は RAPI-02 のレスポンスと同じ構造） |

- 並び順は、長距離 → 地場・事務の順（それぞれ社員番号の昇順）。
- 1 人の計算が失敗（例外を含む）しても全体は止めず、その社員を `errors` に積んで続行する。
- タイムアウトする場合は、`limit` を使って分割して取得する（例: `limit=10&offset=0`、`limit=10&offset=10`）。
- サーバー側の実行時間上限として `set_time_limit(300)` と、メモリ上限の引き上げ（`wp_raise_memory_limit('admin')`）を設定している。
- キャッシュは RAPI-02 と**社員単位で共有**される。

## 4. データ項目

### 4.1 日次明細 `daily` の各行【確定】

計算処理（`get_monthly_rows`）が返す行データに、`*_min_hm` を併記したもの。代表的な項目:

| 項目 | 内容 |
|---|---|
| `date` / `dow` / `dow_num` | 日付、曜日、曜日番号 |
| `is_sun` / `is_sat` / `is_shitei_holiday` | 日曜、土曜、所定休日 |
| `has_data` | 元データ（打刻・運行記録）があるか |
| `default_kintai` | 勤怠種別（出勤・法定休・所定休・有給 など） |
| `furikae_label` / `is_manual` | 振替休の表示、手動設定かどうか |
| `start_time` / `end_time` | 始業・終業時刻 |
| `kousoku_min` / `labor_min` | 拘束時間、労働時間（分、計算後） |
| `drive_min` / `cargo_min` | 運転時間、積卸時間（分。地場・事務は null） |
| `break_calc_min` / `overtime_min` / `midnight_min` | 休憩、日残業、深夜時間（分） |
| `hayatai_min` | 早退・遅刻（分） |
| `hosei_min` | **補正時間**（点呼など。分）。調整分だけを別項目として出力 |
| `note` | 備考 |
| `jiba`（長距離の社員の行） / `chokyo`（地場・事務の社員の行） | 下記の注意を参照 |

- 同じ列に、`labor_min` などの値が null になる日がある（データなし、休日など）。`*_min_hm` は、`*_min` が数値のときだけ付く。
- 補正時間（`hosei_min`）は、補正の適用開始月（設定画面で指定）以降に有効になる。適用開始月より前の月は 0 になる。

**日ごとのフラグの非対称性【確定】**

| 社員の区分 | 行に入るフラグ | 意味 |
|---|---|---|
| 長距離 | `jiba` のみ | その日に地場の業務をしたか（true/false） |
| 地場・事務 | `chokyo` のみ | その日に長距離の業務をしたか（true/false） |

- 反対側のフラグ（長距離の行の `chokyo`、地場・事務の行の `jiba`）は**行に含まれない**。
- 地場・事務の `chokyo` は、打刻データの `long_distance` が真、または保存済みの手入力が真のどちらかなら true。true の日は、始業・終業・労働時間などが長距離データ（拘束時間管理表）から計算された値に切り替わる。
- **社員の区分の判定には、日次のフラグではなく `category` を使う。**
- Power Query は、先頭の行を見て展開できる列の一覧を作る。先頭が長距離の社員だと `chokyo` が一覧に出ないことがある。その場合は、展開の列名に `"chokyo"` と `"jiba"` を手動で指定する（存在しない行は null になる）。

### 4.2 週次集計 `weekly`【要確認】

`get_weekly_summary` の戻り値をそのまま返す（週ごとの集計と、月全体の合計 `total`。`total` には `midnight_min`・`cargo_min` などが入る）。月をまたぐ週は翌月へ繰越される。構造の細部は 05（業務ロジック仕様）を参照。実際のレスポンスで確認してから Excel を組むこと。

### 4.3 月次サマリ `summary`【確定】

`get_monthly_summary` の戻り値（空の月は `labor_min_incl_tenko` のみ）。主な項目:

| 項目 | 内容 |
|---|---|
| `attendance` / `absent` / `holiday_work` | 出勤日数、欠勤、休日出勤 |
| `unmatched_houtei_days` / `unmatched_houtei_labor_min` | 振替のない法定休日出勤の日数、労働時間 |
| `paid_consumed` / `paid_remaining` / `paid_has_data` | 有給の消化・残り、有給データの有無 |
| `labor_min` / `hayatai_min` / `overtime_min` | 総労働、早退遅刻、確定残業（分） |
| `labor_min_incl_tenko` | **点呼時間を含む総労働時間（API 側で追加）** |

`labor_min_incl_tenko` の定義（集計 CSV の「総労働時間（点呼時間含む）」と同じ）:
- 補正時間の適用開始月**以降**: `labor_min` そのまま（週集計に各日の補正時間が既に含まれるため）。
- 適用開始月**より前**で、職種名が「長距離」または「郵便」: `labor_min` ＋ 10 分 × `attendance`。
- それ以外: `labor_min` そのまま。

## 5. キャッシュ【確定】

| 項目 | 内容 |
|---|---|
| 対象 | RAPI-02 / RAPI-03 の社員ごとの結果（transient） |
| 対象月 | **前月以前**のみ。当月・未来月はキャッシュしない |
| 有効期間 | 1 日（`DAY_IN_SECONDS`） |
| キー | `am_api_ts_` ＋ `md5( バージョン番号 | employee_code | year_month )` |
| 無効化 | オプション `am_api_cache_version` を更新し、全キャッシュのキーを無効にする（`AM_REST_API::invalidate_cache()`） |
| 強制再計算 | `refresh=1` |
| レスポンスの印 | キャッシュから返した場合 `cached: true` |

キャッシュを無効化する処理（呼び出し箇所）:

- 長距離・地場の勤怠保存（`chokyo_kintai_save` / `jiba_kintai_save`）
- 補正時間の設定保存（`hosei_setting_save`）
- 休日ルールの保存・削除・有効切替（`holiday_save_rule` / `holiday_delete_rule` / `holiday_toggle_rule`）
- 職種マッピングの保存（`jobtype_save`）
- 拘束時間 CSV の取込（`AM_Kousoku_CSV_Importer::handle_import`）

**注意**: 外部テーブル（`wp_kousoku_log`、`wp_tenrec_daily`、`wp_mat_attendance_daily` など）は、このプラグイン以外から更新されることがあり、更新を検知できない。データ修正の直後に最新の値が必要なときは `refresh=1` を使う。

## 6. 運用手順【確定】

1. Excel 担当者の WordPress ユーザーを作成し、ロール「API取得」を付与する（ユーザーごとに作る。共有アカウントにしない）。
2. そのユーザーのプロフィール画面で、アプリケーションパスワードを発行して控える。ツールや用途ごとに名前を分けて発行しておくと、片方だけ失効できる。
3. 動作確認:

```bash
curl -u 'USER:APP_PASSWORD' 'https://ドメイン/wp-json/attendance-manager/v1/employees'
```

4. Power Query: データ → データの取得 → その他のデータソースから → Web から。認証は **「基本」**。JSON の「List」→「テーブルへ」→ 列を展開する。社員コードと対象月は、セルから値を渡す関数クエリにして切り替える（`Web.Contents` の `Query = [ employee_code = …, year_month = … ]`）。
5. 退職・異動時は、そのユーザーのアプリケーションパスワードを失効する。

## 7. 既知の注意点・要確認事項

| # | 内容 | 確度 |
|---|---|---|
| 1 | XSERVER で Authorization ヘッダーが PHP に渡らないと、認証が 401 になる。`.htaccess` に `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` の追記が必要になる場合がある | 【推定】（環境依存。本番で要確認） |
| 2 | RAPI-03 は全員分の計算を 1 リクエストで行う。人数が増えると、サーバー側（PHP / XSERVER）のタイムアウトに掛かる可能性がある。`limit` / `offset` で分割できる | 【確定】（構造上）／実測は【要確認】 |
| 3 | 週次集計（`_build_weekly_static`）は、**月末をまたぐ週がある月に限り、翌月の繰越値を保存する**（`save_chokyo_carryover` / `save_jiba_carryover`）。そのため GET の API でも、該当する月を取得すると繰越データが書き込まれる（管理画面の表示と同じ挙動） | 【確定】（コード上）／運用上の影響は【要確認】 |
| 4 | `employee_code` が空または `―` の社員は、一覧にも出ず、取得もできない | 【確定】 |
| 5 | 長距離と地場・事務で、日次行の項目が一部異なる（§4.1 のフラグの非対称性など）。Power Query では、先頭行から列の一覧が決まるため、展開時に列名を明示する | 【確定】 |
| 6 | 他ツールの API が、同じロール「API取得」と専用ケイパビリティで作られているかは、本書の範囲外 | 【要確認】 |
| 7 | 動作確認は、PHP の構文チェックと、利用者側での Excel からの取得で行っている。自動テストはない | 【確定】 |

## 8. 変更履歴

| 日付 | 内容 |
|---|---|
| 2026-10 | RAPI-01・RAPI-02（社員一覧・個人別月次勤怠）、ロール「API取得」、キャッシュを追加（PR #27） |
| 2026-10 | RAPI-03（全従業員の一括取得、試験的）を追加（PR #28） |
