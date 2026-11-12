/**
 * 地址三级联动选择器
 * 依赖: address-data.js
 * 使用方法:
 *   new AddressSelector('origin_province', 'origin_city', 'origin_district', {
 *       originValue: '山西省太原市迎泽区', // 编辑时回填（可选）
 *       onChange: function(fullText) {}      // 变更回调（可选）
 *   });
 */
var AddressSelector = (function() {

    /**
     * 创建选择器实例
     * @param {string} provinceSelectId - 省份select元素ID
     * @param {string} citySelectId     - 城市select元素ID
     * @param {string} districtSelectId - 区县select元素ID
     * @param {object} options          - 配置项
     */
    function AddressSelector(provinceSelectId, citySelectId, districtSelectId, options) {
        options = options || {};

        this.$province = document.getElementById(provinceSelectId);
        this.$city = document.getElementById(citySelectId);
        this.$district = document.getElementById(districtSelectId);
        this.onChange = options.onChange || null;
        this.originHiddenInput = options.hiddenInput || null; // 隐藏的input，存储完整地址文本

        if (!this.$province || !this.$city || !this.$district) return;

        this._init(options.originValue);
    }

    /**
     * 初始化选择器
     */
    AddressSelector.prototype._init = function(originStr) {
        var self = this;

        // 填充省份
        self._fillProvinces();

        // 绑定事件
        self.$province.addEventListener('change', function() {
            self._onProvinceChange();
            self._notify();
        });
        self.$city.addEventListener('change', function() {
            self._onCityChange();
            self._notify();
        });
        self.$district.addEventListener('change', function() {
            self._notify();
        });

        // 如果有初始值则回填
        if (originStr && originStr.trim()) {
            setTimeout(function() {
                self._parseAndFill(originStr);
            }, 50);
        }
    };

    /**
     * 填充省份下拉框
     */
    AddressSelector.prototype._fillProvinces = function() {
        var html = '<option value="">-- 请选择省份 --</option>';
        var list = AddressData.getProvinces();
        for (var i = 0; i < list.length; i++) {
            html += '<option value="' + list[i].code + '">' + list[i].name + '</option>';
        }
        this.$province.innerHTML = html;
    };

    /**
     * 省份变更 → 加载城市列表
     */
    AddressSelector.prototype._onProvinceChange = function() {
        var pCode = this.$province.value;
        this.$city.innerHTML = '<option value="">-- 请选择城市 --</option>';
        this.$district.innerHTML = '<option value="">-- 请选择区县 --</option>';

        if (!pCode) return;

        var cityList = AddressData.getCities(pCode);
        var html = '<option value="">-- 请选择城市 --</option>';
        for (var i = 0; i < cityList.length; i++) {
            html += '<option value="' + cityList[i].code + '">' + cityList[i].name + '</option>';
        }
        this.$city.innerHTML = html;
        this.$city.disabled = false;
    };

    /**
     * 城市变更 → 加载区县列表
     */
    AddressSelector.prototype._onCityChange = function() {
        var cCode = this.$city.value;
        this.$district.innerHTML = '<option value="">-- 请选择区县 --</option>';

        if (!cCode) return;

        var distList = AddressData.getDistricts(cCode);
        var html = '';
        if (distList && distList.length > 0) {
            html = '<option value="">-- 请选择区县 --</option>';
            for (var i = 0; i < distList.length; i++) {
                html += '<option value="' + distList[i] + '">' + distList[i] + '</option>';
            }
        } else {
            html = '<option value="">(无下级区县)</option>';
        }
        this.$district.innerHTML = html;
        this.$district.disabled = false;
    };

    /**
     * 解析已有地址并回填
     */
    AddressSelector.prototype._parseAndFill = function(originStr) {
        var result = AddressData.parseOrigin(originStr);
        if (!result) return;

        // 设置省份
        this.$province.value = result.province;
        this._onProvinceChange();

        // 设置城市
        if (result.city) {
            this.$city.value = result.city;
            this._onCityChange();
        }

        // 设置区县
        if (result.district && this.$district.querySelector('option[value="' + result.district + '"]')) {
            this.$district.value = result.district;
        } else if (result.district) {
            // 如果精确匹配不到，尝试设置第一个包含的
            for (var i = 0; i < this.$district.options.length; i++) {
                if (this.$district.options[i].value === result.district) {
                    this.$district.selectedIndex = i;
                    break;
                }
            }
        }
    };

    /**
     * 获取当前选择的完整地址文本
     * @returns {string} 如"山西省太原市迎泽区"
     */
    AddressSelector.prototype.getValue = function() {
        var pName = this.$province.options[this.$province.selectedIndex];
        var cName = this.$city.options[this.$city.selectedIndex];
        var dName = this.$district.options[this.$district.selectedIndex];

        var parts = [];
        if (pName && pName.value) parts.push(pName.text);
        if (cName && cName.value) parts.push(cName.text);
        if (dName && dName.value && dName.value !== '(无下级区县)') parts.push(dName.text);

        return parts.join('');
    };

    /**
     * 触发变更回调 & 更新隐藏字段
     */
    AddressSelector.prototype._notify = function() {
        var val = this.getValue();

        // 更新隐藏的input
        if (this.originHiddenInput) {
            var hiddenEl = document.getElementById(this.originHiddenInput);
            if (hiddenEl) hiddenEl.value = val;
        }

        if (typeof this.onChange === 'function') {
            this.onChange(val);
        }
    };

    return AddressSelector;
})();
