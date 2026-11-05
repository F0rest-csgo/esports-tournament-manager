<?php require __DIR__.'/head.php'; ?>
<body><header class="topbar"><a class="brand" href="/"><span class="brand-mark">T</span> TOURNAMENT</a></header><main class="error-page"><div class="eyebrow">TOURNAMENT / <?= e($code) ?></div><h1><?= e($code===404?'暂时找不到这个赛事':'暂时无法访问') ?></h1><p><?= e($message) ?></p><a class="button" href="/">返回首页</a></main></body></html>
