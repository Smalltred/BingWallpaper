#!/usr/bin/env python3 
# -*- coding: utf-8 -*-
# @Time    : 2023/3/16 1:15
# @Author  : Small tred
# @FileName: __init__.py.py
# @Software: PyCharm
# @Blog    : https://www.hecady.com
from flask import Flask
from config import Config


def create_app():
    app = Flask(__name__, static_folder='static', template_folder='templates')
    app.config.from_object(Config)
    # 注册蓝图
    from app.routes import api_bp
    app.register_blueprint(api_bp)
    return app
