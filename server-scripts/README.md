# 最新データ取得（拘束時間）— 仕様と反映手順

勤怠管理画面の右上にある **「最新データを取得」** ボタンで、拘束時間管理表（`wp_kousoku_log`）を、
毎朝の自動取込を待たずに最新にする機能です。

このフォルダのファイルは **取得サーバー用**（WordPress では使いません。反映手順は §4）。
現在の `deploy.yml` はリポジトリ全体を FTP で送る設定のため、このフォルダもプラグインのフォルダに置かれますが、
中身はソースコードのみ（認証情報なし）で、WordPress から実行されることはありません。
気になる場合は、`deploy.yml` の FTP-Deploy-Action に `exclude: ['**/server-scripts/**']` を追加してください。

## 1. 仕組み

```
[画面] 右上「最新データを取得」→ 月（当月／前月）を選んで「取得を依頼」
   ↓ WordPress（class-am-fetch-request.php）
   ↓   warehouse/requests/kreq_YYYYMM_<ID>.json を作成 ／ 履歴に「取得待ち」／ 5分Cron を予約
   ↓ 取得サーバー（5分ごと chikyu_request_poll.py）
   ↓   依頼を検出 → 地球号から該当月の拘束時間管理表CSVを取得（約1〜3分）
   ↓   xlsx に変換（日次の convert_csv.py と同じ変換）
   ↓   FTP で warehouse/kousoku_kreq_<ID>.xlsx を転送（.part で送って改名）
   ↓ WordPress：xlsx の到着を検知（画面表示中は15秒ごと／WP-Cron は5分ごと）
   ↓   wp_kousoku_log へ取り込み（class-am-kousoku-xlsx-importer.php）→ キャッシュ破棄
   ↓   履歴が「取り込み完了」に。xlsx と依頼ファイルは削除
[画面] 「ページを更新して反映」
```

目安：依頼から **5〜10分**（ポーラーの待ち最大5分 ＋ 取得・変換 1〜3分）。

## 2. 制約・注意

- **毎日 1:55〜3:30 は取得サーバーが動きません**（日次 2:00・月次 3:00 と重ならないため）。この間の依頼は 3:30 以降に処理されます。
- 取得できるのは **当月と前月の1か月分**（1回の依頼＝1か月）。
- ポーラーが見るのは **本番の warehouse のみ**。テストサイトの依頼は処理されません。
- 取得サーバー側は、失敗すると5分ごとに再試行し、**3回で打ち切り**。WordPress 側は **3時間でタイムアウト**。
- 取り込みは `import_kousoku.php`（毎朝 6:00）と同じ仕様の UPSERT（乗務員コード＋日付）。何度実行しても同じ結果になる。
  - 始業時刻が空の行（休日）はスキップ。
  - `wp_emp_master` に無い乗務員コードの行はスキップ（結果欄に件数とコードを表示）。
- 日次の `拘束時間管理表.xlsx` は上書きしない（依頼ごとに別名 `kousoku_kreq_<ID>.xlsx`）。
- ボタンを表示・実行できるのは、設定画面と同じ権限（`manage_custom_plugin_settings`）の人のみ。
- 画面に出るのは 長距離 / 地場・事務 / 集計一覧 の3ページ。

## 3. WordPress 側の設定

取得用フォルダ（取得サーバーが xlsx を置く場所）の既定値は
`/home/xs969605/xs969605.xsrv.jp/warehouse/`（`import_kousoku.php` が読む場所と同じ）。
違う場合は `wp-config.php` に定数で指定します。

```php
define( 'AM_WAREHOUSE_DIR', '/実際の/warehouse/のパス/' );
```

（オプション `am_warehouse_dir` でも指定可。優先順位：定数 ＞ オプション ＞ 既定値）

フォルダが無い・書き込めない場合、依頼時に画面へエラーを表示します。

## 4. 取得サーバーへの反映手順（root 権限が必要）

対象：`/home/guser/chikyu/chikyu_request_poll.py`（`root` 所有のため、管理者が実施）

1. 現行のバックアップ：
   ```bash
   cp -p /home/guser/chikyu/chikyu_request_poll.py /home/guser/chikyu/chikyu_request_poll.py.bak_before_kousoku
   ```
2. このフォルダの `chikyu_request_poll.py` で置き換える（所有者・権限は現行と同じに）。
3. 構文確認：
   ```bash
   python3 -m py_compile /home/guser/chikyu/chikyu_request_poll.py
   ```
4. cron は変更不要（`*/5 * * * *` のまま。`.env` 読み込み済み）。
5. 動作確認：本番の画面から「当月」で依頼 → 数分後に履歴が「取り込み完了」になること、
   `/home/guser/chikyu/poll.log` に `取得依頼(拘束時間)` の記録が出ることを確認。

### 取得サーバーに必要なもの（現状すべてある想定）
- `chikyu_auto_download.py`（`run(日付, 保存先)`）と `convert_csv.py`（`read_header_year_month` / `load_processed` / `write_excel`）が、
  `chikyu_request_poll.py` と同じフォルダにあること。
- 環境変数：`.env` の `CHIKYU_*`（地球号ログイン）と `XSERVER_FTP_*` / `XSERVER_DEST_DIR`。
- 上記2ファイルの関数名・引数を変更した場合は、このスクリプトも合わせて直す。

## 5. 元に戻す方法

- 取得サーバー：手順 4-1 のバックアップを戻す（既存の一般データ取得は元の動作に戻る）。
- WordPress：この機能のコミットを戻す。依頼ファイル `kreq_*.json` が残っていても、
  元のポーラーは名前が合わないものを無視するので影響はない。

## 6. 検証済みの内容

- xlsx の読み込み・変換・UPSERT 文の生成（NULL・引用符・翌日終業・休日行・未登録コード・壊れたファイル）
- 依頼 → 到着検知 → 取り込み → 履歴更新、二重依頼・権限・タイムアウト・書きかけ回避
- ポーラー：当月/前月・同月の複数依頼の統合・範囲外の無視・失敗3回で打ち切り・年月不一致の拒否・
  既存の一般データ依頼が従来どおり動くこと（FTP・地球号は模擬）

**未確認（本番でのみ確認できる）**：実際の地球号からの取得と FTP 転送、
WordPress から warehouse フォルダへのアクセス可否（`open_basedir` など）。
