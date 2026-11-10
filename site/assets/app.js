/* ============================================================================
   官网脚本：无依赖，几十行够用
   - __ORIGIN__ 占位：页面里写相对完整命令时，运行时换成当前站点地址，
     这样换域名 / 用 IP 访问 / 本地 file:// 预览都不用改 HTML
   - 复制按钮：data-copy-target 指向要复制的元素 id
   ========================================================================== */
(function () {
    'use strict';

    var origin = location.origin && location.origin !== 'null' ? location.origin : '';

    // 1) 替换站点地址占位
    if (origin) {
        document.querySelectorAll('body *').forEach(function (el) {
            if (el.children.length === 0 && el.textContent.indexOf('__ORIGIN__') !== -1) {
                el.textContent = el.textContent.replace(/__ORIGIN__/g, origin);
            }
        });
    }

    // 2) 移动端菜单
    var toggle = document.getElementById('navToggle');
    var links = document.getElementById('navLinks');

    if (toggle && links) {
        toggle.addEventListener('click', function () {
            links.classList.toggle('open');
        });
    }

    // 3) 复制到剪贴板
    document.querySelectorAll('.btn-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.getAttribute('data-copy-target'));
            if (!target) return;

            var text = target.textContent.trim();
            var done = function () {
                var old = btn.textContent;
                btn.textContent = '已复制';
                setTimeout(function () { btn.textContent = old; }, 1600);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(fallback);
            } else {
                fallback();
            }

            function fallback() {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();

                try { document.execCommand('copy'); done(); } catch (e) { /* 静默失败 */ }

                document.body.removeChild(ta);
            }
        });
    });

    // 4) 页脚年份
    var year = document.getElementById('year');
    if (year) year.textContent = String(new Date().getFullYear());
})();
