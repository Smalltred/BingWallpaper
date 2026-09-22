# -*- coding: utf-8 -*-
"""独立交叉校验部署包（不用 Node，自己实现一遍 zip 检查）。

用法：npm run package 之后，在项目根跑
    python tools/verify-package.py
全 PASS 退出码 0，否则 1。只依赖 Python 标准库。

注意断言设计：凡会随每日更新变化的东西（行数、最新日期、daily-api 行数）
一律不做硬编码快照断言，改为与本地源库 bingimages/bing_wallpapers.db
做 sha256 逐字节对比；源库本身由 app/ArchiveUpdater.php 维护
（CLI 入口 tools/update-archive.php 与「每天首次访问」共用这一个核心）。
"""

import hashlib
import sqlite3
import sys
import tempfile
import zipfile
from pathlib import Path

SRC = Path(__file__).resolve().parent.parent  # 项目根（tools/ 的上一层）
ZIP = SRC / "bingwallpaper-deploy.zip"
SRC_DB = SRC / "bingimages" / "bing_wallpapers.db"

EXPECTED_COLS = [
    "date", "date_compact", "year", "month", "day", "weekday", "title", "title_en",
    "copyright", "copyright_en", "copyrightlink", "url", "urlbase", "region", "sources",
]

fails: list[str] = []


def check(name: str, ok: bool, detail: str = "") -> None:
    print(f"  {'PASS' if ok else 'FAIL'}  {name}" + (f"  | {detail}" if detail else ""))
    if not ok:
        fails.append(name)


def sha256_file(p: Path) -> str:
    return hashlib.sha256(p.read_bytes()).hexdigest()


with zipfile.ZipFile(ZIP) as z:
    names = z.namelist()
    print(f"=== 包内 {len(names)} 个条目 ===\n")

    print("=== 1. CRC 全量自校验 ===")
    bad = z.testzip()
    check("zipfile.testzip() 无坏条目", bad is None, str(bad))

    print("\n=== 2. 每日更新脚本已随包发出 ===")
    check("含 tools/update-archive.php", "tools/update-archive.php" in names)
    # 与源文件逐字节比对：确保发出去的就是本地验过的那份
    in_zip = z.read("tools/update-archive.php")
    on_disk = (SRC / "tools" / "update-archive.php").read_bytes()
    check(
        "包内脚本与源文件字节一致",
        hashlib.sha256(in_zip).hexdigest() == hashlib.sha256(on_disk).hexdigest(),
        f"sha256(前16) {hashlib.sha256(in_zip).hexdigest()[:16]}",
    )
    src_text = in_zip.decode("utf-8")
    check("包内脚本用的是修复后的项目根推导", "realpath($here . '/..')" in src_text)
    check("包内脚本没有退回 dirname(__DIR__) 的写法", "$projectRoot = dirname(__DIR__);" not in src_text)

    # 「写库的域名必须是 cn.bing.com」这条约束，在 2026-09-22 之后从 update-archive.php
    # 移到了 app/ArchiveUpdater.php（CLI 变成薄壳，核心与「首访自动更新」共用）。
    # 断言跟着挪，别只盯着旧文件 —— 否则会把「核心文件没发出去 / 域名被改坏」放过。
    check("含 app/ArchiveUpdater.php", "app/ArchiveUpdater.php" in names)
    updater_zip = z.read("app/ArchiveUpdater.php")
    updater_src = (SRC / "app" / "ArchiveUpdater.php").read_bytes()
    check(
        "包内 ArchiveUpdater 与源文件字节一致",
        hashlib.sha256(updater_zip).hexdigest() == hashlib.sha256(updater_src).hexdigest(),
        f"sha256(前16) {hashlib.sha256(updater_zip).hexdigest()[:16]}",
    )
    updater_text = updater_zip.decode("utf-8")
    check("写库路径用的是 Config::archiveImageBase()", "archiveImageBase()" in updater_text)

    # 默认值本身必须是 cn.bing.com；4K 判定只认这个 host。两处一起断言，
    # 才能保证「补进去的行 url_4k 不会全变 null」。
    cfg_text = z.read("app/Config.php").decode("utf-8")
    check(
        "Config 默认归档域名是 cn.bing.com",
        "ARCHIVE_IMAGE_BASE', 'https://cn.bing.com'" in cfg_text,
    )
    archive_text = z.read("app/Archive.php").decode("utf-8")
    check("4K 判定只认 cn.bing.com", "'cn.bing.com'" in archive_text)

    print("\n=== 3. 只带了这一个 tools 文件（不带开发脚本）===")
    tools_entries = sorted(n for n in names if n.startswith("tools/"))
    check(
        "tools/ 下只有 update-archive.php",
        tools_entries == ["tools/update-archive.php"],
        str(tools_entries),
    )

    print("\n=== 4. 禁忌项 ===")
    for prefix in ("node_modules/", "frontend/", "bingimages/", ".workbuddy/", "data/bing-wallpapers.json"):
        hit = [n for n in names if n.startswith(prefix)]
        check(f"不含 {prefix}", not hit, str(hit[:3]))
    check("不含 *.zip", not any(n.endswith(".zip") for n in names))
    check("不含 .env.example 之外的 .env.* 变体", not any(n.startswith(".env.") and n != ".env.example" for n in names))

    print("\n=== 5. 必需条目 ===")
    for required in ("public/index.php", "public/.htaccess", "app/Config.php", "app/Archive.php",
                     "app/ArchiveUpdater.php",
                     "DEPLOY.md", ".env", "dataset/bing_wallpapers.db", "data/.gitkeep",
                     "BUILD-INFO.txt", "nginx-rewrite.conf"):
        check(f"含 {required}", required in names)

    print("\n=== 6. 数据集：与本地源库逐字节一致（不硬编码快照数字）===")
    if not SRC_DB.exists():
        check(f"源库存在 {SRC_DB}", False)
    else:
        with tempfile.TemporaryDirectory(prefix="bt_pkg_") as tmp:
            z.extract("dataset/bing_wallpapers.db", tmp)
            db = Path(tmp) / "dataset" / "bing_wallpapers.db"
            h_zip = sha256_file(db)
            h_src = sha256_file(SRC_DB)
            check("包内 DB 与 bingimages/bing_wallpapers.db sha256 一致", h_zip == h_src, h_src[:16])

            # 列结构是稳定契约，照常断言；行数/日期只作信息输出
            con = sqlite3.connect(f"file:{db}?mode=ro", uri=True)
            total = con.execute("SELECT COUNT(*) FROM bing_wallpapers").fetchone()[0]
            mn, mx = con.execute("SELECT MIN(date), MAX(date) FROM bing_wallpapers").fetchone()
            cols = [r[1] for r in con.execute("PRAGMA table_info(bing_wallpapers)")]
            con.close()
            check("15 列齐全", cols == EXPECTED_COLS, str(cols))
            print(f"  INFO  包内库 {total} 行，日期范围 {mn} ~ {mx}")

    print("\n=== 7. 包内 .env 的关键项 ===")
    env_text = z.read(".env").decode("utf-8")
    for key, want in (("APP_ENV", "production"), ("BINGIMAGES_DIR", "dataset"), ("TRUST_PROXY", "1")):
        line = next((l for l in env_text.splitlines() if l.strip().startswith(key + "=")), "")
        check(f"{key}={want}", line.strip() == f"{key}={want}", line.strip())

print(f"\n===== 汇总: {'全部通过' if not fails else str(len(fails)) + ' 项失败'} =====")
if fails:
    for f in fails:
        print("  - " + f)
sys.exit(1 if fails else 0)
