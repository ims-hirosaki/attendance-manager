# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

WordPress admin plugin for employee attendance management (勤怠管理) for 有限会社たんぽぽ運送. Manages three employee categories: 長距離 (Chokyo — long-distance drivers), 地場・事務 (Jiba — local/office staff), and common settings (holidays, job type mappings).

No build tooling, no test framework. Pure PHP + jQuery. Changes are deployed directly via GitHub Actions FTP to XSERVER on push to `main`.

## Deployment

Pushes to `main` auto-deploy to production via `.github/workflows/deploy.yml` (FTP to XSERVER). Staging and personal deploy workflows also exist. There is no local dev server setup — development is done against a live WordPress install.

## Architecture

### Class responsibilities

| File | Role |
|------|------|
| `attendance-manager.php` | Plugin bootstrap — registers hooks, enqueues assets, creates DB tables on activation |
| `includes/class-am-db.php` | All WPDB queries. Single source of truth for data access |
| `includes/class-am-ajax.php` | `wp_ajax_*` handlers — validates input, calls DB and compute classes, returns JSON |
| `includes/class-am-compute-chokyo.php` | Business logic for Chokyo (長距離) time calculations |
| `includes/class-am-compute-jiba.php` | Business logic for Jiba (地場・事務) time calculations |
| `includes/class-am-fetch-request.php` | 「最新データを取得」ボタン（長距離・地場事務・集計一覧のページ右上）。取得依頼の作成・到着検知・履歴管理 |
| `includes/class-am-kousoku-xlsx-importer.php` / `class-am-xlsx-reader.php` | 拘束時間管理表 xlsx → `wp_kousoku_log` の取込（サーバー側 `import_kousoku.php` と同じ仕様の移植） |
| `server-scripts/` | 取得サーバー（WordPress の外）用のスクリプトと反映手順。依頼を受けて地球号から取得する `chikyu_request_poll.py` |
| `templates/` | PHP view files rendered by AJAX handlers or page callbacks |
| `assets/js/admin.js` | jQuery-based frontend — all AJAX calls, DOM manipulation, table rendering |

### Data flow

1. User interacts with admin page → `admin.js` fires AJAX request
2. `class-am-ajax.php` handler receives it → calls `class-am-db.php` for raw data
3. Compute class (`chokyo` or `jiba`) processes the data
4. Result is returned as JSON or rendered via a template partial

### Database tables (plugin-owned)

- `wp_am_chokyo_kintai_log` / `wp_am_jiba_kintai_log` — attendance logs per employee type
- `wp_am_chokyo_carryover` / `wp_am_jiba_carryover` — monthly carryover hours
- `wp_am_holiday_rules` — holiday scheduling rules
- `wp_am_job_type_mapping` — job type to category mapping

### External tables read (not owned by this plugin)

- `wp_kousoku_log` — driving/constraint time logs (Chokyo source)
- `wp_tenrec_daily` — daily travel records with driver entries (Chokyo source)
- `wp_mat_attendance_daily` — clock-in/out records (Jiba source)
- `wp_emp_master` — employee master
- `wp_mst_affiliation` — department/affiliation master

### AJAX action naming

Actions follow `am_{type}_{operation}` pattern, e.g. `am_chokyo_kintai_save`, `am_jiba_get_weekly_rows`, `am_holiday_save_rule`.

## Key domain concepts

- **Chokyo (長距離)**: Long-distance drivers. Data sourced from `wp_tenrec_daily` and `wp_kousoku_log`. Has complex overtime/rest calculations.
- **Jiba (地場・事務)**: Local drivers and office staff. Data sourced from `wp_mat_attendance_daily`. Simpler time calculations.
- **Carryover (繰越)**: Hours carried over month-to-month, stored separately per employee type.
- **Has_data flag**: Controls whether a row shows saved data vs. auto-calculated defaults. For both types, auto-calculation always runs even when saved data exists (the saved data is the override).
