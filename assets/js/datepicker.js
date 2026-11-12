/**
 * 日期选择器 — 纯JS，零依赖，完全本地化
 * 点击 📅 图标弹出日历，选择后填入输入框
 * 同时支持手动输入文本
 */
(function() {
    var activeInput = null;
    var activePicker = null;

    // 创建全局日期选择器DOM
    function createPicker() {
        if (document.getElementById('_datepicker')) return;
        var div = document.createElement('div');
        div.id = '_datepicker';
        div.className = 'datepicker-popup';
        div.style.display = 'none';
        div.innerHTML =
            '<div class="dp-header">' +
                '<button type="button" class="dp-nav" data-action="prevY" title="上一年">««</button>' +
                '<button type="button" class="dp-nav" data-action="prevM" title="上一月">«</button>' +
                '<span class="dp-title"></span>' +
                '<button type="button" class="dp-nav" data-action="nextM" title="下一月">»</button>' +
                '<button type="button" class="dp-nav" data-action="nextY" title="下一年">»»</button>' +
            '</div>' +
            '<div class="dp-weekdays">' +
                '<span>日</span><span>一</span><span>二</span><span>三</span><span>四</span><span>五</span><span>六</span>' +
            '</div>' +
            '<div class="dp-days"></div>' +
            '<div class="dp-footer">' +
                '<button type="button" class="dp-today">今天</button>' +
                '<button type="button" class="dp-clear">清除</button>' +
            '</div>';
        document.body.appendChild(div);

        // 事件委托
        div.addEventListener('click', function(e) {
            var btn = e.target.closest('button');
            if (!btn) return;
            var action = btn.dataset.action;
            if (action === 'prevM') dpSetMonth(-1);
            else if (action === 'nextM') dpSetMonth(1);
            else if (action === 'prevY') dpSetYear(-1);
            else if (action === 'nextY') dpSetYear(1);
            else if (btn.classList.contains('dp-today')) dpSelectDate(new Date());
            else if (btn.classList.contains('dp-clear')) { dpHide(); if (activeInput) activeInput.value = ''; }
            else if (btn.classList.contains('dp-day')) {
                var y = parseInt(btn.dataset.y);
                var m = parseInt(btn.dataset.m);
                var d = parseInt(btn.dataset.d);
                dpSelectDate(new Date(y, m, d));
            }
        });

        // 点击外部关闭
        document.addEventListener('click', function(e) {
            if (activePicker && !activePicker.contains(e.target) && e.target !== activeInput && !e.target.closest('.datepicker-trigger')) {
                dpHide();
            }
        });
    }

    var dpYear, dpMonth;

    function dpShow(input) {
        activeInput = input;
        activePicker = document.getElementById('_datepicker');
        if (!activePicker) return;
        // 从输入值解析默认日期
        var val = input.value.trim();
        var d = parseDate(val);
        if (!d) d = new Date();
        dpYear = d.getFullYear();
        dpMonth = d.getMonth();
        dpRender();
        // 定位到输入框下方
        var rect = input.getBoundingClientRect();
        activePicker.style.top = (rect.bottom + window.scrollY + 4) + 'px';
        activePicker.style.left = (rect.left + window.scrollX) + 'px';
        activePicker.style.display = 'block';
    }

    function dpHide() {
        if (activePicker) activePicker.style.display = 'none';
        activeInput = null;
    }

    function dpSetMonth(delta) {
        dpMonth += delta;
        if (dpMonth < 0) { dpMonth = 11; dpYear--; }
        if (dpMonth > 11) { dpMonth = 0; dpYear++; }
        dpRender();
    }

    function dpSetYear(delta) {
        dpYear += delta;
        dpRender();
    }

    function dpSelectDate(date) {
        if (activeInput) {
            var y = date.getFullYear();
            var m = String(date.getMonth() + 1).padStart(2, '0');
            var d = String(date.getDate()).padStart(2, '0');
            activeInput.value = y + '-' + m + '-' + d;
            activeInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        dpHide();
    }

    function dpRender() {
        var title = activePicker.querySelector('.dp-title');
        title.textContent = dpYear + '年 ' + (dpMonth + 1) + '月';
        var daysDiv = activePicker.querySelector('.dp-days');
        daysDiv.innerHTML = '';
        var firstDay = new Date(dpYear, dpMonth, 1).getDay();
        var daysInMonth = new Date(dpYear, dpMonth + 1, 0).getDate();
        var today = new Date();
        for (var i = 0; i < firstDay; i++) {
            daysDiv.appendChild(document.createElement('span'));
        }
        for (var d = 1; d <= daysInMonth; d++) {
            var span = document.createElement('span');
            span.className = 'dp-day';
            span.textContent = d;
            span.dataset.y = dpYear;
            span.dataset.m = dpMonth;
            span.dataset.d = d;
            if (dpYear === today.getFullYear() && dpMonth === today.getMonth() && d === today.getDate()) {
                span.classList.add('dp-today');
            }
            daysDiv.appendChild(span);
        }
    }

    function parseDate(str) {
        if (!str) return null;
        // YYYY-MM-DD
        var m1 = str.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
        if (m1) return new Date(parseInt(m1[1]), parseInt(m1[2]) - 1, parseInt(m1[3]));
        // YYYY年
        var m2 = str.match(/^(\d{4})年/);
        if (m2) return new Date(parseInt(m2[1]), 0, 1);
        // YYYY
        var m3 = str.match(/^(\d{4})$/);
        if (m3) return new Date(parseInt(m3[1]), 0, 1);
        return null;
    }

    // 初始化
    createPicker();

    // 绑定所有带 datepicker 类的输入框
    function bindDateInputs() {
        document.querySelectorAll('.datepicker-input').forEach(function(input) {
            if (input.dataset.dpBound) return;
            input.dataset.dpBound = '1';
            // 在输入框后面插入触发按钮
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'datepicker-trigger';
            btn.innerHTML = '📅';
            btn.title = '选择日期';
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                dpShow(input);
            });
            input.parentNode.style.position = 'relative';
            input.parentNode.appendChild(btn);
        });
    }

    // 页面加载和DOM变化时绑定
    bindDateInputs();
    if (window.MutationObserver) {
        new MutationObserver(function() { bindDateInputs(); }).observe(document.body, { childList: true, subtree: true });
    }
})();
