"""从 Google Fonts 拉取 woff2 做自托管，输出 frontend/static/fonts/ 与 fonts.css。

为什么要自托管：fonts.googleapis.com 在国内访问不稳，首屏会长时间停在无字体状态（FOUT）。

两个已踩过的坑：
1. 多字重必须用分号分隔（wght@400;500;700）。写成 wght@400&wght@500 是非法语法，
   Google 会静默只返回第一个字重。
2. Google 对可变字体（DM Sans / JetBrains Mono）在各个字重下返回的是同一份文件，
   必须按内容去重，否则白背几倍体积。@font-face 用 font-weight 区间声明即可。
"""
import hashlib
import os
import re
import urllib.request
from pathlib import Path

# 构建输入资产目录按脚本自身位置派生（tools/ 的上一级 = 项目根）。
# 不写死盘符：项目搬过家（E:\workbuddy → 当前工作区），写死会让脚本静默写到旧位置。
# frontend/static/ 是构建输入；Web 根是 public/，两者刻意不同名。
OUT_DIR = str(Path(__file__).resolve().parent.parent / "frontend" / "static" / "fonts")

# 只取 latin 子集：站点正文是中文，CJK 字体动辄几 MB，一律回落系统字体栈
TARGETS = [
    ("Playfair Display", [700]),
    ("DM Sans", [400, 500, 700]),
    ("JetBrains Mono", [400, 500]),
]

UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
)


def fetch(url: str) -> bytes:
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    return urllib.request.urlopen(req, timeout=30).read()


def css_url(family: str, weights: list) -> str:
    fam = family.replace(" ", "+")
    w = ";".join(str(x) for x in sorted(weights))
    return f"https://fonts.googleapis.com/css2?family={fam}:wght@{w}&display=swap"


def main() -> None:
    os.makedirs(OUT_DIR, exist_ok=True)

    # family -> {md5: {"data": bytes, "weights": set()}}
    families: dict = {}

    for family, weights in TARGETS:
        css = fetch(css_url(family, weights)).decode("utf-8")
        buckets = families.setdefault(family, {})

        for block in re.findall(r"@font-face\s*\{(.*?)\}", css, re.S):
            ur = re.search(r"unicode-range:\s*([^;]+);", block)
            src = re.search(r"url\((https://[^)]+\.woff2)\)", block)
            wt = re.search(r"font-weight:\s*(\d+)", block)
            if not (ur and src and wt):
                continue
            if "U+0000-00FF" not in ur.group(1).replace(" ", ""):
                continue  # 只要 latin

            data = fetch(src.group(1))
            digest = hashlib.md5(data).hexdigest()
            slot = buckets.setdefault(digest, {"data": data, "weights": set()})
            slot["weights"].add(int(wt.group(1)))

    lines = [
        "/* 自托管字体（latin 子集），由 tools/fetch-fonts.py 生成，勿手改。",
        " *",
        " * 中文不在此列：CJK 字体体积过大，统一回落系统字体栈",
        " * （PingFang SC / 微软雅黑 / Noto Sans SC 等）。",
        " *",
        " * 可变字体去重说明：DM Sans、JetBrains Mono 在 400/500/700 各字重回源是",
        " * 同一份可变字体文件，因此按内容去重后每族只保留一份，用 font-weight 区间声明。",
        " */",
        "",
    ]

    total = 0
    count = 0
    for family, buckets in families.items():
        for digest, slot in buckets.items():
            lo, hi = min(slot["weights"]), max(slot["weights"])
            fname = f"{family.replace(' ', '')}.woff2"
            with open(os.path.join(OUT_DIR, fname), "wb") as fh:
                fh.write(slot["data"])

            weight_decl = f"{lo}" if lo == hi else f"{lo} {hi}"
            lines += [
                "@font-face {",
                f"  font-family: '{family}';",
                "  font-style: normal;",
                f"  font-weight: {weight_decl};",
                "  font-display: swap;",
                f"  src: url('./{fname}') format('woff2');",
                "}",
                "",
            ]
            size = len(slot["data"])
            total += size
            count += 1
            print(f"  {family:18} weight {weight_decl:8} -> {fname} ({size:,} bytes)")

    css_path = os.path.join(OUT_DIR, "fonts.css")
    with open(css_path, "w", encoding="utf-8", newline="\n") as fh:
        fh.write("\n".join(lines))

    print(f"\n去重后 {count} 个文件，合计 {total:,} bytes ({total / 1024:.1f} KB)")
    print(f"已写入 {css_path}")


if __name__ == "__main__":
    main()
