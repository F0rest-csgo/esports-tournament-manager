# 电竞赛事管理系统

一套可自行部署的 PHP 赛事管理系统，提供独立的赛事后台、公开赛程和淘汰树。支持单败、双败、单循环、双循环及瑞士轮，可将两个阶段串联，并根据第一阶段赛果安排第二阶段的种子和首轮对阵。

- 赛制设置支持阶段 BO、各轮 BO 覆盖、手动分组及首轮对阵。
- CS2 和 VALORANT 支持详细比分、逐局地图和加时规则，也可只记录整场胜者。
- 公开赛程支持 LIVE、逐局比分和比赛说明。管理员可登记判负，系统会计入排名和晋级。
- 赛事可上传 PDF 和 XLSX，逐份设置公开或仅管理员可见。比赛 XLSX 只向该赛事的管理员开放。单份附件上限为 10 MB。
- 总管理员和赛事管理员分别登录。后台操作写入带哈希链的审计日志。

## Docker Compose 安装

在支持 Docker Compose 的服务器上克隆本仓库，并进入仓库目录。容器包含 PHP 8.4、MySQL 8.4 和 Nginx。

```sh
cp .env.example .env
```

编辑 .env，将 DB_PASSWORD 和 MYSQL_ROOT_PASSWORD 分别设为独立的随机密码。可分别执行 openssl rand -hex 24 生成。通过 HTTPS 对外部署时设为 SESSION_SECURE=1。若只用本机 HTTP 试用，将其设为 0。

```sh
docker compose up -d --build
docker compose ps
```

首次启动会在新的 MySQL 数据卷中建表并配置应用账号的权限。待容器启动后，在 Bash 中创建总管理员。密码须为 12 至 72 字节。

```bash
read -rsp 'New administrator password: ' TOURNAMENT_ADMIN_PASSWORD
printf '\n'
printf '%s' "$TOURNAMENT_ADMIN_PASSWORD" | docker compose exec -T php php bin/init-admin.php admin
unset TOURNAMENT_ADMIN_PASSWORD
```

服务默认绑定到部署服务器的 127.0.0.1:8080。部署到互联网时，在服务器上配置 HTTPS 反向代理，将域名的所有路径转发到该地址，并传递原 Host 请求头。下面是 Nginx HTTPS 站点内的示例片段。证书和域名由部署者配置，HTTP 站点重定向到 HTTPS。

```nginx
client_max_body_size 12m;
location / {
    proxy_set_header Host $host;
    proxy_pass http://127.0.0.1:8080;
}
```

打开自己的域名加 /admin，使用刚创建的账号登录。仅本机 HTTP 试用时，打开 http://127.0.0.1:8080/admin。

## 原生 Linux 安装

准备 Nginx、PHP 8.4 CLI 和 PHP-FPM，以及 MySQL 8.4。PHP 需启用 pdo_mysql、mbstring、fileinfo、dom、zip 和 session。安装目录可自选，下面以 /var/www/tournament 为例。网站使用独立域名，Web 根目录指向项目内的 public/。

1. 将仓库克隆到选定目录。在新数据库上，用 MySQL 维护账号导入结构。脚本使用名为 tournament 的数据库。

   ```sh
   cd /var/www/tournament
   mysql -u root -p < database/database.sql
   ```

2. 用维护账号打开 MySQL 客户端，在其中创建应用账号。保存命令生成的密码。示例假设 PHP 通过 TCP 连接同一台服务器上的 MySQL。若 MySQL 单独部署，账号的主机部分应匹配 PHP 服务器的地址。

   ```sql
   CREATE USER 'tournament'@'127.0.0.1' IDENTIFIED BY RANDOM PASSWORD;
   ```

   返回 shell 后，将仓库中的最小权限配置应用到该账号。

   ```sh
   sed "s/'tournament'@'%'/'tournament'@'127.0.0.1'/g" database/runtime-grants.sql | mysql -u root -p
   ```

3. 复制 .env.example 为 .env，设置 DB_HOST、DB_PORT、DB_DATABASE、DB_USERNAME 和 DB_PASSWORD。生产 HTTPS 站点使用 SESSION_SECURE=1。让 PHP-FPM 用户可以读取 .env 及应用源码，并对 public/uploads/ 有写权限。Nginx 需要读取 public/ 下的静态文件和上传的 Logo。

4. 将 [Nginx 配置模板](deploy/nginx.conf) 用作站点配置。替换域名、Web 根目录和 PHP-FPM socket。为该域名配置 TLS 证书，在 HTTPS 虚拟主机中使用模板的路由规则，并将 HTTP 重定向至 HTTPS。PHP-FPM 的 upload_max_filesize 至少为 10M、post_max_size 至少为 12M。Nginx 的 client_max_body_size 至少为 12m，MySQL 的 max_allowed_packet 至少为 16M。保存配置后重载 Nginx 和 PHP-FPM。

5. 在项目根目录运行以下命令创建总管理员，然后访问自己的域名加 /admin 登录。

   ```bash
   read -rsp 'New administrator password: ' TOURNAMENT_ADMIN_PASSWORD
   printf '\n'
   printf '%s' "$TOURNAMENT_ADMIN_PASSWORD" | php bin/init-admin.php admin
   unset TOURNAMENT_ADMIN_PASSWORD
   ```

## 使用和维护

1. 在总后台创建赛事，填写网址 slug，并为该赛事设置独立的管理密码。
2. 添加队伍并设置种子，在「赛制设置」选择赛制，再到「分组与赛程」生成首阶段。
3. 在「比赛管理」安排时间、录入赛果和编辑比赛说明。双阶段赛事完成首阶段后，在「排名与晋级」预览并确认第二阶段。
4. 在「赛事设置 → 赛事附件」上传赛事文件，在「编辑比赛 → 比赛附件」上传单场工作簿。公开附件显示在访客的「赛事附件」页。

备份应包括 MySQL 数据库、public/uploads/ 中的 Logo 和部署使用的 .env。附件内容位于数据库中。使用 Docker 时持久卷分别保存数据库和 Logo。重置阶段或撤回轮次会删除相应比赛及其附件，操作前请保存需要留存的文件。

审计日志可在总后台验证，也可在部署目录执行 php bin/verify-audit.php。Docker 中使用 docker compose exec -T php php bin/verify-audit.php。

游戏计分及加时规则见 [游戏规则](deploy/GAME-RULES.md)。

## 开源许可

本项目采用 GNU 通用公共许可证第 3 版（`GPL-3.0-only`）。完整条款见 [LICENSE](LICENSE)。
