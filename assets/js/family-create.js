/**
 * family-create.js - 创建家谱页交互逻辑
 * 依赖：address-selector.js, cover_preview.php
 */

document.addEventListener('DOMContentLoaded', function () {
    var cfg = window.FamilyCreateConfig || {};
    var siteUrl = cfg.siteUrl || '';

    // ── 地址选择器 ──
    (function initAddr() {
        if (typeof AddressSelector === 'undefined') {
            setTimeout(initAddr, 100);
            return;
        }
        var sel = document.getElementById('origin_province');
        if (!sel) { setTimeout(initAddr, 100); return; }

        new AddressSelector('origin_province', 'origin_city', 'origin_district', {
            hiddenInput: 'origin_value',
            originValue: cfg.originValue || ''
        });
    })();

    // ── 封面预览刷新 ──
    var surnameInput = document.getElementById('surname');
    var titleInput   = document.getElementById('title');
    if (surnameInput) {
        surnameInput.addEventListener('input', debounceUpdate);
        surnameInput.addEventListener('change', debounceUpdate);
    }
    if (titleInput) {
        titleInput.addEventListener('input', debounceUpdate);
        titleInput.addEventListener('change', debounceUpdate);
    }

    function debounceUpdate() {
        clearTimeout(window._previewTimer);
        window._previewTimer = setTimeout(updateCoverPreviews, 300);
    }

    function updateCoverPreviews() {
        var surname = (document.getElementById('surname') && document.getElementById('surname').value) || '李';
        surname = surname.replace(/[^\u4e00-\u9fa5]/g, '').substring(0, 3) || '李';
        var title = (document.getElementById('title') && document.getElementById('title').value) || '';
        title = title.trim().substring(0, 8);

        var imgs = document.querySelectorAll('.cover-preview-img');
        imgs.forEach(function (img) {
            var style = img.getAttribute('data-style');
            img.src = siteUrl + '/cover_preview.php?style=' + style +
                '&surname=' + encodeURIComponent(surname) +
                '&title=' + encodeURIComponent(title);
            img.style.display = '';
            var fb = img.nextElementSibling;
            if (fb && fb.classList.contains('cover-style-icon-fallback')) {
                fb.style.display = 'none';
            }
        });
    }

    // ── 封面样式选择 ──
    window.selectStyle = function (key, el) {
        var items = document.querySelectorAll('.cover-style-item');
        items.forEach(function (item) { item.classList.remove('active'); });
        el.classList.add('active');
        el.querySelector('input[type="radio"]').checked = true;
    };
});
