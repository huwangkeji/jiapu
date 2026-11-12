/**
 * 家谱管理系统 - 主脚本
 */

// 密码显示切换
function togglePwd(id, btn) {
    var input = document.getElementById(id);
    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = '🔒';
    } else {
        input.type = 'password';
        btn.textContent = '👁';
    }
}

// 视图切换（世系图 / 成员列表）
function toggleView(view) {
    var treeView = document.getElementById('tree-view');
    var listView = document.getElementById('list-view');

    if (view === 'tree') {
        treeView.style.display = 'block';
        listView.style.display = 'none';
    } else {
        treeView.style.display = 'none';
        listView.style.display = 'block';
    }

    // 更新按钮状态
    var buttons = document.querySelectorAll('.action-bar .btn');
    buttons.forEach(function(btn) {
        if (btn.textContent.trim() === '世系图' || btn.textContent.trim() === '成员列表') {
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-outline');
        }
    });
    event.target.classList.remove('btn-outline');
    event.target.classList.add('btn-primary');
}

// 自动关闭消息提示
document.addEventListener('DOMContentLoaded', function() {

    // 用户菜单：点击头像/用户名展开或收起下拉菜单
    var userWrap = document.querySelector('.user-menu-wrap');
    var userTrigger = document.getElementById('userMenuTrigger');
    if (userWrap && userTrigger) {
        userTrigger.addEventListener('click', function(e) {
            e.preventDefault();
            var isOpen = userWrap.classList.toggle('open');
            userTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
        // 点击菜单外部区域关闭
        document.addEventListener('click', function(e) {
            if (!userWrap.contains(e.target)) {
                userWrap.classList.remove('open');
                userTrigger.setAttribute('aria-expanded', 'false');
            }
        });
    }

    var flash = document.querySelector('.flash-message');
    if (flash) {
        setTimeout(function() {
            flash.style.opacity = '0';
            flash.style.transform = 'translateX(100%)';
            flash.style.transition = 'all 0.3s ease';
            setTimeout(function() {
                flash.remove();
            }, 300);
        }, 4000);
    }

    // 表单确认
    var deleteForms = document.querySelectorAll('form[onsubmit*="confirm"]');
    deleteForms.forEach(function(form) {
        form.addEventListener('submit', function(e) {
            var msg = form.getAttribute('onsubmit');
            if (msg.indexOf('return confirm') !== -1) {
                // 已有confirm，跳过
                return;
            }
        });
    });

    // 平滑滚动
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(e) {
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });

    // 数字动画（首页统计）
    animateNumbers();
});

// 数字增长动画
function animateNumbers() {
    var statNums = document.querySelectorAll('.stat-num');
    statNums.forEach(function(el) {
        var target = parseInt(el.textContent);
        if (isNaN(target) || target === 0) return;

        var current = 0;
        var increment = Math.ceil(target / 40);
        var timer = setInterval(function() {
            current += increment;
            if (current >= target) {
                current = target;
                clearInterval(timer);
            }
            el.textContent = current;
        }, 30);
    });
}
