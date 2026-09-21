"""生成社交分享大图 frontend/static/og-image.png（1200x630）。

为什么需要：原来 og:image / twitter:image 都指向 favicon-192.png（192x192），
却声明 twitter:card = summary_large_image，社交平台拿到 192px 图标只能降级显示。

产出物是构建期资源，不进运行时。依赖 Pillow，以及 Windows 自带的
Georgia Bold / 微软雅黑 Bold 用于排版（仅用于本地渲染，不随站点分发）。
"""

import math
import os
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

W, H = 1200, 630
# 构建输入资产目录按脚本自身位置派生（tools/ 的上一级 = 项目根）。
# 不写死盘符：项目搬过家（E:\workbuddy → 当前工作区），写死会让脚本静默写到旧位置甚至直接报错。
# 注意是 frontend/static/ 而不是 frontend/public/ —— 后者是 Web 根，与构建输入不是一回事。
OUT = str(Path(__file__).resolve().parent.parent / "frontend" / "static" / "og-image.png")

CANVAS = (10, 10, 12)          # #0a0a0c
MOUNTAIN_BACK = (38, 38, 44)   # #26262c —— 比画布亮一档，两层山才分得开
MOUNTAIN_FRONT = (22, 22, 26)  # #16161a
ACCENT = (212, 168, 83)        # #d4a853
WHITE = (255, 255, 255)

FONT_DIR = r"C:\Windows\Fonts"
GEORGIA_BOLD = os.path.join(FONT_DIR, "georgiab.ttf")
YAHEI_BOLD = os.path.join(FONT_DIR, "msyhbd.ttc")


def font(path: str, size: int, index: int = 0):
    return ImageFont.truetype(path, size, index=index)


def radial_halo(size, center, radius, color, max_alpha=0.34):
    """柔和径向光晕。在 96x96 小图上算完后放大，避免 Python 逐像素循环太慢。"""
    w, h = size
    n = 96
    small = Image.new("L", (n, n), 0)
    px = small.load()
    cx, cy = center
    for y in range(n):
        for x in range(n):
            dx = (x / (n - 1)) * w - cx
            dy = (y / (n - 1)) * h - cy
            d = math.hypot(dx, dy)
            t = max(0.0, 1.0 - d / radius)
            px[x, y] = int(255 * (t ** 2.2) * max_alpha)

    mask = small.resize((w, h), Image.BICUBIC)
    layer = Image.new("RGBA", (w, h), color + (0,))
    layer.putalpha(mask)
    return layer


def draw_tracked(draw, xy, text, fnt, fill, tracking):
    """带字距的文本（营销小标题用）。"""
    x, y = xy
    for ch in text:
        draw.text((x, y), ch, font=fnt, fill=fill)
        x += draw.textlength(ch, font=fnt) + tracking
    return x


def map_points(pts, y_from=32.0, y_to=64.0, y_top=300.0, y_bottom=630.0):
    """把 favicon 里的山形控制点映射到 1200x630 画布。"""
    sx = W / 64.0
    sy = (y_bottom - y_top) / (y_to - y_from)
    return [(x * sx, y_top + (y - y_from) * sy) for x, y in pts]


def main():
    img = Image.new("RGB", (W, H), CANVAS)

    # 1) 琥珀色光晕（右侧，呼应 favicon 里悬在山影之上的太阳）
    halo = radial_halo((W, H), (800, 190), 340, ACCENT, max_alpha=0.34)
    img = Image.alpha_composite(img.convert("RGBA"), halo)

    draw = ImageDraw.Draw(img, "RGBA")

    # 2) 太阳
    sun_c, sun_r = (800, 190), 92
    draw.ellipse(
        [sun_c[0] - sun_r, sun_c[1] - sun_r, sun_c[0] + sun_r, sun_c[1] + sun_r],
        fill=ACCENT + (255,),
    )

    # 3) 双层山影（控制点取自 favicon.svg，保持同一套造型语言）
    back = [(0, 64), (0, 46.08), (16, 37.12), (32, 41.6), (49.92, 32), (64, 39.68), (64, 64)]
    front = [(0, 64), (0, 54.4), (11.52, 44.8), (28.8, 52.48), (39.68, 43.52), (56.32, 48), (64, 54.4), (64, 64)]
    back_pts = map_points(back)
    front_pts = map_points(front)

    draw.polygon(front_pts, fill=MOUNTAIN_FRONT + (255,))
    draw.polygon(back_pts, fill=MOUNTAIN_BACK + (255,))

    # 山脊高光：只靠两块平色在近黑底上分不出层次，加一道亮边才有"剪影"的立体感
    draw.line(back_pts[1:-1], fill=(60, 60, 68, 255), width=2, joint="curve")
    draw.line(front_pts[1:-1], fill=(44, 44, 52, 255), width=2, joint="curve")

    # 4) 文案
    x0 = 80
    f_brand = font(GEORGIA_BOLD, 30)
    f_label = font(GEORGIA_BOLD, 19)
    f_title = font(YAHEI_BOLD, 88)
    f_sub = font(YAHEI_BOLD, 26)
    f_url = font(GEORGIA_BOLD, 22)

    # 品牌行
    draw.ellipse([x0, 62, x0 + 13, 75], fill=ACCENT + (255,))
    draw.text((x0 + 26, 55), "BingWallpaper", font=f_brand, fill=WHITE + (240,))

    # 分隔线（用矩形而非 line，保证琥珀色实心不被抗锯齿洗淡）
    draw.rectangle([x0, 130, x0 + 64, 132], fill=ACCENT + (255,))

    # 小标题
    draw_tracked(draw, (x0, 178), "BING DAILY WALLPAPER", f_label, ACCENT + (235,), 4.5)

    # 主标题
    draw.text((x0 - 4, 232), "暗夜画廊", font=f_title, fill=WHITE + (255,))

    # 副标题
    draw.text((x0, 366), "Bing 每日精选壁纸 · 4K 原图免费下载", font=f_sub, fill=WHITE + (170,))

    # 右下角域名
    url = "bingwallpaper.hecady.com"
    uw = draw.textlength(url, font=f_url)
    draw.text((W - 80 - uw, H - 74), url, font=f_url, fill=WHITE + (110,))

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    img.convert("RGB").save(OUT, "PNG", optimize=True)
    print(f"已写入 {OUT}")
    print(f"尺寸 {W}x{H}，{os.path.getsize(OUT):,} bytes")


if __name__ == "__main__":
    main()
