<?php
/**
 * header_pre.php - 在header.php之前加载的核心文件
 * 避免在每个页面重复require
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Flash.php';
require_once __DIR__ . '/Security.php';
