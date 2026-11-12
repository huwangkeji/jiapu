/**
 * 本地化富文本编辑器
 * 功能：粗体、斜体、下划线、文字颜色、插入图片、超链接、对齐、列表
 * 完全本地化，零外部依赖
 */
(function() {
    'use strict';

    window.RichEditor = {
        init: function(selector) {
            var textarea = document.querySelector(selector);
            if (!textarea) return;

            // 创建编辑器容器
            var container = document.createElement('div');
            container.className = 'rich-editor';
            container.style.border = '1px solid #d0c5b5';
            container.style.borderRadius = '6px';
            container.style.background = '#fff';

            // 创建工具栏
            var toolbar = document.createElement('div');
            toolbar.className = 'rich-editor-toolbar';
            toolbar.style.display = 'flex';
            toolbar.style.flexWrap = 'wrap';
            toolbar.style.gap = '4px';
            toolbar.style.padding = '8px 10px';
            toolbar.style.borderBottom = '1px solid #e8e8e8';
            toolbar.style.background = '#fafafa';
            toolbar.style.borderRadius = '6px 6px 0 0';

            // 工具按钮配置
            var buttons = [
                { cmd: 'bold', icon: 'B', title: '粗体', style: 'font-weight:bold' },
                { cmd: 'italic', icon: 'I', title: '斜体', style: 'font-style:italic' },
                { cmd: 'underline', icon: 'U', title: '下划线', style: 'text-decoration:underline' },
                { type: 'separator' },
                { cmd: 'foreColor', icon: 'A', title: '文字颜色', type: 'color' },
                { type: 'separator' },
                { cmd: 'justifyLeft', icon: '⬅', title: '左对齐' },
                { cmd: 'justifyCenter', icon: '↔', title: '居中' },
                { cmd: 'justifyRight', icon: '➡', title: '右对齐' },
                { type: 'separator' },
                { cmd: 'insertUnorderedList', icon: '• 列表', title: '无序列表' },
                { cmd: 'insertOrderedList', icon: '1. 列表', title: '有序列表' },
                { type: 'separator' },
                { cmd: 'createLink', icon: '🔗', title: '插入链接', type: 'prompt' },
                { cmd: 'insertImage', icon: '🖼️', title: '插入图片', type: 'image' },
                { cmd: 'removeFormat', icon: '✕ 清除', title: '清除格式' },
            ];

            buttons.forEach(function(btn) {
                if (btn.type === 'separator') {
                    var sep = document.createElement('span');
                    sep.style.width = '1px';
                    sep.style.height = '20px';
                    sep.style.background = '#ddd';
                    sep.style.margin = '0 4px';
                    toolbar.appendChild(sep);
                    return;
                }

                var button = document.createElement('button');
                button.type = 'button';
                button.innerHTML = btn.icon;
                button.title = btn.title;
                button.style.padding = '6px 10px';
                button.style.border = '1px solid #d0c5b5';
                button.style.background = '#fff';
                button.style.borderRadius = '4px';
                button.style.cursor = 'pointer';
                button.style.fontSize = '13px';
                button.style.minWidth = '32px';
                button.style.transition = 'all 0.15s';
                if (btn.style) button.style.cssText += btn.style;

                button.onmouseover = function() {
                    this.style.background = '#f5f0e8';
                    this.style.borderColor = '#c4982e';
                };
                button.onmouseout = function() {
                    this.style.background = '#fff';
                    this.style.borderColor = '#d0c5b5';
                };

                button.onclick = function(e) {
                    e.preventDefault();
                    editor.focus();

                    if (btn.type === 'color') {
                        showColorPicker(button, btn.cmd);
                    } else if (btn.type === 'prompt') {
                        var url = prompt('请输入链接地址：', 'https://');
                        if (url) document.execCommand(btn.cmd, false, url);
                    } else if (btn.type === 'image') {
                        showImagePicker(button);
                    } else {
                        document.execCommand(btn.cmd, false, null);
                    }
                    updateToolbarState();
                };

                toolbar.appendChild(button);
            });

            // 创建可编辑区域
            var editor = document.createElement('div');
            editor.className = 'rich-editor-content';
            editor.contentEditable = true;
            editor.innerHTML = textarea.value;
            editor.style.minHeight = textarea.rows ? (textarea.rows * 24 + 'px') : '300px';
            editor.style.maxHeight = '600px';
            editor.style.padding = '12px 14px';
            editor.style.outline = 'none';
            editor.style.fontSize = '14px';
            editor.style.lineHeight = '1.8';
            editor.style.overflow = 'auto';

            // 同步内容到 textarea
            editor.oninput = function() {
                textarea.value = editor.innerHTML;
            };

            // 更新工具栏状态
            function updateToolbarState() {
                toolbar.querySelectorAll('button').forEach(function(btn) {
                    var cmd = btn.getAttribute('data-cmd');
                    if (cmd && document.queryCommandState(cmd)) {
                        btn.style.background = '#e8d5b5';
                        btn.style.borderColor = '#b8860b';
                    } else if (!btn.getAttribute('data-type')) {
                        btn.style.background = '#fff';
                        btn.style.borderColor = '#d0c5b5';
                    }
                });
            }

            editor.onkeyup = updateToolbarState;
            editor.onmouseup = updateToolbarState;
            editor.onmouseup = updateToolbarState;

            // 颜色选择器
            function showColorPicker(triggerBtn, cmd) {
                var existing = document.querySelector('.rich-color-picker');
                if (existing) existing.remove();

                var picker = document.createElement('div');
                picker.className = 'rich-color-picker';
                picker.style.position = 'absolute';
                picker.style.background = '#fff';
                picker.style.border = '1px solid #d0c5b5';
                picker.style.borderRadius = '6px';
                picker.style.padding = '10px';
                picker.style.boxShadow = '0 4px 20px rgba(0,0,0,0.15)';
                picker.style.zIndex = '9999';
                picker.style.display = 'grid';
                picker.style.gridTemplateColumns = 'repeat(8, 24px)';
                picker.style.gap = '4px';

                var colors = [
                    '#000000', '#333333', '#666666', '#999999', '#cccccc', '#ffffff', '#ff0000', '#ff4444',
                    '#00ff00', '#44ff44', '#0000ff', '#4444ff', '#ffff00', '#ffff44', '#ff00ff', '#ff44ff',
                    '#00ffff', '#44ffff', '#ff8800', '#ffaa44', '#8800ff', '#aa44ff', '#0088ff', '#44aaff',
                    '#8B0000', '#A52A2A', '#D2691E', '#CD853F', '#DAA520', '#B8860B', '#556B2F', '#6B8E23'
                ];

                colors.forEach(function(color) {
                    var swatch = document.createElement('span');
                    swatch.style.width = '24px';
                    swatch.style.height = '24px';
                    swatch.style.background = color;
                    swatch.style.border = '1px solid #ddd';
                    swatch.style.borderRadius = '3px';
                    swatch.style.cursor = 'pointer';
                    swatch.onclick = function() {
                        document.execCommand(cmd, false, color);
                        picker.remove();
                        editor.focus();
                    };
                    picker.appendChild(swatch);
                });

                // 自定义颜色输入
                var customRow = document.createElement('div');
                customRow.style.gridColumn = '1 / -1';
                customRow.style.display = 'flex';
                customRow.style.gap = '6px';
                customRow.style.marginTop = '6px';
                customRow.style.paddingTop = '8px';
                customRow.style.borderTop = '1px solid #eee';

                var colorInput = document.createElement('input');
                colorInput.type = 'color';
                colorInput.value = '#333333';
                colorInput.style.width = '40px';
                colorInput.style.height = '28px';
                colorInput.style.border = '1px solid #d0c5b5';
                colorInput.style.borderRadius = '3px';
                colorInput.style.cursor = 'pointer';

                var confirmBtn = document.createElement('button');
                confirmBtn.textContent = '确定';
                confirmBtn.style.flex = '1';
                confirmBtn.style.padding = '4px 12px';
                confirmBtn.style.border = '1px solid #b8860b';
                confirmBtn.style.background = '#b8860b';
                confirmBtn.style.color = '#fff';
                confirmBtn.style.borderRadius = '3px';
                confirmBtn.style.cursor = 'pointer';
                confirmBtn.onclick = function() {
                    document.execCommand(cmd, false, colorInput.value);
                    picker.remove();
                    editor.focus();
                };

                customRow.appendChild(colorInput);
                customRow.appendChild(confirmBtn);
                picker.appendChild(customRow);

                document.body.appendChild(picker);

                var rect = triggerBtn.getBoundingClientRect();
                picker.style.left = rect.left + 'px';
                picker.style.top = (rect.bottom + 5) + 'px';

                // 点击外部关闭
                setTimeout(function() {
                    document.addEventListener('click', function closePicker(e) {
                        if (!picker.contains(e.target) && e.target !== triggerBtn) {
                            picker.remove();
                            document.removeEventListener('click', closePicker);
                        }
                    });
                }, 10);
            }

            // 图片选择器（支持上传和 URL）
            function showImagePicker(triggerBtn) {
                var existing = document.querySelector('.rich-image-picker');
                if (existing) existing.remove();

                var picker = document.createElement('div');
                picker.className = 'rich-image-picker';
                picker.style.position = 'absolute';
                picker.style.background = '#fff';
                picker.style.border = '1px solid #d0c5b5';
                picker.style.borderRadius = '6px';
                picker.style.padding = '14px';
                picker.style.boxShadow = '0 4px 20px rgba(0,0,0,0.15)';
                picker.style.zIndex = '9999';
                picker.style.minWidth = '280px';

                var title = document.createElement('h4');
                title.textContent = '插入图片';
                title.style.margin = '0 0 12px';
                title.style.fontSize = '14px';
                title.style.color = '#5D3A1A';
                picker.appendChild(title);

                // 方式1：URL
                var urlLabel = document.createElement('label');
                urlLabel.textContent = '图片地址：';
                urlLabel.style.display = 'block';
                urlLabel.style.fontSize = '12px';
                urlLabel.style.color = '#666';
                urlLabel.style.marginBottom = '4px';
                picker.appendChild(urlLabel);

                var urlInput = document.createElement('input');
                urlInput.type = 'text';
                urlInput.placeholder = 'https://... 或 /uploads/...';
                urlInput.style.width = '100%';
                urlInput.style.padding = '8px 10px';
                urlInput.style.border = '1px solid #d0c5b5';
                urlInput.style.borderRadius = '4px';
                urlInput.style.fontSize = '13px';
                urlInput.style.boxSizing = 'border-box';
                picker.appendChild(urlInput);

                // 或分隔线
                var orDiv = document.createElement('div');
                orDiv.style.textAlign = 'center';
                orDiv.style.margin = '12px 0';
                orDiv.style.fontSize = '12px';
                orDiv.style.color = '#999';
                orDiv.innerHTML = '<span style="background:#fff;padding:0 8px;">或</span>';
                orDiv.style.borderTop = '1px solid #eee';
                orDiv.style.lineHeight = '1px';
                picker.appendChild(orDiv);

                // 方式2：上传
                var fileInput = document.createElement('input');
                fileInput.type = 'file';
                fileInput.accept = 'image/*';
                fileInput.style.display = 'none';
                picker.appendChild(fileInput);

                var uploadBtn = document.createElement('button');
                uploadBtn.type = 'button';
                uploadBtn.textContent = '📁 选择本地图片上传';
                uploadBtn.style.width = '100%';
                uploadBtn.style.padding = '10px';
                uploadBtn.style.border = '1px dashed #c4982e';
                uploadBtn.style.background = '#fdf7ee';
                uploadBtn.style.color = '#8B6914';
                uploadBtn.style.borderRadius = '4px';
                uploadBtn.style.cursor = 'pointer';
                uploadBtn.onclick = function() { fileInput.click(); };
                picker.appendChild(uploadBtn);

                var preview = document.createElement('div');
                preview.style.marginTop = '10px';
                preview.style.display = 'none';
                picker.appendChild(preview);

                // 上传处理
                fileInput.onchange = function() {
                    var file = this.files[0];
                    if (!file) return;

                    var reader = new FileReader();
                    reader.onload = function(e) {
                        preview.innerHTML = '<img src="' + e.target.result + '" style="max-width:100%;max-height:120px;border-radius:4px;border:1px solid #eee;">';
                        preview.style.display = 'block';
                        urlInput.value = e.target.result; // Base64
                    };
                    reader.readAsDataURL(file);
                };

                // 按钮行
                var btnRow = document.createElement('div');
                btnRow.style.display = 'flex';
                btnRow.style.gap = '8px';
                btnRow.style.marginTop = '14px';

                var cancelBtn = document.createElement('button');
                cancelBtn.type = 'button';
                cancelBtn.textContent = '取消';
                cancelBtn.style.flex = '1';
                cancelBtn.style.padding = '8px';
                cancelBtn.style.border = '1px solid #d0c5b5';
                cancelBtn.style.background = '#fff';
                cancelBtn.style.borderRadius = '4px';
                cancelBtn.style.cursor = 'pointer';
                cancelBtn.onclick = function() { picker.remove(); };

                var insertBtn = document.createElement('button');
                insertBtn.type = 'button';
                insertBtn.textContent = '插入图片';
                insertBtn.style.flex = '1';
                insertBtn.style.padding = '8px';
                insertBtn.style.border = '1px solid #b8860b';
                insertBtn.style.background = '#b8860b';
                insertBtn.style.color = '#fff';
                insertBtn.style.borderRadius = '4px';
                insertBtn.style.cursor = 'pointer';
                insertBtn.onclick = function() {
                    var src = urlInput.value.trim();
                    if (src) {
                        document.execCommand('insertImage', false, src);
                        picker.remove();
                        editor.focus();
                    }
                };

                btnRow.appendChild(cancelBtn);
                btnRow.appendChild(insertBtn);
                picker.appendChild(btnRow);

                document.body.appendChild(picker);

                var rect = triggerBtn.getBoundingClientRect();
                picker.style.left = rect.left + 'px';
                picker.style.top = (rect.bottom + 5) + 'px';
            }

            // 组装
            container.appendChild(toolbar);
            container.appendChild(editor);

            // 隐藏原 textarea，替换为编辑器
            textarea.style.display = 'none';
            textarea.parentNode.insertBefore(container, textarea.nextSibling);

            // 表单提交前同步
            textarea.form && textarea.form.addEventListener('submit', function() {
                textarea.value = editor.innerHTML;
            });

            return {
                getContent: function() { return editor.innerHTML; },
                setContent: function(html) { editor.innerHTML = html; textarea.value = html; }
            };
        }
    };
})();
