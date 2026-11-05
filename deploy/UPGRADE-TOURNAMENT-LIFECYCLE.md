# 赛事复制和删除

网站总管理员可在「赛事控制台 → 全部赛事」操作。

- 复制时填写新名称、唯一 Slug 和新管理密码。副本包含赛制、队伍、分组、赛程、赛果、地图、排名和全部赛事及比赛附件。附件可见范围随文件复制。副本默认停用，由总管理员在「状态」中启用。
- 删除时先点「删除」，再输入 `确认删除` 并提交。操作会永久删除该赛事及其队伍、赛程、赛果、管理凭据和附件。全局审计日志保留。

## 升级已有站点

先备份数据库，再更新项目根目录中的代码。进入根目录，用数据库维护账号运行升级脚本。若服务器同时安装了多个 PHP 版本，将 `php` 换成实际的 PHP 8.4 CLI 路径。

```bash
read -rsp 'MySQL maintenance password: ' TOURNAMENT_DB_MAINTENANCE_PASSWORD
printf '\n'
printf '%s' "$TOURNAMENT_DB_MAINTENANCE_PASSWORD" | php bin/upgrade-tournament-lifecycle.php root
unset TOURNAMENT_DB_MAINTENANCE_PASSWORD
```

最后的 `root` 可替换为实际维护账号。脚本可重复执行，兼容补齐地图、判负与附件所需的前序结构。它会解除审计历史对当前赛事记录的外键依赖，保留原有日志、赛事 ID、哈希链和禁止改写日志的触发器。

看到 `Tournament copy and deletion are ready. Audit history was preserved.` 后，重载 PHP-FPM 并刷新后台。全新安装使用当前版本的 `database/database.sql`，可直接使用这两个功能。
