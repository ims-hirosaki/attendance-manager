#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
WordPress（ダッシュボード）からの取得依頼を確認し、地球号のデータを取得して Xserver へ FTP 転送する。

cron で5分ごとに実行する想定。依頼は2種類ある。

  [積降時間管理 kintai-manager] 一般データ（月指定）
    依頼ファイル: <XSERVER_DEST_DIR>/requests/req_YYYYMM_<id>.json
    転送ファイル: 一般データ_<令和年2桁><月2桁>_<取得日>.zip

  [勤怠管理 attendance-manager] 拘束時間管理表（当月または前月の1か月分）  ← 今回追加
    依頼ファイル: <XSERVER_DEST_DIR>/requests/kreq_YYYYMM_<id>.json
    転送ファイル: kousoku_kreq_<id>.xlsx（依頼ごとの名前。日次の 拘束時間管理表.xlsx は上書きしない）

どちらも .part で送ってから改名し、取り込み側が書きかけを読まないようにする。
拘束時間管理表は、chikyu_auto_download.run() で1か月分のCSVを取得し、
convert_csv.load_processed() / write_excel() で xlsx にする（日次の run.sh と同じ変換）。
作業用フォルダは専用の一時フォルダを使い、run.sh が行う csv/ の掃除とは衝突しない。
"""
import os
import re
import sys
import json
import time
import fcntl
import shutil
import logging
import tempfile
import subprocess
from datetime import datetime, date
from ftplib import FTP, error_perm
from pathlib import Path

BASE_DIR   = Path(__file__).resolve().parent
STATE_FILE = Path(os.environ.get("CHIKYU_POLL_STATE", BASE_DIR / "poll_state.json"))
LOCK_FILE  = "/tmp/chikyu_poll.lock"
MAX_ATTEMPTS = 3

# 日次(2時台)・月次(3時台)の処理と重ならないよう、この時間帯は動かない
SKIP_FROM = (1, 55)
SKIP_TO   = (3, 30)

# 他の取得処理が動いている間は動かない（地球号の二重ログイン防止）
BUSY_PATTERN = (r"python3?[^ ]* +(\S*/)?"
                r"(chikyu_auto_download|chikyu_download2|chikyu_monthly_download2?|ftp_transfer|convert_csv)\.py")

REQ_RE  = re.compile(r"^req_(\d{4})(0[1-9]|1[0-2])_([a-z0-9]{6,16})\.json$")
KREQ_RE = re.compile(r"^kreq_(\d{4})(0[1-9]|1[0-2])_([a-z0-9]{6,16})\.json$")

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s", stream=sys.stdout)
logger = logging.getLogger("chikyu_poll")

# 同じ python プロセス内で chikyu_* / convert_csv を読み込めるようにする
if str(BASE_DIR) not in sys.path:
    sys.path.insert(0, str(BASE_DIR))


def ftp_connect():
    ftp = FTP(os.environ["XSERVER_FTP_HOST"], timeout=60)
    ftp.login(os.environ["XSERVER_FTP_USER"], os.environ["XSERVER_FTP_PASSWORD"])
    ftp.cwd(os.environ["XSERVER_DEST_DIR"])
    return ftp


def in_skip_window(now: datetime) -> bool:
    t = (now.hour, now.minute)
    return SKIP_FROM <= t < SKIP_TO


def other_job_running() -> bool:
    r = subprocess.run(["pgrep", "-f", BUSY_PATTERN], capture_output=True)
    return r.returncode == 0


def load_state() -> dict:
    try:
        return json.loads(STATE_FILE.read_text(encoding="utf-8"))
    except Exception:
        return {"done": [], "attempts": {}}


def save_state(state: dict):
    state["done"] = state["done"][-200:]
    STATE_FILE.write_text(json.dumps(state, ensure_ascii=False), encoding="utf-8")


def list_requests(now: datetime):
    """FTP上の requests/ から、形式が正しい依頼だけを返す。
    戻り値: (一般データの依頼, 拘束時間の依頼)  どちらも [(id, year, month)]
    """
    ftp = ftp_connect()
    try:
        try:
            ftp.cwd("requests")
        except error_perm:
            return [], []
        general, kousoku = [], []
        for name in ftp.nlst():
            base = os.path.basename(name)
            m = REQ_RE.match(base)
            target = general
            if not m:
                m = KREQ_RE.match(base)
                target = kousoku
            if not m:
                continue
            y, mo, rid = int(m.group(1)), int(m.group(2)), m.group(3)
            # 依頼ファイルの中身は信用せず、ファイル名から取り出した年月だけを使う
            if y < 2019 or (y, mo) > (now.year, now.month):
                logger.warning(f"範囲外の依頼を無視: {name}")
                continue
            target.append((rid, y, mo))
        return general, kousoku
    finally:
        try:
            ftp.quit()
        except Exception:
            pass


def upload_file(path: Path, remote_name: str = None):
    """.part で送信してから正式名に改名する"""
    remote_name = remote_name or path.name
    ftp = ftp_connect()
    try:
        part = remote_name + ".part"
        with open(path, "rb") as f:
            ftp.storbinary(f"STOR {part}", f)
        try:
            ftp.delete(remote_name)  # 同名が残っていれば置き換える
        except Exception:
            pass
        ftp.rename(part, remote_name)
    finally:
        try:
            ftp.quit()
        except Exception:
            pass


def mark_result(state: dict, ids, ok: bool):
    """成功なら完了、失敗なら試行回数を数え、MAX_ATTEMPTS回で打ち切る。"""
    for rid in ids:
        if ok:
            state["done"].append(rid)
        else:
            n = state["attempts"].get(rid, 0) + 1
            state["attempts"][rid] = n
            if n >= MAX_ATTEMPTS:
                logger.error(f"  → 依頼 {rid} は{MAX_ATTEMPTS}回失敗したため打ち切り")
                state["done"].append(rid)
    save_state(state)


# ─── 一般データ（積降時間管理） ────────────────────────────────

def fetch_month(dl, year: int, month: int):
    """地球号から指定月の一般データを取得し、保存したZIPのPathを返す（失敗時None）"""
    started = time.time()
    try:
        dl.run(year, month)
    except (Exception, SystemExit) as e:
        logger.error(f"{year}年{month}月の取得で例外: {e}")
    save_dir = Path(dl.CONFIG["save_dir"])
    yymm = f"{year - 2018:02d}{month:02d}"
    cands = [p for p in save_dir.glob(f"一般データ_{yymm}_*.zip") if p.stat().st_mtime >= started - 5]
    return max(cands, key=lambda p: p.stat().st_mtime) if cands else None


def process_general(todo, state):
    # 同じ月の依頼は1回の取得にまとめる
    months = {}
    for rid, y, mo in todo:
        months.setdefault((y, mo), []).append(rid)

    import chikyu_download2 as dl  # 依頼があるときだけ読み込む

    for (y, mo), ids in sorted(months.items()):
        if other_job_running():
            logger.info("他の取得処理が始まったため中断")
            break
        logger.info(f"=== 取得依頼(一般データ): {y}年{mo}月 (依頼ID: {', '.join(ids)}) ===")
        zip_path = fetch_month(dl, y, mo)
        ok = False
        if zip_path:
            try:
                upload_file(zip_path)
                zip_path.unlink()
                logger.info(f"  → 転送完了: {zip_path.name}")
                ok = True
            except Exception as e:
                logger.error(f"  → 転送に失敗: {e}")
        else:
            logger.error("  → ZIPを取得できませんでした")
        mark_result(state, ids, ok)


# ─── 拘束時間管理表（勤怠管理） ────────────────────────────────

def allowed_kousoku_months(now: datetime):
    """依頼できるのは当月と前月だけ。"""
    cur = (now.year, now.month)
    prev = (now.year - 1, 12) if now.month == 1 else (now.year, now.month - 1)
    return {cur, prev}


def fetch_kousoku_xlsx(year: int, month: int, work_dir: Path):
    """地球号から指定月の拘束時間管理表CSVを取得し、xlsx に変換して返す（失敗時None）。"""
    import chikyu_auto_download as da
    import convert_csv as cc

    csv_path = work_dir / "拘束時間管理表.csv"
    try:
        da.run(date(year, month, 1), csv_path)
    except (Exception, SystemExit) as e:
        logger.error(f"{year}年{month}月の拘束時間管理表の取得で例外: {e}")
        return None
    if not csv_path.exists():
        logger.error("  → CSVが保存されませんでした")
        return None

    # 取得できたCSVが依頼した月のものか確認する（別の月を取り込まない）
    ym = cc.read_header_year_month(str(csv_path))
    if ym != (year, month):
        logger.error(f"  → 取得したCSVの年月が依頼と異なります: {ym}")
        return None

    xlsx_path = work_dir / "kousoku.xlsx"
    try:
        df = cc.load_processed(str(csv_path))
        cc.write_excel(df, str(xlsx_path))
    except Exception as e:
        logger.error(f"  → xlsx への変換に失敗: {e}")
        return None
    logger.info(f"  → 変換完了: {len(df)}行")
    return xlsx_path


def process_kousoku(todo, state, now: datetime):
    allowed = allowed_kousoku_months(now)
    months = {}
    for rid, y, mo in todo:
        if (y, mo) not in allowed:
            logger.warning(f"当月・前月以外の拘束時間の依頼を無視: {y}-{mo:02d} (依頼ID: {rid})")
            state["done"].append(rid)
            save_state(state)
            continue
        months.setdefault((y, mo), []).append(rid)

    for (y, mo), ids in sorted(months.items()):
        if other_job_running():
            logger.info("他の取得処理が始まったため中断")
            break
        logger.info(f"=== 取得依頼(拘束時間): {y}年{mo}月 (依頼ID: {', '.join(ids)}) ===")
        work_dir = Path(tempfile.mkdtemp(prefix="kousoku_req_"))
        ok = False
        try:
            xlsx = fetch_kousoku_xlsx(y, mo, work_dir)
            if xlsx:
                try:
                    # 同じ月の依頼が複数あっても、取得は1回。依頼IDごとの名前で転送する
                    for rid in ids:
                        upload_file(xlsx, f"kousoku_kreq_{rid}.xlsx")
                        logger.info(f"  → 転送完了: kousoku_kreq_{rid}.xlsx")
                    ok = True
                except Exception as e:
                    logger.error(f"  → 転送に失敗: {e}")
        finally:
            shutil.rmtree(work_dir, ignore_errors=True)
        mark_result(state, ids, ok)


# ─── メイン ──────────────────────────────────────────────────

def main():
    ignore_window = "--ignore-window" in sys.argv
    now = datetime.now()

    lock = open(LOCK_FILE, "w")
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        return  # 前回分が実行中

    if not ignore_window and in_skip_window(now):
        return
    if other_job_running():
        logger.info("他の取得処理が実行中のためスキップ")
        return

    try:
        general, kousoku = list_requests(now)
    except Exception as e:
        logger.error(f"依頼の確認に失敗: {e}")
        return

    state = load_state()
    todo_general = [r for r in general if r[0] not in state["done"]]
    todo_kousoku = [r for r in kousoku if r[0] not in state["done"]]
    if not todo_general and not todo_kousoku:
        return

    if todo_general:
        process_general(todo_general, state)
    if todo_kousoku:
        process_kousoku(todo_kousoku, state, now)


if __name__ == "__main__":
    main()
