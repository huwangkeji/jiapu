/**
 * profile.js - 个人设置页交互逻辑
 * 依赖：address-selector.js
 */

document.addEventListener('DOMContentLoaded', function () {
    var cfg = window.ProfileConfig || {};

    // ── 地区地址选择器 ──
    (function initAddr() {
        if (typeof AddressSelector === 'undefined') {
            setTimeout(initAddr, 100);
            return;
        }
        var sel = document.getElementById('region_province');
        if (!sel) { setTimeout(initAddr, 100); return; }

        new AddressSelector('region_province', 'region_city', 'region_district', {
            hiddenInput: 'region_value',
            originValue: cfg.regionValue || ''
        });
    })();

    // ── 头像裁剪器 ──
    var cropState = {
        img: null, scale: 1, offsetX: 0, offsetY: 0, rotation: 0,
        dragging: false, dragStartX: 0, dragStartY: 0,
        canvasSize: 280, cropRadius: 130
    };

    var avatarInput = document.getElementById('avatar-input');
    if (avatarInput) {
        avatarInput.addEventListener('change', function (e) {
            var file = e.target.files[0];
            if (!file) return;
            if (file.size > 5 * 1024 * 1024) { alert('图片不能超过5MB'); this.value = ''; return; }
            var reader = new FileReader();
            reader.onload = function (ev) { window._openCrop(ev.target.result); };
            reader.readAsDataURL(file);
        });
    }

    window._openCrop = function (dataUrl) {
        cropState.img = new Image();
        cropState.img.onload = function () {
            var size = cropState.canvasSize;
            cropState.scale = Math.min((size - 40) / cropState.img.width, (size - 40) / cropState.img.height, 1.2);
            cropState.offsetX = 0;
            cropState.offsetY = 0;
            cropState.rotation = 0;
            var overlay = document.getElementById('cropOverlay');
            if (overlay) overlay.style.display = 'flex';
            window._renderCrop();
        };
        cropState.img.src = dataUrl;
    };

    window._closeCrop = function () {
        var overlay = document.getElementById('cropOverlay');
        if (overlay) overlay.style.display = 'none';
        var ai = document.getElementById('avatar-input');
        if (ai) ai.value = '';
    };

    window._renderCrop = function () {
        var canvas = document.getElementById('cropCanvas');
        if (!canvas) return;
        var size = cropState.canvasSize;
        canvas.width = size;
        canvas.height = size;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#e8e8e8';
        ctx.fillRect(0, 0, size, size);
        ctx.save();
        var cx = size / 2, cy = size / 2;
        ctx.translate(cx + cropState.offsetX, cy + cropState.offsetY);
        ctx.rotate(cropState.rotation * Math.PI / 180);
        if (cropState.img) {
            var sw = cropState.img.width * cropState.scale;
            var sh = cropState.img.height * cropState.scale;
            ctx.drawImage(cropState.img, -sw / 2, -sh / 2, sw, sh);
        }
        ctx.restore();
        ctx.fillStyle = 'rgba(0,0,0,0.45)';
        ctx.beginPath();
        ctx.rect(0, 0, size, size);
        ctx.arc(cx, cy, cropState.cropRadius, 0, Math.PI * 2, true);
        ctx.fill();
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 3;
        ctx.beginPath();
        ctx.arc(cx, cy, cropState.cropRadius, 0, Math.PI * 2);
        ctx.stroke();
        ctx.strokeStyle = 'rgba(0,0,0,0.3)';
        ctx.lineWidth = 1;
        ctx.stroke();
    };

    window._applyCrop = function () {
        var canvas = document.getElementById('cropCanvas');
        if (!canvas) return;
        var size = cropState.canvasSize, r = cropState.cropRadius;
        var outCanvas = document.createElement('canvas');
        var outSize = 200;
        outCanvas.width = outSize; outCanvas.height = outSize;
        var outCtx = outCanvas.getContext('2d');
        outCtx.beginPath();
        outCtx.arc(outSize / 2, outSize / 2, outSize / 2, 0, Math.PI * 2);
        outCtx.clip();
        var sx = (size / 2 - r), sy = (size / 2 - r);
        outCtx.drawImage(canvas, sx, sy, r * 2, r * 2, 0, 0, outSize, outSize);
        var dataUrl = outCanvas.toDataURL('image/png', 0.9);
        var preview = document.getElementById('avatarPreview');
        if (preview) preview.innerHTML = '<img src="' + dataUrl + '" alt="头像预览" style="width:100%;height:100%;object-fit:cover;">';
        var cropInput = document.getElementById('avatar-crop-input');
        var keepInput = document.getElementById('avatar-keep-input');
        if (cropInput) cropInput.value = dataUrl;
        if (keepInput) keepInput.value = '';
        window._closeCrop();
    };

    window._zoomCrop = function (delta) {
        cropState.scale = Math.max(0.3, Math.min(3, cropState.scale + delta));
        window._renderCrop();
    };

    window._rotateCrop = function () {
        cropState.rotation = (cropState.rotation + 90) % 360;
        window._renderCrop();
    };

    // ── 裁剪画布交互 ──
    var cropCanvas = document.getElementById('cropCanvas');
    if (cropCanvas) {
        cropCanvas.addEventListener('mousedown', function (e) {
            cropState.dragging = true;
            var rect = cropCanvas.getBoundingClientRect();
            cropState.dragStartX = e.clientX - rect.left - cropState.offsetX;
            cropState.dragStartY = e.clientY - rect.top - cropState.offsetY;
        });
        cropCanvas.addEventListener('mousemove', function (e) {
            if (!cropState.dragging) return;
            var rect = cropCanvas.getBoundingClientRect();
            cropState.offsetX = e.clientX - rect.left - cropState.dragStartX;
            cropState.offsetY = e.clientY - rect.top - cropState.dragStartY;
            window._renderCrop();
        });
        cropCanvas.addEventListener('mouseup', function () { cropState.dragging = false; });
        cropCanvas.addEventListener('mouseleave', function () { cropState.dragging = false; });
        cropCanvas.addEventListener('wheel', function (e) {
            e.preventDefault();
            window._zoomCrop(e.deltaY < 0 ? 0.1 : -0.1);
        });
    }

    // 遮罩点击关闭
    var overlay = document.getElementById('cropOverlay');
    if (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) window._closeCrop();
        });
    }
});
