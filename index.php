<?php
/**
 * 首页
 */
require_once __DIR__ . '/includes/header_pre.php';

$db = Database::getInstance();
$isLoggedIn = Auth::isLoggedIn();
$isAdmin = Auth::isAdmin();

// 首页设置
$heroTitle    = get_site_setting('home_hero_title', '族谱传承 · 家谱管理系统');
$heroSubtitle = get_site_setting('home_hero_subtitle', '凡国必有史，有家必有谱。数字化传承家族记忆，让每一份血脉相连永不遗忘。');
$heroBg       = get_site_setting('home_hero_bg', '');
$heroOverlay  = get_site_setting('home_hero_bg_overlay', 'rgba(20,15,10,0.55)');
$showBanner   = get_site_setting('home_show_banner', '1') === '1';
$showArticles = get_site_setting('home_show_articles', '1') === '1';
$showStats    = get_site_setting('home_show_stats', '1') === '1';
$showFeatures = get_site_setting('home_show_features', '1') === '1';
$showSearch   = get_site_setting('home_show_search', '1') === '1';
$articlesCount= (int)get_site_setting('home_articles_count', '6');
$featuresTitle= get_site_setting('home_features_title', '核心功能');
$articlesTitle= get_site_setting('home_articles_title', '家族资讯');
$statsTitle   = get_site_setting('home_stats_title', '平台数据');
$primaryColor = get_site_setting('site_primary_color', '#2d1f10');
$accentColor  = get_site_setting('site_accent_color', '#c4982e');

// 网站介绍 + 核心价值观
$showAbout    = get_site_setting('home_show_about', '1') === '1';
$showValues   = get_site_setting('home_show_values', '1') === '1';
$aboutTitle   = get_site_setting('home_about_title', '关于族谱传承');
// 内置默认介绍内容（当DB为空时使用）
$aboutContent = get_site_setting('home_about_content', '');
if (empty($aboutContent)) {
    $aboutContent = '<p style="font-size:15px;line-height:1.8;color:#555;">族谱传承是一个专注于家族文化数字化的在线平台。我们致力于帮助每一个家庭建立、管理和传承属于自己的家谱，让珍贵的家族记忆以数字化形式永久保存。</p>
<p style="font-size:15px;line-height:1.8;color:#555;margin-top:10px;">无论您的家族人口众多还是刚刚起步，我们都提供简单易用的工具，让您轻松记录先人信息、梳理世系关系、编目成书导出分享。传承家风，从这里开始。</p>';
}
// 内置默认价值观卡片（基于社会主义核心价值观，当DB为空时使用）
$valueDefaults = [
    1 => ['icon' => '🇨🇳', 'title' => '富强', 'desc' => '国家繁荣昌盛，人民幸福安康，家族兴旺发达'],
    2 => ['icon' => '🏛️', 'title' => '民主', 'desc' => '人人平等参与，尊重每个家庭成员的意见与选择'],
    3 => ['icon' => '📚', 'title' => '文明', 'desc' => '传承优良家风，弘扬中华优秀传统文化与美德'],
    4 => ['icon' => '🕊️', 'title' => '和谐', 'desc' => '家庭和睦邻里互助，建立温暖有爱的家族纽带'],
    5 => ['icon' => '❤️', 'title' => '爱国', 'desc' => '热爱祖国，热爱人民，维护国家统一和民族团结'],
    6 => ['icon' => '💼', 'title' => '敬业', 'desc' => '爱岗敬业，尽职尽责，精益求精追求卓越'],
    7 => ['icon' => '🤝', 'title' => '诚信', 'desc' => '诚实守信，言行一致，重信守约说到做到'],
    8 => ['icon' => '😊', 'title' => '友善', 'desc' => '与人为善，助人为乐，和睦邻里互敬互爱'],
];
for ($i = 1; $i <= 8; $i++) {
    $values["v{$i}_icon"]  = get_site_setting("home_value{$i}_icon", $valueDefaults[$i]['icon']);
    $values["v{$i}_title"] = get_site_setting("home_value{$i}_title", $valueDefaults[$i]['title']);
    $values["v{$i}_desc"]  = get_site_setting("home_value{$i}_desc", $valueDefaults[$i]['desc']);
}

// 轮播图
$slides = $db->fetchAll("SELECT * FROM slides WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");

// 文章列表
$articles = $db->fetchAll(
    "SELECT id, title, slug, excerpt, category, cover_image, view_count, created_at
     FROM articles WHERE is_published = 1
     ORDER BY is_pinned DESC, created_at DESC LIMIT $articlesCount"
);

// 统计数据
$statFamilies = $db->fetch("SELECT COUNT(*) AS cnt FROM families WHERE status = 1")['cnt'] ?? 0;
$statMembers  = $db->fetch("SELECT COUNT(*) AS cnt FROM members WHERE status = 1")['cnt'] ?? 0;
$statUsers    = $db->count("SELECT COUNT(*) FROM users") ?? 0;

// 精选家谱
$featuredFamilies = $db->fetchAll(
    "SELECT id, surname, title, cover_image, description FROM families
     WHERE status = 1 AND is_public = 1
     ORDER BY created_at DESC LIMIT 8"
);

// 热门姓氏标签
$hotSurnames = ['王', '李', '张', '刘', '陈', '杨', '黄', '赵', '周', '吴', '徐', '孙'];

$pageTitle = '首页';
$ogTitle = $heroTitle;
$ogDescription = $heroSubtitle;
$extraCss = 'home.css';
require_once __DIR__ . '/includes/header.php';
?>

<!-- 首页颜色变量 -->
<style>
:root {
    --primary: <?php echo e($primaryColor); ?>;
    --accent: <?php echo e($accentColor); ?>;
}
</style>

<!-- ========== Hero 区域 ========== -->
<section class="home-hero" id="homeHero"
         style="--hero-overlay:<?php echo $heroOverlay; ?>;
                <?php if ($heroBg): ?>background-image:url('<?php echo SITE_URL . '/' . $heroBg; ?>');<?php endif; ?>">
    <div class="home-hero-bg"></div>

    <?php if ($showBanner && !empty($slides)): ?>
    <!-- 轮播 -->
    <div class="hero-slider" id="heroSlider">
        <?php foreach ($slides as $i => $slide): ?>
        <div class="slide-item <?php echo $i === 0 ? 'active' : ''; ?>"
             <?php if ($slide['image']): ?>style="background-image:url('<?php echo SITE_URL . '/' . $slide['image']; ?>');"<?php endif; ?>>
            <div class="slide-content">
                <?php if ($slide['title']): ?><h2><?php echo e($slide['title']); ?></h2><?php endif; ?>
                <?php if ($slide['subtitle']): ?><p><?php echo e($slide['subtitle']); ?></p><?php endif; ?>
                <?php if ($slide['link_url']): ?>
                    <a href="<?php echo e($slide['link_url']); ?>" class="btn btn-accent"><?php echo e($slide['link_text'] ?: '了解更多'); ?> →</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <!-- 轮播指示器 -->
        <?php if (count($slides) > 1): ?>
        <div class="slide-dots">
            <?php for ($i = 0; $i < count($slides); $i++): ?>
            <span class="dot <?php echo $i === 0 ? 'active' : ''; ?>" data-index="<?php echo $i; ?>"></span>
            <?php endfor; ?>
        </div>
        <button class="slide-arrow slide-prev" onclick="slideMove(-1)">‹</button>
        <button class="slide-arrow slide-next" onclick="slideMove(1)">›</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="container">
        <div class="hero-content">
            <h1 class="hero-title animate-fadeUp"><?php echo e($heroTitle); ?></h1>
            <p class="hero-subtitle animate-fadeUp"><?php echo e($heroSubtitle); ?></p>

            <?php if ($showSearch): ?>
            <div class="hero-search animate-fadeUp">
                <form action="<?php echo SITE_URL; ?>/family.php" method="get" class="search-form-hero">
                    <input type="text" name="q" placeholder="输入姓氏或谱名搜索家谱..."
                           class="search-input-hero">
                    <button type="submit" class="search-btn-hero">🔍 搜索</button>
                </form>
                <div class="hero-tags">
                    <?php foreach ($hotSurnames as $h): ?>
                        <a href="<?php echo SITE_URL; ?>/family.php?q=<?php echo urlencode($h); ?>"
                           class="hero-tag"><?php echo $h; ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ========== 统计条 ========== -->
<?php if ($showStats): ?>
<section class="home-stats">
    <div class="container">
        <h2 class="section-title"><?php echo e($statsTitle); ?></h2>
        <div class="stats-row">
            <div class="stat-item">
                <span class="stat-num" data-count="<?php echo $statFamilies; ?>">0</span>
                <span class="stat-label">家谱数量</span>
            </div>
            <div class="stat-item">
                <span class="stat-num" data-count="<?php echo $statMembers; ?>">0</span>
                <span class="stat-label">先人记录</span>
            </div>
            <div class="stat-item">
                <span class="stat-num" data-count="<?php echo $statUsers; ?>">0</span>
                <span class="stat-label">注册用户</span>
            </div>
            <div class="stat-item">
                <span class="stat-num" data-count="<?php echo count($hotSurnames); ?>">0</span>
                <span class="stat-label">姓氏覆盖</span>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ========== 功能卡片 ========== -->
<?php if ($showFeatures): ?>
<section class="home-features">
    <div class="container">
        <h2 class="section-title"><?php echo e($featuresTitle); ?></h2>
        <div class="features-grid">
            <a href="<?php echo SITE_URL; ?>/family.php" class="feature-card">
                <div class="feature-icon-wrap">
                    <svg viewBox="0 0 48 48" width="40" height="40"><circle cx="24" cy="10" r="6" fill="none" stroke="currentColor" stroke-width="2"/><line x1="24" y1="16" x2="24" y2="28" stroke="currentColor" stroke-width="2"/><line x1="24" y1="20" x2="14" y2="38" stroke="currentColor" stroke-width="2"/><line x1="24" y1="20" x2="34" y2="38" stroke="currentColor" stroke-width="2"/></svg>
                </div>
                <h3>家谱馆</h3>
                <p>浏览海量数字化家谱，发现家族渊源</p>
            </a>
            <a href="<?php echo SITE_URL; ?>/family_create.php" class="feature-card">
                <div class="feature-icon-wrap">
                    <svg viewBox="0 0 48 48" width="40" height="40"><rect x="8" y="6" width="32" height="36" rx="3" fill="none" stroke="currentColor" stroke-width="2"/><line x1="16" y1="16" x2="32" y2="16" stroke="currentColor" stroke-width="2"/><line x1="16" y1="22" x2="32" y2="22" stroke="currentColor" stroke-width="2"/><line x1="16" y1="28" x2="24" y2="28" stroke="currentColor" stroke-width="2"/></svg>
                </div>
                <h3>创建家谱</h3>
                <p>一键创建您的家族谱系，数字修谱</p>
            </a>
            <a href="<?php echo SITE_URL; ?>/article.php" class="feature-card">
                <div class="feature-icon-wrap">
                    <svg viewBox="0 0 48 48" width="40" height="40"><rect x="8" y="6" width="22" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><rect x="8" y="24" width="32" height="18" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><line x1="8" y1="30" x2="40" y2="30" stroke="currentColor" stroke-width="1"/></svg>
                </div>
                <h3>家族资讯</h3>
                <p>姓氏文化、家谱知识、历史故事</p>
            </a>
            <a href="<?php echo SITE_URL; ?>/export.php" class="feature-card">
                <div class="feature-icon-wrap">
                    <svg viewBox="0 0 48 48" width="40" height="40"><rect x="6" y="4" width="36" height="40" rx="3" fill="none" stroke="currentColor" stroke-width="2"/><line x1="16" y1="14" x2="32" y2="14" stroke="currentColor" stroke-width="2"/><line x1="16" y1="20" x2="32" y2="20" stroke="currentColor" stroke-width="2"/><line x1="16" y1="26" x2="24" y2="26" stroke="currentColor" stroke-width="2"/><polyline points="12,34 18,38 26,30" stroke="currentColor" stroke-width="2" fill="none"/></svg>
                </div>
                <h3>编目成书</h3>
                <p>自动排版，导出电子表格与文档</p>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ========== 核心价值观卡片 ========== -->
<?php if ($showValues): ?>
<section class="home-values">
    <div class="container">
        <h2 class="section-title">核心价值观</h2>
        <div class="values-grid">
            <?php for ($i = 1; $i <= 8; $i++): ?>
            <?php $vi = $values["v{$i}_icon"]; $vt = $values["v{$i}_title"]; $vd = $values["v{$i}_desc"]; ?>
            <?php if ($vt || $vd): ?>
            <div class="value-card">
                <?php if ($vi): ?>
                <div class="value-icon"><?php echo e($vi); ?></div>
                <?php endif; ?>
                <h3 class="value-title"><?php echo e($vt); ?></h3>
                <p class="value-desc"><?php echo e($vd); ?></p>
            </div>
            <?php endif; ?>
            <?php endfor; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ========== 文章展示区 ========== -->
<?php if ($showArticles && !empty($articles)): ?>
<section class="home-articles">
    <div class="container">
        <div class="section-header-flex">
            <h2 class="section-title"><?php echo e($articlesTitle); ?></h2>
            <a href="<?php echo SITE_URL; ?>/article.php" class="section-more">查看全部 →</a>
        </div>
        <div class="articles-grid">
            <?php foreach ($articles as $a): ?>
            <a href="<?php echo SITE_URL; ?>/article.php?slug=<?php echo urlencode($a['slug']); ?>"
               class="article-item">
                <?php if ($a['cover_image']): ?>
                <div class="article-thumb">
                    <img src="<?php echo SITE_URL . '/' . $a['cover_image']; ?>" alt="<?php echo e($a['title']); ?>" loading="lazy">
                </div>
                <?php endif; ?>
                <div class="article-info">
                    <?php if ($a['category']): ?>
                        <span class="article-cat"><?php echo e($a['category']); ?></span>
                    <?php endif; ?>
                    <h3 class="article-title"><?php echo e($a['title']); ?></h3>
                    <p class="article-desc"><?php echo e($a['excerpt'] ?: mb_substr(strip_tags($a['excerpt']), 0, 100, 'UTF-8') . '...'); ?></p>
                    <span class="article-date"><?php echo date('m-d', strtotime($a['created_at'])); ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ========== 网站介绍区 ========== -->
<?php if ($showAbout && $aboutContent): ?>
<section class="home-about">
    <div class="container">
        <h2 class="section-title"><?php echo e($aboutTitle); ?></h2>
        <div class="about-content">
            <?php echo $aboutContent; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ========== 精选家谱 ========== -->
<?php if (!empty($featuredFamilies)): ?>
<section class="home-families">
    <div class="container">
        <h2 class="section-title">精选家谱</h2>
        <div class="families-scroll">
            <?php foreach ($featuredFamilies as $f): ?>
            <a href="<?php echo SITE_URL; ?>/family_view.php?id=<?php echo $f['id']; ?>" class="family-mini-card">
                <div class="family-mini-cover">
                    <?php if ($f['cover_image']): ?>
                        <img src="<?php echo SITE_URL . '/' . $f['cover_image']; ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <span class="family-mini-surname"><?php echo e(mb_substr($f['surname'], 0, 1)); ?></span>
                    <?php endif; ?>
                </div>
                <div class="family-mini-info">
                    <strong><?php echo e($f['title']); ?></strong>
                    <span><?php echo e($f['surname']); ?>氏</span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<!-- 首页专属脚本 -->
<script>
// 轮播
var currentSlide = 0;
var totalSlides = <?php echo count($slides); ?>;

function slideMove(dir) {
    var items = document.querySelectorAll('.slide-item');
    var dots = document.querySelectorAll('.dot');
    if (!items.length) return;
    items[currentSlide].classList.remove('active');
    dots[currentSlide] && dots[currentSlide].classList.remove('active');
    currentSlide = (currentSlide + dir + totalSlides) % totalSlides;
    items[currentSlide].classList.add('active');
    dots[currentSlide] && dots[currentSlide].classList.add('active');
}

// 自动轮播
if (totalSlides > 1) {
    setInterval(function() { slideMove(1); }, 5000);
}

// 数字滚动动画
function animateNumbers() {
    document.querySelectorAll('.stat-num[data-count]').forEach(function(el) {
        var target = parseInt(el.getAttribute('data-count') || 0);
        var current = 0;
        var step = Math.max(1, Math.ceil(target / 60));
        var timer = setInterval(function() {
            current += step;
            if (current >= target) { current = target; clearInterval(timer); }
            el.textContent = current > 1000 ? Math.floor(current) : current;
        }, 30);
    });
}

// 交叉观察器触发动画
if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                if (entry.target.closest('.home-stats')) animateNumbers();
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15 });

    document.querySelectorAll('.animate-fadeUp, .feature-card, .article-item, .family-mini-card, .stat-item').forEach(function(el) {
        el.style.opacity = '0';
        el.style.transform = 'translateY(30px)';
        el.style.transition = 'all 0.6s ease';
        observer.observe(el);
    });
} else {
    animateNumbers();
}
</script>
