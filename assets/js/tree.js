/**
 * 世系图交互脚本
 * 支持缩放、拖拽和搜索
 */

document.addEventListener('DOMContentLoaded', function() {
    var treeContainer = document.querySelector('.tree-container');
    if (!treeContainer) return;

    var treeScroll = treeContainer.querySelector('.tree-scroll');
    if (!treeScroll) return;

    // 鼠标滚轮缩放
    var scale = 1;
    var minScale = 0.3;
    var maxScale = 2;

    treeContainer.addEventListener('wheel', function(e) {
        if (e.ctrlKey || e.metaKey) {
            e.preventDefault();
            var delta = e.deltaY > 0 ? -0.1 : 0.1;
            scale = Math.max(minScale, Math.min(maxScale, scale + delta));
            treeScroll.style.transform = 'scale(' + scale + ')';
            treeScroll.style.transformOrigin = 'center top';
        }
    }, { passive: false });

    // 拖拽滚动
    var isDragging = false;
    var startX, startY, scrollLeft, scrollTop;

    treeContainer.addEventListener('mousedown', function(e) {
        if (e.target.closest('.node-content')) return;
        isDragging = true;
        treeContainer.style.cursor = 'grabbing';
        startX = e.pageX - treeContainer.offsetLeft;
        startY = e.pageY - treeContainer.offsetTop;
        scrollLeft = treeContainer.scrollLeft;
        scrollTop = treeContainer.scrollTop;
    });

    treeContainer.addEventListener('mouseleave', function() {
        isDragging = false;
        treeContainer.style.cursor = 'default';
    });

    treeContainer.addEventListener('mouseup', function() {
        isDragging = false;
        treeContainer.style.cursor = 'default';
    });

    treeContainer.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        e.preventDefault();
        var x = e.pageX - treeContainer.offsetLeft;
        var y = e.pageY - treeContainer.offsetTop;
        var walkX = (x - startX) * 1.5;
        var walkY = (y - startY) * 1.5;
        treeContainer.scrollLeft = scrollLeft - walkX;
        treeContainer.scrollTop = scrollTop - walkY;
    });

    // 居中显示
    setTimeout(function() {
        var tree = treeContainer.querySelector('.tree');
        if (tree) {
            var containerWidth = treeContainer.clientWidth;
            var treeWidth = tree.scrollWidth;
            if (treeWidth > containerWidth) {
                treeContainer.scrollLeft = (treeWidth - containerWidth) / 2;
            }
        }
    }, 100);

    // 节点点击展开/收缩（手机端）
    var nodes = document.querySelectorAll('.node-content');
    nodes.forEach(function(node) {
        node.addEventListener('click', function() {
            // 在手机端显示操作
            if (window.innerWidth <= 768) {
                var actions = this.querySelector('.node-actions');
                if (actions) {
                    actions.style.opacity = actions.style.opacity === '1' ? '0' : '1';
                }
            }
        });
    });
});
