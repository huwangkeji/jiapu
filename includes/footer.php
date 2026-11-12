<?php
/**
 * 统一公共底部模板 — 仅前台显示
 */
$ftCopyright = get_site_setting('footer_copyright', '族谱传承 家谱管理系统');
$ftIcp     = get_site_setting('footer_icp', '') ?: get_site_setting('site_icp', '');
$ftPolice  = get_site_setting('footer_police', '');
$ftNotice  = get_site_setting('footer_notice', '');
$primaryColor = get_site_setting('site_primary_color', '#2d1f10');

// 二维码
$qrWechat  = get_site_setting('footer_qr_wechat', '');
$qrWeLabel = get_site_setting('footer_qr_wechat_label', '关注公众号');

// 判断是否后台页面
$isAdminPage = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false;
?>
    </main>

<?php if (!$isAdminPage): ?>
    <footer class="site-footer-new" style="--ft-bg:<?php echo e($primaryColor); ?>;">
        <div class="container">
            <div class="footer-center">
                <?php if ($qrWechat): ?>
                <div class="footer-qr-row">
                    <img src="<?php echo SITE_URL . '/' . $qrWechat; ?>" alt="" loading="lazy">
                    <span><?php echo e($qrWeLabel); ?></span>
                </div>
                <?php endif; ?>

                <?php
                $links = Database::getInstance()->fetchAll("SELECT * FROM links WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 10");
                if (!empty($links)):
                ?>
                <div class="footer-nav-row footer-links-row">
                    <?php foreach ($links as $l): ?>
                        <a href="<?php echo e($l['url']); ?>" target="_blank" rel="noopener"
                           title="<?php echo e($l['description']); ?>"><?php echo e($l['name']); ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="footer-bar-row">
                    <span>&copy; <?php echo date('Y'); ?> <?php echo e($ftCopyright); ?> 版权所有</span>
                    <?php if ($ftIcp): ?>
                        <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener"><?php echo e($ftIcp); ?></a>
                    <?php endif; ?>
                    <?php if ($ftPolice): ?>
                        <span><?php echo e($ftPolice); ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($ftNotice): ?>
                <p class="footer-notice"><?php echo e($ftNotice); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </footer>
<?php endif; ?>

    <script src="<?php echo SITE_URL; ?>/assets/js/main.js"></script>
    <script src="<?php echo SITE_URL; ?>/assets/js/address-data.js"></script>
    <script src="<?php echo SITE_URL; ?>/assets/js/address-selector.js"></script>
    <script src="<?php echo SITE_URL; ?>/assets/js/datepicker.js"></script>
    <?php if (isset($extraJs)): ?>
        <script src="<?php echo SITE_URL; ?>/assets/js/<?php echo $extraJs; ?>"></script>
    <?php endif; ?>

    <?php
    // UCenter 同步登录脚本（登录后由 Auth 写入，输出一次即清除）
    if (!empty($_SESSION['uc_synlogin_script'])) {
        echo $_SESSION['uc_synlogin_script'];
        unset($_SESSION['uc_synlogin_script']);
    }
    ?>
</body>
</html>
